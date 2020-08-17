<?php
require "vendor/autoload.php";

use Amp\Loop;
use Amp\Producer;
use Amp\Promise;
use Amp\ByteStream;
use Amp\Dns;
use Amp\Dns\DnsException;
use Amp\File;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Log\ConsoleFormatter;
use Monolog\Formatter\LineFormatter;
use Amp\Log\StreamHandler;
use Amp\Sync\LocalSemaphore;
use Monolog\Logger;
use Symfony\Component\DomCrawler\Crawler;
use function Amp\call;

Dns\resolver(new Dns\Rfc1035StubResolver(null, new class implements Dns\ConfigLoader {
    public function loadConfig(): Promise
    {
        return Amp\call(function () {
            $hosts = yield (new Dns\HostLoader)->loadHosts();

            return new Dns\Config([
                "8.8.8.8:53",
                "[2001:4860:4860::8888]:53",
            ], $hosts, $timeout = 5000, $attempts = 3);
        });
    }
}));


$logger = (new Logger('amp-parser'))
    ->pushHandler(
        (new StreamHandler(ByteStream\getStdout()))
            ->setFormatter(new ConsoleFormatter)
    );

//Loop::setErrorHandler(fn(\Throwable $t) => $logger->alert($t));
Loop::setErrorHandler(function (\Throwable $t) use ($logger) { 
    $logger->alert($t);
});

Loop::run(function () use ($logger) {
	$logPath = __DIR__ . '/logs/log-' . date('Y-m-d-H-i-s') . '.txt';
    if (!yield File\isDir(dirname($logPath))) {
        yield File\mkdir(dirname($logPath));
    }

    $logger->pushHandler(
        (new StreamHandler(yield File\open($logPath, 'w')))
            ->setFormatter(new LineFormatter)
    );
    $semaphore = new LocalSemaphore(50);
    $client = HttpClientBuilder::buildDefault();
    $file = yield File\open('domains.txt', 'w');

    $producer = new Producer(function ($emit) use ($client, $logger) {
        $from = new DateTime('2006-01-01');
        $to = new DateTime('2007-01-01');

        while ($from < $to) {
            try {
                $url = "https://whoistory.com/{$from->format('/Y/m/d/')}";
                $logger->debug("Request to {$url}");

                $response = yield $client->request(new Request($url, 'GET'));
            } catch (\Throwable $t) {
                $logger->error("Error during request to {$url}: {$t->getMessage()}");
                continue;
            }

            $links = (new Crawler((string) yield $response->getBody()->buffer()))
                ->filter('div.left > a:not(.backlink)');

            $logger->info("{$url} contains " . count($links) . " links");
            foreach ($links as $link) {
                yield $emit($link->textContent);
            }

            $from->modify('+ 1 day');
        }
    });

    $validDomainsCount = 0;
    while (($lock = yield $semaphore->acquire()) && yield $producer->advance()) {
        $domain = $producer->getCurrent();

        $promise = call(function () use ($domain, $client) {
            $request = new Request("http://{$domain}/");
            $request->setTcpConnectTimeout(2400);
            $response = yield $client->request($request);

            return [$domain, $response->getStatus()];
        });

        $promise->onResolve(function ($error, $args) use ($domain, $lock, $file, $logger, &$validDomainsCount) {
            $lock->release();
            if ($error !== null) {
                return $error instanceof DnsException
                    ? $logger->debug("Unable to resolve domain {$domain}")
                    : $logger->error("Error occured during request to {$domain}: {$error->getMessage()}");
            }

            [$domain, $statusCode] = $args;
            if ((int) $statusCode > 499) {
                return $logger->info("{$domain} has returned {$statusCode} code");
            }

            $validDomainsCount++;
            $logger->info("{$domain} has returned {$statusCode} code, Total valid domains: {$validDomainsCount}");
            $file->write("{$domain}\n");
        });
    }
});
