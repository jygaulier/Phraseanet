<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2016 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Core\Provider;

use Alchemy\Phrasea\Controller\LazyLocator;
use Alchemy\Phrasea\SearchEngine\Elastic\ElasticsearchOptions;
use Alchemy\Phrasea\SearchEngine\Elastic\Search\QueryVisitor;
use Alchemy\Phrasea\SearchEngine\SearchEngineLogger;
use Alchemy\Phrasea\Exception\InvalidArgumentException;
use Alchemy\Phrasea\SearchEngine\SearchEngineInterface;
use Alchemy\Phrasea\SearchEngine\Elastic\ElasticSearchEngine;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer;
use Alchemy\Phrasea\SearchEngine\Elastic\IndexerSubscriber;
use Alchemy\Phrasea\SearchEngine\Elastic\RecordHelper;
use Alchemy\Phrasea\SearchEngine\Elastic\Search\Escaper;
use Alchemy\Phrasea\SearchEngine\Elastic\Search\FacetsResponse;
use Alchemy\Phrasea\SearchEngine\Elastic\Search\QueryContextFactory;
use Alchemy\Phrasea\SearchEngine\Elastic\Search\QueryCompiler;
use Alchemy\Phrasea\SearchEngine\Elastic\Structure\GlobalStructure;
use Alchemy\Phrasea\SearchEngine\Elastic\Thesaurus;
use Elasticsearch\ClientBuilder;
use Hoa\Compiler;
use Hoa\File;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class SearchEngineServiceProvider implements ServiceProviderInterface
{
    public function register(Application $app)
    {
        $app['phraseanet.SE'] = function ($app) {
            return $app['search_engine'];
        };

        $app['phraseanet.SE.logger'] = $app->share(function (Application $app) {
            return new SearchEngineLogger($app);
        });

        $app['search_engine'] = $app->share(function ($app) {
            $type = $app['conf']->get(['main', 'search-engine', 'type']);
            if ($type !== SearchEngineInterface::TYPE_ELASTICSEARCH) {
                    throw new InvalidArgumentException(sprintf('Invalid search engine type "%s".', $type));
            }
            /** @var ElasticsearchOptions $options */
            $options = $app['elasticsearch.options'];

            return new ElasticSearchEngine(
                $app,
                $app['search_engine.structure'],
                $app['elasticsearch.client'],
                $options->getIndexName(),
                $app['query_context.factory'],
                $app['elasticsearch.facets_response.factory'],
                $options
            );
        });

        $app['search_engine.structure'] = $app->share(function (\Alchemy\Phrasea\Application $app) {
            $databoxes = $app->getDataboxes();
            return GlobalStructure::createFromDataboxes($databoxes);
        });

        $app['elasticsearch.facets_response.factory'] = $app->protect(function (array $response) use ($app) {
            return new FacetsResponse(new Escaper(), $response, $app['search_engine.structure']);
        });


        /* Indexer related services */

        $app['elasticsearch.indexer'] = $app->share(function ($app) {
            // TODO Use upcomming monolog factory
            $logger = new Logger('indexer');
            $logger->pushHandler(new ErrorLogHandler());
            return new Indexer(
                $app['elasticsearch.client'],
                $app['elasticsearch.options'],
                $app['phraseanet.appbox'],
                $app['elasticsearch.record_helper'],
                array_keys($app['locales.available']),
                $logger
            );
        });

        $app['elasticsearch.record_helper'] = $app->share(function ($app) {
            return new RecordHelper($app['phraseanet.appbox']);
        });

        $app['dispatcher'] = $app
            ->share($app->extend('dispatcher', function (EventDispatcherInterface $dispatcher, $app) {
                $subscriber = new IndexerSubscriber(new LazyLocator($app, 'elasticsearch.indexer'));

                $dispatcher->addSubscriber($subscriber);

                $listener = array($subscriber, 'flushQueue');

                // Add synchronous flush when used in CLI.
                if (isset($app['console'])) {
                    foreach (array_keys($subscriber->getSubscribedEvents()) as $eventName) {
                        $dispatcher->addListener($eventName, $listener, -10);
                    }

                    return $dispatcher;
                }

                $dispatcher->addListener(KernelEvents::TERMINATE, $listener);

                return $dispatcher;
            }));

        /* Low-level elasticsearch services */

        $app['elasticsearch.client'] = $app->share(function($app) {
            /** @var ElasticsearchOptions $options */
            $options        = $app['elasticsearch.options'];
            $clientParams   = ['hosts' => [sprintf('%s:%s', $options->getHost(), $options->getPort())]];

            // Create file logger for debug
            if ($app['debug']) {
                /** @var Logger $logger */
                $logger = new $app['monolog.logger.class']('search logger');
                $logger->pushHandler(new RotatingFileHandler($app['log.path'].DIRECTORY_SEPARATOR.'elasticsearch.log', 2, Logger::INFO));

                $clientParams['logObject'] = $logger;
                $clientParams['logging'] = true;
            }

            $clientBuilder = ClientBuilder::create()
                ->setHosts($clientParams['hosts']);
            if(array_key_exists('logObject', $clientParams)) {
                $clientBuilder->setLogger($clientParams['logObject']);
            }

            return $clientBuilder->build();
        });

        $app['elasticsearch.options'] = $app->share(function($app) {
            $options = ElasticsearchOptions::fromArray($app['conf']->get(['main', 'search-engine', 'options'], []));

            if (empty($options->getIndexName())) {
                $options->setIndexName(strtolower(sprintf('phraseanet_%s', str_replace(
                    array('/', '.'), array('', ''),
                    $app['conf']->get(['main', 'key'])
                ))));
            }

            return $options;
        });


        /* Querying helper services */
        $app['thesaurus'] = $app->share(function ($app) {
            $logger = new Logger('thesaurus');
            $logger->pushHandler(new ErrorLogHandler(
                ErrorLogHandler::OPERATING_SYSTEM,
                $app['debug'] ? Logger::DEBUG : Logger::ERROR
            ));

            return new Thesaurus(
                $app['elasticsearch.client'],
                $app['elasticsearch.options'],
                $logger
            );
        });

        $app['query_context.factory'] = $app->share(function ($app) {
            return new QueryContextFactory(
                $app['search_engine.structure'],
                array_keys($app['locales.available']),
                $app['locale']
            );
        });

        $app['query_parser.grammar_path'] = function ($app) {
            $configPath = ['registry', 'searchengine', 'query-grammar-path'];
            $grammarPath = $app['conf']->get($configPath, 'grammar/query.pp');
            $projectRoot = '../../../../..';

            return realpath(implode('/', [__DIR__, $projectRoot, $grammarPath]));
        };

        $app['query_parser'] = $app->share(function ($app) {
            $grammarPath = $app['query_parser.grammar_path'];
            return Compiler\Llk\Llk::load(new File\Read($grammarPath));
        });

        $app['query_visitor.factory'] = $app->protect(function () use ($app) {
            return new QueryVisitor($app['search_engine.structure']);
        });

        $app['query_compiler'] = $app->share(function ($app) {
            return new QueryCompiler(
                $app['query_parser'],
                $app['query_visitor.factory'],
                $app['thesaurus']
            );
        });
    }

    public function boot(Application $app)
    {
    }
}
