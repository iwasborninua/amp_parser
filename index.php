<?php
require "vendor/autoload.php";

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Loop;
use Symfony\Component\DomCrawler\Crawler;

$from = new DateTime('2006-02-01');
$to = new DateTime('2006-12-31');


Loop::run(function () use (&$from, $to) {
    $client = HttpClientBuilder::buildDefault();
    $handle = yield \Amp\File\open($from->format("Y-m-d") . "_" . $to->format("Y-m-d") . ".txt", "w");
    $log = yield \Amp\File\open($from->format("Y-m-d") . "_" . $to->format("Y-m-d") . "_log" . ".txt", "w");

    while ($from < $to) {
        $uri = "https://whoistory.com/" . $from->format('/Y/m/d/');
        $response = yield $client->request(new Request($uri, 'GET'));

        if ($response->getStatus() == 200) {
            $crawler = new Crawler((string) yield $response->getBody()->buffer());
            $links = $crawler->filter('div.left > a');
            $valid = 0;

            if (count($links) > 1) {
                foreach ($links as $link) {
                    try {
                        $request = new Request("http://" . $link->textContent);
                        $request->setTcpConnectTimeout(2400);
                        $domain = yield $client->request($request);
                        if ($domain->getStatus() == 200 || $domain->getStatus() == 302) {
                            $handle->write($link->textContent . "\n");
                            $valid += 1;
                            echo $link->textContent . " : 200" . PHP_EOL;
                        }
                    } catch (Exception $e) {
                        echo $e->getCode() . " : " . $e->getMessage() . PHP_EOL;
                    }
                }
            }
            $log->write($uri . " domains: {$links->count()} , valid: {$valid};" . PHP_EOL);
            echo $uri . ' : ' . $links->count() . ", valid: {$valid};" . PHP_EOL;
            $valid = 0;
        } else {
            echo $uri . " 404" . PHP_EOL;
        }
        $from->modify("+ 1 day");
    }

    echo PHP_EOL . PHP_EOL . "Done!" . PHP_EOL . PHP_EOL;
});
