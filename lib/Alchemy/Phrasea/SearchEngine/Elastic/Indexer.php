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

use Alchemy\Phrasea\Model\RecordInterface;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\BulkOperation;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\RecordIndexer;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\RecordIndexerForDatabox;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\TermIndexer;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\RecordQueuer;
use Alchemy\Phrasea\SearchEngine\Elastic\Structure\GlobalStructure;
use Alchemy\Phrasea\SearchEngine\Elastic\Structure\Structure;
use appbox;
use databox;
use Elasticsearch\Client;
use Psr\Log\LoggerInterface;
use igorw;
use Psr\Log\NullLogger;
use Symfony\Component\Stopwatch\Stopwatch;
use SplObjectStorage;



class Indexer
{
    const THESAURUS = 1;
    const RECORDS   = 2;

    /** @var \Elasticsearch\Client */
    private $client;
    /** @var ElasticsearchOptions */
    private $options;
    private $appbox;
    /** @var LoggerInterface|null */
    private $logger;

    // former "all-databoxes" indexers
    private $recordIndexer;
    private $termIndexer;

    /** @var  RecordHelper */
    private $recordHelper;

    private $locales;

    // array of "tools" for each databox (queues, indexer, thesaurus, ...)
    private $databoxToolbox;

    public function __construct(Client $client, ElasticsearchOptions $options, TermIndexer $termIndexer, RecordIndexer $recordIndexer, appbox $appbox,
                                Structure $structure, RecordHelper $helper, Thesaurus $thesaurus, array $locales, LoggerInterface $logger=null)
    {
        $this->client   = $client;
        $this->options  = $options;
        $this->termIndexer = $termIndexer;
        $this->recordIndexer = $recordIndexer;
        $this->appbox   = $appbox;
        $this->logger = $logger ?: new NullLogger();

        $this->recordHelper = $helper;
        $this->locales = $locales;

        $this->databoxToolbox = [];
    }

    /**
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * @param bool $withMapping
     *
     * create indexes for all databoxes
     */
    public function createIndex($withMapping = true)
    {
        $termsIndices = [];
        $recordsIndices = [];
        foreach($this->appbox->get_databoxes() as $dbox) {
            if(!$this->indexExistForDatabox($dbox)) {
                $this->createIndexForDatabox($dbox, $withMapping);
                // todo : check that indexes do exist before adding them to the alias(es)
                $termsIndices[] = $this->getTermsIndexNameForDatabox($dbox);
                $recordsIndices[] = $this->getRecordsIndexNameForDatabox($dbox);
            }
        }

        // create the aliases
        /* too bad, creating an alias on multiple indices is not ok in es 1.7 ...
        $params = [
            'body' => [
                'actions' => [
                    // one alias for all terms indices
                    [
                        'add' => [
                            'indices' => $termsIndices,
                            'alias' => $this->options->getIndexName() . '.t',
                        ]
                    ],
                    // one alias for all records indices
                    [
                        'add' => [
                            'indices' => $recordsIndices,
                            'alias' => $this->options->getIndexName() . '.r',
                        ]
                    ],
                ],
            ],
        ];
file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) creating aliases \"%s\"\n", __FILE__, __LINE__, var_export($params, true)), FILE_APPEND);

        $this->client->indices()->updateAliases($params);
        $params = [
            'body' => [
                'actions' => [
                    // one alias for all
                    [
                        'add' => [
                            'indices' => [
                                $this->options->getIndexName() . '.t',
                                $this->options->getIndexName() . '.r',
                            ],
                            'alias' => $this->options->getIndexName(),
                        ]
                    ],
                ],
            ],
        ];
file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) creating aliases \"%s\"\n", __FILE__, __LINE__, var_export($params, true)), FILE_APPEND);
        $this->client->indices()->updateAliases($params);
        */

        /* ... so we need to loop for now */
        $actions = [];
        foreach($termsIndices as $index) {
            $actions[] = [
                'add' => [
                    'index' => $index,
                    'alias' => $this->options->getIndexName() . '.t',
                ]
            ];
        }
        foreach($recordsIndices as $index) {
            $actions[] = [
                'add' => [
                    'index' => $index,
                    'alias' => $this->options->getIndexName() . '.r',
                ]
            ];
        }
        $params = [
            'body' => [
                'actions' => $actions
            ]
        ];
file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) creating aliases \"%s\"\n", __FILE__, __LINE__, var_export($params, true)), FILE_APPEND);
        $this->client->indices()->updateAliases($params);

        $params = [
            'body' => [
                'actions' => [
                    // one alias for all
                    [
                        'add' => [
                            'index' => [
                                $this->options->getIndexName() . '.t',
                            ],
                            'alias' => $this->options->getIndexName(),
                        ]
                    ],
                    [
                        'add' => [
                            'index' => [
                                $this->options->getIndexName() . '.r',
                            ],
                            'alias' => $this->options->getIndexName(),
                        ]
                    ],
                ],
            ],
        ];
file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) creating aliases \"%s\"\n", __FILE__, __LINE__, var_export($params, true)), FILE_APPEND);
        $this->client->indices()->updateAliases($params);

    }

    private function createIndexForDatabox(databox $dbox, $withMapping)
    {
        $common_params = [
            'body' => [
                'settings' => [
                    'number_of_shards'   => $this->options->getShards(),
                    'number_of_replicas' => $this->options->getReplicas(),
                    'analysis'           => $this->getAnalysis(),
                ],
            ],
        ];

        $params = $common_params;
        $params['index'] = $this->getTermsIndexNameForDatabox($dbox);
        if ($withMapping) {
            $params['body']['mappings'][TermIndexer::TYPE_NAME] = $this->termIndexer->getMapping();
        }
file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) creating index \"%s\"\n", __FILE__, __LINE__, $params['index']), FILE_APPEND);
        $this->client->indices()->create($params);

        $params = $common_params;
        $params['index'] = $this->getRecordsIndexNameForDatabox($dbox);
        if ($withMapping) {
            $params['body']['mappings'][RecordIndexer::TYPE_NAME] = $this->getRecordIndexerForDatabox($dbox)->getMapping();
        }

file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) creating index \"%s\"\n", __FILE__, __LINE__, $params['index']), FILE_APPEND);
        $this->client->indices()->create($params);
    }

    private function getStructureForDatabox(databox $dbox)
    {
        $toolbox = $this->getToolboxForDatabox($dbox->get_sbas_id());
        if(!array_key_exists('structure', $toolbox)) {
            $toolbox['structure'] = GlobalStructure::createFromDataboxes([$dbox]);
        }

        return $toolbox['structure'];
    }

    /**
     * @param databox $dbox
     * @return Thesaurus
     */
    private function getThesaurusForDatabox(databox $dbox)
    {
        $toolbox = $this->getToolboxForDatabox($dbox->get_sbas_id());
        if(!array_key_exists('thesaurus', $toolbox)) {
            // don't use the all-databoxes thesaurus, but one for this databox (simply change the index....)
            $options = clone $this->options;
            $options->setIndexName($this->getTermsIndexNameForDatabox($dbox));
            $toolbox['thesaurus'] = new Thesaurus($this->client, $options, $this->logger);
            unset($options);
        }

        return $toolbox['thesaurus'];
    }

    /**
     * @param databox $dbox
     * @return RecordIndexerForDatabox
     */
    private function getRecordIndexerForDatabox(databox $dbox)
    {
        $toolbox = $this->getToolboxForDatabox($dbox->get_sbas_id());
        if(!array_key_exists('recordIndexer', $toolbox)) {
            $toolbox['recordIndexer'] = new RecordIndexerForDatabox(
                $this->getStructureForDatabox($dbox), // don't use the all-databoxes structure, but one for this databox
                $this->recordHelper,
                $this->getThesaurusForDatabox($dbox), // don't use the all-databoxes thesaurus, but one for this databox
                $this->locales,
                $this->logger
            );
        }

        return $toolbox['recordIndexer'];
    }

    public function updateMapping()
    {
        $params = array();
        $params['index'] = $this->options->getIndexName();
        $params['type'] = RecordIndexer::TYPE_NAME;
        $params['body'][RecordIndexer::TYPE_NAME] = $this->recordIndexer->getMapping();
        $params['body'][TermIndexer::TYPE_NAME]   = $this->termIndexer->getMapping();

        // @todo This must throw a new indexation if a mapping is edited
        $this->client->indices()->putMapping($params);
    }

    /**
     * delete indexes of all databoxes
     */
    public function deleteIndex()
    {
        // delete former common index just in case
        //
        // !!!
        //     in fact even if the common "index" has become an ALIAS to all dbox/indexes,
        //     deleting it as an "index" deletes (cascade) all aliases and indexes
        //     so there should be nothing more to do.
        // !!!
        $this->deleteESIndex($this->options->getIndexName());

        // but since the creation or cleanup might have failed before
        // leaving indexes without alias, we clean anyway

        // delete aliases
        foreach($this->appbox->get_databoxes() as $dbox) {
            $this->deleteESAlias($this->getTermsIndexNameForDatabox($dbox), $this->options->getIndexName() . '.t');
            $this->deleteESAlias($this->getTermsIndexNameForDatabox($dbox), $this->options->getIndexName());
            $this->deleteESAlias($this->getRecordsIndexNameForDatabox($dbox), $this->options->getIndexName() . '.r');
            $this->deleteESAlias($this->getRecordsIndexNameForDatabox($dbox), $this->options->getIndexName());
        }

        // delete indexes
        foreach($this->appbox->get_databoxes() as $dbox) {
            $this->deleteESIndex($this->getTermsIndexNameForDatabox($dbox));
            $this->deleteESIndex($this->getRecordsIndexNameForDatabox($dbox));
        }

    }

    private function deleteESAlias($indexName, $aliasName)
    {
file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) should delete alias \"%s\" for index \"%s\"\n", __FILE__, __LINE__, $aliasName, $indexName), FILE_APPEND);
        $params = [
            'index' => $indexName,
            'name' => $aliasName,
        ];
        try {
            $this->client->indices()->deleteAlias($params);
file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) alias \"%s\" for index \"%s\" deleted\n", __FILE__, __LINE__, $aliasName, $indexName), FILE_APPEND);
        }
        catch(\Exception $e) {
            // no-op
file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) alias \"%s\" for index \"%s\" failed to delete\n", __FILE__, __LINE__, $aliasName, $indexName), FILE_APPEND);
        }
    }

    private function deleteESIndex($indexname)
    {
file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) should delete index \"%s\"\n", __FILE__, __LINE__, $indexname), FILE_APPEND);
        $indexExist = false;
        // !!! ...->exists(...) throws if index does not exists !!!
        try {
            $indexExist = $this->client->indices()->exists(['index' => $indexname]);
        }
        catch(\Exception $e) {
            // no-op : index is supposed not to exist
        }

        if($indexExist) {
file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) deleting index \"%s\"\n", __FILE__, __LINE__, $indexname), FILE_APPEND);
            try {
                $this->client->indices()->delete(['index' => $indexname]);
                $this->logger->info(sprintf('ES index "%s" deleted', $indexname));
file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) index \"%s\" deleted\n", __FILE__, __LINE__, $indexname), FILE_APPEND);
            } catch (\Exception $e) {
                // no-op
file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) failed to delete index \"%s\"\n", __FILE__, __LINE__, $indexname), FILE_APPEND);
            }
        }
        else {
file_put_contents("/tmp/phraseanet-log.txt", sprintf("%s (%d) index \"%s\" does not exists\n", __FILE__, __LINE__, $indexname), FILE_APPEND);
        }
    }

    /**
     * @return bool
     *
     * return true if all databoxes indexes exists
     */
    public function indexExists()
    {
        foreach($this->appbox->get_databoxes() as $dbox) {
            if(!$this->indexExistForDatabox($dbox)) {
                return false;
            }
        }

        return true;
    }

    private function indexExistForDatabox(databox $dbox)
    {
        return $this->client->indices()->exists(['index' => $this->getTermsIndexNameForDatabox($dbox)])
            && $this->client->indices()->exists(['index' => $this->getRecordsIndexNameForDatabox($dbox)]);
    }

    private function getTermsIndexNameForDatabox(databox $dbox)
    {
        return $dbox->get_dbname() . '.t';
    }

    private function getRecordsIndexNameForDatabox(databox $dbox)
    {
        return $dbox->get_dbname() . '.r';
    }

    public function populateIndex($what, array $databoxes_id = [])
    {
        $stopwatch = new Stopwatch();
        $stopwatch->start('populate');

        if (!empty($databoxes_id)) {
            // If databoxes are given, only use those
            $databoxes = array_map(array($this->appbox, 'get_databox'), $databoxes_id);
        } else {
            $databoxes = $this->appbox->get_databoxes();
        }

        /** @var databox $databox */
        foreach ($databoxes as $databox) {
            // Record indexing depends on indexed terms so we need to make
            // everything ready to search
            if ($what & self::THESAURUS) {
                $terms_index = $this->getTermsIndexNameForDatabox($databox);
                $terms_bulk = new BulkOperation($this->client, $terms_index, $this->logger);
                $terms_bulk->setAutoFlushLimit(1000);

                $this->termIndexer->populateIndex($terms_bulk, $databox);

                // Flush just in case, it's a noop when already done
                $terms_bulk->flush();
                unset($terms_bulk);

                $this->client->indices()->refresh();
            }

            if ($what & self::RECORDS) {
                $records_index = $this->getRecordsIndexNameForDatabox($databox);
                $records_bulk = new BulkOperation($this->client, $records_index, $this->logger);
                $records_bulk->setAutoFlushLimit(1000);

                $this->recordIndexer->populateIndex($records_bulk, $databox);

                // Final flush
                $records_bulk->flush();
                unset($records_bulk);
            }

            // Optimize index
            $params = array('index' => $this->options->getIndexName());
            $this->client->indices()->optimize($params);
        };

        $event = $stopwatch->stop('populate');

        printf("Indexation finished in %s min (Mem. %s Mo)\n", ($event->getDuration()/1000/60), bcdiv($event->getMemory(), 1048576, 2));
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
        $toolbox = $this->getToolboxForDatabox($databox_id);
        if(!array_key_exists('queues', $toolbox)) {
            $toolbox['queues'] = [
                'index' => new SplObjectStorage(),
                'delete' => new SplObjectStorage()
            ];
        }

        return $toolbox['queues'];
    }

    public function indexRecord(RecordInterface $record)
    {
        $q = $this->getQueuesForDatabox($record->getDataboxId());
        $q['index']->attach($record);
    }

    public function deleteRecord(RecordInterface $record)
    {
        $q = $this->getQueuesForDatabox($record->getDataboxId());
        $q['delete']->attach($record);
    }

    /**
     * @param databox[] $databoxes    databoxes to index
     * @throws \Exception
     */
    public function indexScheduledRecords(array $databoxes)
    {
        /** @var databox $databox */
        foreach ($databoxes as $databox) {
            $records_index = $this->getRecordsIndexNameForDatabox($databox);
            $records_bulk = new BulkOperation($this->client, $records_index, $this->logger);
            $records_bulk->setAutoFlushLimit(1000);

            $this->recordIndexer->indexScheduled($records_bulk, $databox);

            $records_bulk->flush();

            unset($records_bulk);
        };
    }

    public function flushQueue()
    {
        /**
         * @var int $databox_id
         * @var SplObjectStorage $q
         */
        foreach($this->databoxToolbox as $databox_id => $toolbox) {
            $q = $this->getQueuesForDatabox($databox_id);
            // it's useless to index records that are to be deleted, remove em from the index q.
            $q['index']->removeAll($q['delete']);

            // Skip if nothing to do
            if (empty($q['index']) && empty($q['delete'])) {
                continue;
            }
            $records_index = $this->getRecordsIndexNameForDatabox($this->appbox->get_databox($databox_id));
            $bulk = new BulkOperation($this->client, $records_index, $this->logger);
            $bulk->setAutoFlushLimit(1000);

            $this->recordIndexer->index($bulk, $q['index']);
            $this->recordIndexer->delete($bulk, $q['delete']);

            $bulk->flush();

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
