<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\SearchEngine\Elastic;

use appbox;
use databox;
use Alchemy\Phrasea\Model\RecordInterface;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\RecordIndexer;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\TermIndexer;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\RecordQueuer;
use Elasticsearch\Client;
use Psr\Log\LoggerInterface;
use igorw;
use Psr\Log\NullLogger;
use SplObjectStorage;


class Indexer
{
    const THESAURUS = 1;
    const RECORDS   = 2;
    const WITHOUT_MAPPING = false;
    const WITH_MAPPING = true;

    /** @var \Elasticsearch\Client */
    private $client;
    /** @var ElasticsearchOptions */
    private $options;
    private $appbox;
    /** @var LoggerInterface|null */
    private $logger;

    /** @var  RecordHelper */
    private $recordHelper;

    private $locales;

    // array of "tools" for each databox (queues, indexer, thesaurus, ...)
    private $databoxToolbox;

    public function __construct(Client $client, ElasticsearchOptions $options, appbox $appbox,
                                RecordHelper $helper, array $locales, LoggerInterface $logger=null)
    {
        $this->client   = $client;
        $this->options  = $options;
        $this->appbox   = $appbox;
        $this->logger = $logger ?: new NullLogger();

        $this->recordHelper = $helper;
        $this->locales = $locales;

        $this->databoxToolbox = [];
    }

    public function __destruct()
    {
        $this->flushQueue();
    }

    /**
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    public function createIndexForDatabox(databox $dbox, $withMapping)
    {
        $settings = [
            'number_of_shards'   => $this->options->getShards(),
            'number_of_replicas' => $this->options->getReplicas(),
            'analysis'           => $this->getAnalysis(),
        ];

        $actions = [];
        $mainIndex = $this->options->getIndexName();

        // create the "term" index
        $termIndexer = $this->getTermIndexerForDatabox($dbox);
        $termIndexer->createIndex($settings, $withMapping);

        // add it to "main.t" and "main" aliases
        $this->logger->info(sprintf("Adding index \"%s\" to aliases \"%s.t\" and \"%s\"", $termIndexer->getIndexName(), $mainIndex, $mainIndex));
        $actions[] = [
            'add' => [
                'index' => $termIndexer->getIndexName(),
                'alias' => $mainIndex . '.t',
            ]
        ];
        $actions[] = [
            'add' => [
                'index' => $termIndexer->getIndexName(),
                'alias' => $mainIndex,
            ]
        ];

        // create the "record" index
        $recordIndexer = $this->getRecordIndexerForDatabox($dbox);
        $recordIndexer->createIndex($settings, $withMapping);

        // add it to "main.r" and "main" aliases
        $this->logger->info(sprintf("Adding index \"%s\" to aliases \"%s.r\" and \"%s\"", $recordIndexer->getIndexName(), $mainIndex, $mainIndex));
        $actions[] = [
            'add' => [
                'index' => $recordIndexer->getIndexName(),
                'alias' => $mainIndex . '.r',
            ]
        ];
        $actions[] = [
            'add' => [
                'index' => $recordIndexer->getIndexName(),
                'alias' => $mainIndex,
            ]
        ];


        $params = [
            'body' => [
                'actions' => $actions
            ]
        ];
        $this->client->indices()->updateAliases($params);
    }

    /**
     * @param databox $dbox
     * @return RecordIndexer
     */
    private function getRecordIndexerForDatabox(databox $dbox)
    {
        $toolbox = &$this->getToolboxForDatabox($dbox->get_sbas_id());
        if(!array_key_exists('recordIndexer', $toolbox)) {
            $toolbox['recordIndexer'] = new RecordIndexer(
                $this->client,
                $this->options,
                $dbox,
                $this->recordHelper,
                $this->locales,
                $this->logger
            );
        }

        return $toolbox['recordIndexer'];
    }

    /**
     * @param databox $dbox
     * @return TermIndexer
     */
    private function getTermIndexerForDatabox(databox $dbox)
    {
        $toolbox = &$this->getToolboxForDatabox($dbox->get_sbas_id());
        if(!array_key_exists('termIndexer', $toolbox)) {
            $toolbox['termIndexer'] = new TermIndexer(
                $this->client,
                $dbox,
                $this->locales,
                $this->logger
            );
        }

        return $toolbox['termIndexer'];
    }

    public function updateMappingforDatabox(databox $dbox)
    {
        $params = array();
        $params['index'] = $this->getRecordIndexerForDatabox($dbox);
        $params['type'] = RecordIndexer::TYPE_NAME;
        $params['body'][RecordIndexer::TYPE_NAME] = $this->getRecordIndexerForDatabox($dbox)->getMapping();

        // @todo This must throw a new indexation if a mapping is edited
        $this->client->indices()->putMapping($params);
    }

    /**
     * drop aliases and indexes of a databox
     * nb : dropping a non-existing index does not throw any exception
     *
     * @param databox $dbox
     */
    public function dropIndexForDatabox(databox $dbox)
    {
        $mainIndex = $this->options->getIndexName();

        $termIndexer = $this->getTermIndexerForDatabox($dbox);
        $this->deleteESAlias($termIndexer->getIndexName(), $mainIndex . '.t');
        $this->deleteESAlias($termIndexer->getIndexName(), $mainIndex);

        $recordIndexer = $this->getRecordIndexerForDatabox($dbox);
        $this->deleteESAlias($recordIndexer->getIndexName(), $mainIndex . '.r');
        $this->deleteESAlias($recordIndexer->getIndexName(), $mainIndex);

        $termIndexer->dropIndex();
        $recordIndexer->dropIndex();
    }

    private function deleteESAlias($indexName, $aliasName)
    {
        $this->logger->info(sprintf("Deleting alias \"%s\" for index \"%s\"", $aliasName, $indexName));
        $params = [
            'index' => $indexName,
            'name' => $aliasName,
        ];
        try {
            $this->client->indices()->deleteAlias($params);
        }
        catch(\Exception $e) {
            // no-op
        }
    }

    /**
     * check if index exists (both .r and .t) for a databox
     *
     * @param databox $dbox
     * @return bool
     * @todo : add tests on aliases existence
     */
    public function indexExistForDatabox(databox $dbox)
    {
        return $this->getTermIndexerForDatabox($dbox)->indexExists()
            && $this->getRecordIndexerForDatabox($dbox)->indexExists();
    }


    /**
     * list indexes and aliases for each databox
     *
     * @return array
     */
    public function listIndexes()
    {
        $ret = [];
        $mainIndex = $this->options->getIndexName();
        foreach($this->appbox->get_databoxes() as $dbox) {
            $termIndexer = $this->getTermIndexerForDatabox($dbox);
            $recordIndexer = $this->getRecordIndexerForDatabox($dbox);
            $ret[$dbox->get_sbas_id()] = [
                'dbname' => $dbox->get_dbname(),
                'indexes' => [
                    $recordIndexer->getIndexName() => [
                        'exists' => $recordIndexer->indexExists(),
                        'aliases' => [
                            $mainIndex . '.r' => [
                                'exists' => $this->existsESAlias($mainIndex . '.r', $recordIndexer->getIndexName())
                            ],
                            $mainIndex => [
                                'exists' => $this->existsESAlias($mainIndex, $recordIndexer->getIndexName())
                            ]
                        ]
                    ],
                    $termIndexer->getIndexName() => [
                        'exists' => $termIndexer->indexExists(),
                        'aliases' => [
                            $mainIndex . '.t' => [
                                'exists' => $this->existsESAlias($mainIndex . '.t', $termIndexer->getIndexName()),
                            ],
                            $mainIndex => [
                                'exists' => $this->existsESAlias($mainIndex, $termIndexer->getIndexName())
                            ]
                        ]
                    ],
                ]
            ];
        }

        return $ret;
    }

    private function existsESAlias($name, $index)
    {
        return $this->client->indices()->existsAlias([
            'name' => $name,
            'index' => $index
        ]);
    }

    public function populateIndexForDatabox(databox $dbox,  $what)
    {
        // Record indexing depends on indexed terms so we need to make
        // everything ready to search
        if ($what & self::THESAURUS) {
            $this->getTermIndexerForDatabox($dbox)->populateIndex();
            $this->client->indices()->refresh();
        }

        if ($what & self::RECORDS) {
            $this->getRecordIndexerForDatabox($dbox)->populateIndex();
        }

        // Optimize index
        $params = array('index' => $this->options->getIndexName());
        $this->client->indices()->optimize($params);
    }

    public function migrateMappingForDatabox($databox)
    {
        // TODO Migrate mapping
        // - Create a new index
        // - Dump records using scroll API
        // - Insert them in created index (except those in the changed databox)
        // - Reindex databox's records from DB
        // - Make alias point to new index
        // - Delete old index

        // $this->updateMapping();
        // RecordQueuer::queueRecordsFromDatabox($databox);
    }

    public function scheduleRecordsFromDataboxForIndexing(\databox $databox)
    {
        RecordQueuer::queueRecordsFromDatabox($databox);
    }

    public function scheduleRecordsFromCollectionForIndexing(\collection $collection)
    {
        RecordQueuer::queueRecordsFromCollection($collection);
    }

    private function &getToolboxForDatabox($databox_id)
    {
        if(!array_key_exists($databox_id, $this->databoxToolbox)) {
            $this->databoxToolbox[$databox_id] = [];
        }
        return $this->databoxToolbox[$databox_id];
    }
    /**
     * @param $databox_id
     * @return SplObjectStorage[]
     */
    private function &getQueuesForDatabox($databox_id)
    {
        $toolbox = &$this->getToolboxForDatabox($databox_id);
        if(!array_key_exists('queues', $toolbox)) {
            $toolbox['queues'] = [
                'index' => [],
                'delete' => []
            ];
        }

        return $toolbox['queues'];
    }

    public function indexRecord(RecordInterface $record)
    {
        $q = &$this->getQueuesForDatabox($record->getDataboxId());
        $q['index'][$record->getRecordId()] = true; // key ensure unity, value is useless
    }

    public function deleteRecord(RecordInterface $record)
    {
        $q = &$this->getQueuesForDatabox($record->getDataboxId());
        $q['delete'][$record->getRecordId()] = true; // key ensure unity, value is useless
    }

    /**
     * @param databox[] $databoxes    databoxes to index
     * @throws \Exception
     */
    public function indexScheduledRecords(array $databoxes)
    {
        /** @var databox $databox */
        foreach ($databoxes as $dbox) {
            $this->getRecordIndexerForDatabox($dbox)->indexScheduled();
        }
    }

    public function flushQueue()
    {
        foreach($this->databoxToolbox as $sbas_id => $toolbox) {
            $q = &$this->getQueuesForDatabox($sbas_id);
            // it's useless to index records that are to be deleted, remove em from the index q.
            $q['index'] = array_diff_key($q['index'], $q['delete']);

            // Skip if nothing to do
            if (empty($q['index']) && empty($q['delete'])) {
                continue;
            }
            $dbox = $this->appbox->get_databox($sbas_id);
            $recordIndexer = $this->getRecordIndexerForDatabox($dbox);
            $recordIndexer->index(array_keys($q['index']));
            $recordIndexer->delete(array_keys($q['delete']));

            $q['index'] = $q['delete'] = [];
        }
    }

    /**
     * Editing this configuration must be followed by a full re-indexation
     * @return array
     */
    private function getAnalysis()
    {
        return [
            'analyzer' => [
                // General purpose, without removing stop word or stem: improve meaning accuracy
                'general_light' => [
                    'type'      => 'custom',
                    'tokenizer' => 'icu_tokenizer',
                    // TODO Maybe replace nfkc_normalizer + asciifolding with icu_folding
                    'filter'    => ['nfkc_normalizer', 'asciifolding']
                ],
                // Lang specific
                'fr_full' => [
                    'type'      => 'custom',
                    'tokenizer' => 'icu_tokenizer', // better support for some Asian languages and using custom rules to break Myanmar and Khmer text.
                    'filter'    => ['nfkc_normalizer', 'asciifolding', 'elision', 'stop_fr', 'stem_fr']
                ],
                'en_full' => [
                    'type'      => 'custom',
                    'tokenizer' => 'icu_tokenizer',
                    'filter'    => ['nfkc_normalizer', 'asciifolding', 'stop_en', 'stem_en']
                ],
                'de_full' => [
                    'type'      => 'custom',
                    'tokenizer' => 'icu_tokenizer',
                    'filter'    => ['nfkc_normalizer', 'asciifolding', 'stop_de', 'stem_de']
                ],
                'nl_full' => [
                    'type'      => 'custom',
                    'tokenizer' => 'icu_tokenizer',
                    'filter'    => ['nfkc_normalizer', 'asciifolding', 'stop_nl', 'stem_nl_override', 'stem_nl']
                ],
                'es_full' => [
                    'type'      => 'custom',
                    'tokenizer' => 'icu_tokenizer',
                    'filter'    => ['nfkc_normalizer', 'asciifolding', 'stop_es', 'stem_es']
                ],
                'ar_full' => [
                    'type'      => 'custom',
                    'tokenizer' => 'icu_tokenizer',
                    'filter'    => ['nfkc_normalizer', 'asciifolding', 'stop_ar', 'stem_ar']
                ],
                'ru_full' => [
                    'type'      => 'custom',
                    'tokenizer' => 'icu_tokenizer',
                    'filter'    => ['nfkc_normalizer', 'asciifolding', 'stop_ru', 'stem_ru']
                ],
                'cn_full' => [ // Standard chinese analyzer is not exposed
                    'type'      => 'custom',
                    'tokenizer' => 'icu_tokenizer',
                    'filter'    => ['nfkc_normalizer', 'asciifolding']
                ],
                // Thesaurus specific
                'thesaurus_path' => [
                    'type'      => 'custom',
                    'tokenizer' => 'thesaurus_path'
                ],
                // Thesaurus strict term lookup
                'thesaurus_term_strict' => [
                    'type'      => 'custom',
                    'tokenizer' => 'keyword',
                    'filter'    => 'nfkc_normalizer'
                ]
            ],
            'tokenizer' => [
                'thesaurus_path' => [
                    'type' => 'path_hierarchy'
                ]
            ],
            'filter' => [
                'nfkc_normalizer' => [ // weißkopfseeadler => weisskopfseeadler, ١٢٣٤٥ => 12345.
                    'type' => 'icu_normalizer', // œ => oe, and use the fewest  bytes possible.
                    'name' => 'nfkc_cf' // nfkc_cf do the lowercase job too.
                ],

                'stop_fr' => [
                    'type' => 'stop',
                    'stopwords' => ['l', 'm', 't', 'qu', 'n', 's', 'j', 'd'],
                ],
                'stop_en' => [
                    'type' => 'stop',
                    'stopwords' => '_english_' // Use the Lucene default
                ],
                'stop_de' => [
                    'type' => 'stop',
                    'stopwords' => '_german_' // Use the Lucene default
                ],
                'stop_nl' => [
                    'type' => 'stop',
                    'stopwords' => '_dutch_' // Use the Lucene default
                ],
                'stop_es' => [
                    'type' => 'stop',
                    'stopwords' => '_spanish_' // Use the Lucene default
                ],
                'stop_ar' => [
                    'type' => 'stop',
                    'stopwords' => '_arabic_' // Use the Lucene default
                ],
                'stop_ru' => [
                    'type' => 'stop',
                    'stopwords' => '_russian_' // Use the Lucene default
                ],

                // See http://www.elasticsearch.org/guide/en/elasticsearch/reference/current/analysis-stemmer-tokenfilter.html
                'stem_fr' => [
                    'type' => 'stemmer',
                    'name' => 'light_french',
                ],
                'stem_en' => [
                    'type' => 'stemmer',
                    'name' => 'english', // Porter stemming algorithm
                ],
                'stem_de' => [
                    'type' => 'stemmer',
                    'name' => 'light_german',
                ],
                'stem_nl' => [
                    'type' => 'stemmer',
                    'name' => 'dutch', // Snowball algo
                ],
                'stem_es' => [
                    'type' => 'stemmer',
                    'name' => 'light_spanish',
                ],
                'stem_ar' => [
                    'type' => 'stemmer',
                    'name' => 'arabic', // Lucene Arabic stemmer
                ],
                'stem_ru' => [
                    'type' => 'stemmer',
                    'name' => 'russian', // Snowball algo
                ],

                // Some custom rules
                'stem_nl_override' => [
                    'type' => 'stemmer_override',
                    'rules' => [
                        "fiets=>fiets",
                        "bromfiets=>bromfiets",
                        "ei=>eier",
                        "kind=>kinder"
                    ]
                ]
            ],
        ];
    }
}
