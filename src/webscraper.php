<?php
/**
 * GRBJ Archive Scrapper by Godwin Ojebiyi
 *
 */

use GuzzleHttp\Client;
use GuzzleHttp\Promise\EachPromise;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class Scrapper
 */
class Scrapper
{
    public $url = 'http://archive-grbj-2.s3-website-us-west-1.amazonaws.com/';
    public $startDate;
    public $endDate;
    public $maxdatasPerAuthor = 40;
    public $concurrency = 2;
    public $wait = 0;
    private $data = [];

    /**
     * Scrapes Data From Web Page
     * @param array $config
     * @return array $data
     */
    public function getData($config = [])
    {
        foreach ($config as $attribute => $value) {
            $this->$attribute = $value;
        }
        $concurrency = function ($pending) {
            if ($pending == 0) {
                sleep($this->wait);
                return $this->concurrency;
            } else {
                return 0;
            }
        };
        $client = new Client([
            'base_uri' => $this->url,
            'timeout' => 0,
        ]);

        # Request / or root
        $uri = $client->request('GET', '/authors.html');
        $crawler = new Crawler((string)$uri->getBody());

        //var_dump($crawler);
        
        // Scrapping Featured Authors Data since they are not included in the author listing under the featured category
        $filter = $crawler->filter('.featured .record .author-bio .author-info');
        foreach ($filter as $content) {
            $crawlerAuthor = new Crawler($content);
            $name = $crawlerAuthor->filter('.headline > a')->text();
            $this->data[$name] = [
                'authorName' => $name,
                'authorTwitterHandle' => ($crawlerAuthor->filter('.abstract > a[href*="https://twitter.com/"]')->count()) ? $crawlerAuthor->filter('.abstract > a[href*="https://twitter.com/"]')->attr('href') : null,
                'authorBio' => trim($crawlerAuthor->filter('.abstract')->text()),
                'authorUrl' => $crawlerAuthor->filter('.headline > a')->attr('href'),
                'articles' => [],
            ];
            break;
        }

        //Scrapping Data for other Authors
        $filter = $crawler->filter('.authors .record > a[href*="authors"]');
        foreach ($filter as $content) {
            $crawlerAuthor = new Crawler($content);
            $name = $crawlerAuthor->text();
            $this->data[$name] = [
                'authorName' => $name,
                'authorTwitterHandle' => null,
                'authorBio' => null,
                'authorUrl' => $crawlerAuthor->attr('href'),
                'articles' => [],
            ];
        }
        $authors = $this->data;

        $promises = call_user_func(function () use ($authors, $client) {
            foreach ($authors as $author) {
                yield $client->requestAsync('GET', $author['authorUrl']);
            }
        });
        $eachPromise = new EachPromise($promises, [
            'concurrency' => $concurrency,
            'fulfilled' => function (ResponseInterface $response) {
                $crawlerAuthor = new Crawler((string)$response->getBody());
                if ($crawlerAuthor->filter('.author-bio')->count()) {
                    $name = $crawlerAuthor->filter('.author-bio .headline > a')->text();
                    $this->data[$name] = [
                        'authorName' => $name,
                        'authorTwitterHandle' => ($crawlerAuthor->filter('.author-bio .abstract > a[href*="https://twitter.com/"]')->count()) ? $crawlerAuthor->filter('.abstract > a[href*="https://twitter.com/"]')->attr('href') : null,
                        'authorBio' => trim($crawlerAuthor->filter('.author-bio .abstract')->text()),
                        'authorUrl' => $this->url . $crawlerAuthor->filter('.author-bio .headline > a')->attr('href'),
                        'articles' => [],
                    ];
                } else {
                    $name = $crawlerAuthor->filter('#breadcrumbs a:nth-child(3)')->text();
                }

                //Scrapping Author Articles
                $articlesUrl = $crawlerAuthor->filter('.author-bio .articles > a')->attr('href');
                if ($crawlerAuthor->filter('.records')->count()) {
                    $filter = $crawlerAuthor->filter('.records .record > .info');
                    foreach ($filter as $content) {
                        $currentArticlesCount = count($this->data[$name]['articles']);
                        if ($this->maxdatasPerAuthor && ($this->maxdatasPerAuthor <= $currentArticlesCount)) {
                            break;
                        }
                        $crawlerArticle = new Crawler($content);
                        $date = trim($crawlerArticle->filter('.meta .date')->text());
                        if ($this->compareDateWithRange($date)) {
                            $date = date('Y-m-d', strtotime($date));
                        } else {
                            continue;
                        }
                        $this->data[$name]['articles'][] = [
                            'articleTitle' => trim($crawlerArticle->filter('.headline > a')->text()),
                            'articleDate' => $date,
                            'articleUrl' => trim($this->url, '/') . trim($crawlerArticle->filter('.headline > a')->attr('href'), '.'),
                        ];
                    }
                }
            },
        ]);
        $eachPromise->promise()->wait();
        return $this->data;
    }

    /**
     * Checks to see if article date is in date range
     * @param $date string
     * @return bool
     */
    private function compareDateWithRange($article_date)
    {
        $convertedArticleDate = strtotime($article_date);
        if ($this->startDate) {
            $convertedStartDate = strtotime($this->startDate);
            if ($convertedStartDate > $convertedArticleDate) {
                return false;
            }
        }
        if ($this->endDate) {
            $convertedEndDate = strtotime($this->endDate);
            if ($convertedEndDate < $convertedArticleDate) {
                return false;
            }
        }
        return true;
    }
}
