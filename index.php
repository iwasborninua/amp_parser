<?php
require "vendor/autoload.php";

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Loop;
use Symfony\Component\DomCrawler\Crawler;

$from = new DateTime('2006-09-01');
$to = new DateTime('2007-01-01');


Loop::run(function () use (&$from, $to) {
    $client = HttpClientBuilder::buildDefault();
    $handle = \Amp\File\open("domains.txt", "w");

    while ($from < $to) {
        $uri = "https://whoistory.com/" . $from->format('/Y/m/d/');
        $response = yield $client->request(new Request($uri, 'GET'));

        if ($response->getStatus() == 200){
            $crawler = new Crawler((string) yield $response->getBody()->buffer());
            $links = $crawler->filter('div.left > a');
            $links->each(function ($node) use ($handle) {
                if(substr($node->attr('href'), 0, -1) != null && $node->attr('class') != "backlink") {
                    $handle->onResolve(function ($error, $result) use ($node) {
                        if ($error !== null) {
                            exit($error->getMessage());
                        }
                        $write = $result->write($node->text() . "\n");
                    });
                }
            });
            echo $uri . ' : ' . $links->count() . PHP_EOL;
        } else {
            echo $uri . " 404" . PHP_EOL;
        }
        $from->modify("+ 1 day");
    }

    echo PHP_EOL . PHP_EOL . "Done!" . PHP_EOL . PHP_EOL;
});
