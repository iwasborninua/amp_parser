<?php
require "vendor/autoload.php";

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Loop;
use Symfony\Component\DomCrawler\Crawler;

$from = new DateTime('2006-09-01');
$to = new DateTime('2006-10-01');


Loop::run(function () use (&$from, $to) {
    $client = HttpClientBuilder::buildDefault();
    $handle = yield \Amp\File\open("domains.txt", "w");

    while ($from < $to) {
        $uri = "https://whoistory.com/" . $from->format('/Y/m/d/');
        $response = yield $client->request(new Request($uri, 'GET'));

        if ($response->getStatus() == 200){
            $crawler = new Crawler((string) yield $response->getBody()->buffer());
            $links = $crawler->filter('div.left > a');
            foreach ($links as $link) {
                try {
                    $domain = yield $client->request(new Request("http://" . $link->textContent));
                    if ($domain->getStatus() == 200) {
                        $handle->write($link->textContent . "\n");
                        echo $link->textContent . " : 200" . PHP_EOL;
                    }
                } catch (Exception $e) {

                }
            }
            echo $uri . ' : ' . $links->count() . PHP_EOL;
        } else {
            echo $uri . " 404" . PHP_EOL;
        }
        $from->modify("+ 1 day");
    }

    echo PHP_EOL . PHP_EOL . "Done!" . PHP_EOL . PHP_EOL;
});
