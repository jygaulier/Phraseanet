<?php
/**
 * This file is part of Phraseanet
 *
 * (c) 2005-2016 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\SearchEngine\Elastic\Indexer;

use Alchemy\Phrasea\SearchEngine\Elastic\ElasticsearchOptions;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\Record\Delegate\FetcherDelegateInterface;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\Record\Delegate\RecordIdListFetcherDelegate;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\Record\Delegate\ScheduledFetcherDelegate;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\Record\Fetcher;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\Record\Hydrator\CoreHydrator;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\Record\Hydrator\FlagHydrator;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\Record\Hydrator\MetadataHydrator;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\Record\Hydrator\SubDefinitionHydrator;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\Record\Hydrator\ThesaurusHydrator;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\Record\Hydrator\TitleHydrator;
use Alchemy\Phrasea\SearchEngine\Elastic\Mapping;
use Alchemy\Phrasea\SearchEngine\Elastic\RecordHelper;
use Alchemy\Phrasea\SearchEngine\Elastic\Structure\Field;
use Alchemy\Phrasea\SearchEngine\Elastic\Structure\GlobalStructure;
use Alchemy\Phrasea\SearchEngine\Elastic\Thesaurus;
use Alchemy\Phrasea\SearchEngine\Elastic\Thesaurus\CandidateTerms;
use databox;
use Elasticsearch\Client;
use Psr\Log\LoggerInterface;

class RecordIndexer
{
    const TYPE_NAME = 'record';

    /** @var Client */
    private $client;

    /** @var ElasticsearchOptions */
    private $options;

    /** @var  databox */
    private $databox;

    private $structure;

    private $recordHelper;

    private $thesaurus;

    /**
     * @var array
     */
    private $locales;

    private $logger;

    private function getUniqueOperationId($record_key)
    {
        $_key = dechex(mt_rand());
        return $_key . '_' . $record_key;
    }

    public function __construct(Client $client, ElasticsearchOptions $options, databox $databox, RecordHelper $recordHelper, array $locales, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->databox = $databox;
        $this->recordHelper = $recordHelper;
        $this->locales = $locales;
        $this->logger = $logger;

        // a thesaurus linked to the .t index for this databox
        $thesaurusOptions = clone $options;
        $thesaurusOptions->setIndexName($this->getTermIndexName());
        $this->thesaurus = new Thesaurus($this->client, $thesaurusOptions, $this->logger);   // !!! specific options 'index'

        $this->options = clone $options;
        $this->options->setIndexName($this->getIndexName());
        $this->structure = GlobalStructure::createFromDataboxes([$databox]);
    }

    public function getIndexName()
    {
        return $this->databox->get_dbname() . '.r';
    }

    public function getTermIndexName()
    {
        return $this->databox->get_dbname() . '.t';
    }


    public function indexExists()
    {
        return $this->client->indices()->exists(
            [
                'index' => $this->getIndexName()
            ]
        );
    }

    public function createIndex($settings, $withMapping)
    {
        $params = [
            'index' => $this->getIndexName(),
            'body' => [
                'settings' => $settings,
            ],
        ];
        if ($withMapping) {
            $params['body']['mappings'][self::TYPE_NAME] = $this->getMapping();
        }

        $this->logger->info(sprintf("Creating index \"%s\"", $params['index']));
        $this->client->indices()->create($params);
    }

    public function dropIndex()
    {
        $this->logger->info(sprintf("Deleting index \"%s\"", $this->getIndexName()));
        try {
            $this->client->indices()->delete(['index' => $this->getIndexName()]);
        } catch (\Exception $e) {
            // no-op
        }
    }

    /**
     * ES made a bulk op, check our (index) operations to drop the "indexing" & "to_index" jetons
     *
     * @param array $operation_identifiers  key:op_identifier ; value:operation result (json from es)
     * @param array $submited_records       records indexed, key:op_identifier
     */
    private function onBulkFlush(array $operation_identifiers, array &$submited_records)
    {
        $records = array_intersect_key(
            $submited_records,        // this is OUR records list
            $operation_identifiers          // reduce to the records indexed by this bulk (should be the same...)
        );
        if(count($records) === 0) {
            return;
        }
        // Commit and remove "indexing" flag
        RecordQueuer::didFinishIndexingRecords(array_values($records), $this->databox);
        foreach (array_keys($records) as $id) {
            unset($submited_records[$id]);
        }
    }

    /**
     * index whole databox(es), don't test actual "jetons"
     * called by command "populate"
     *
     */
    public function populateIndex()
    {
        $submited_records = [];

        $this->logger->info(sprintf("Populating records of databox \"%s\" (id=%s)...", $this->databox->get_viewname(), $this->databox->get_sbas_id()));

        // make fetcher (no delegate, scan the whole records)
        $fetcher = $this->createFetcherForDatabox($this->databox);

        // post fetch : flag records as "indexing"
        $fetcher->setPostFetch(function(array $records) use ($fetcher) {
            RecordQueuer::didStartIndexingRecords($records, $this->databox);
            // do not restart the fetcher since it has no clause on jetons
        });

        $index = $this->getIndexName();
        $bulk = new BulkOperation($this->client, $index, $this->logger);
        $bulk->setAutoFlushLimit(1000);

        // bulk flush : flag records as "indexed"
        $bulk->onFlush(function($operation_identifiers) use (&$submited_records) {
            $this->onBulkFlush($operation_identifiers, $submited_records);
        });

        // Perform indexing
        $this->indexFromFetcher($bulk, $fetcher, $submited_records);
        $bulk->flush(); // force final flush to avoid mess in log messages

        $this->logger->info(sprintf("Finished populating records of databox \"%s\" (id=%s)", $this->databox->get_viewname(), $this->databox->get_sbas_id()));
    }

    /**
     * Index the records flagged as "to_index" on databoxes
     * called by task "indexer"
     *
     */
    public function indexScheduled()
    {
        $submited_records = [];

        // Make fetcher
        $delegate = new ScheduledFetcherDelegate();
        $fetcher = $this->createFetcherForDatabox($this->databox, $delegate);

        // post fetch : flag records as "indexing"
        $fetcher->setPostFetch(function(array $records) use ($fetcher) {
            RecordQueuer::didStartIndexingRecords($records, $this->databox);
            // because changing the flag on the records affects the "where" clause of the fetcher,
            // restart it each time
            $fetcher->restart();
        });

        $index = $this->getIndexName();
        $bulk = new BulkOperation($this->client, $index, $this->logger);
        $bulk->setAutoFlushLimit(1000);

        // bulk flush : flag records as "indexed"
        $bulk->onFlush(function($operation_identifiers) use (&$submited_records) {
            $this->onBulkFlush($operation_identifiers, $submited_records);
        });

        // Perform indexing
        $this->indexFromFetcher($bulk, $fetcher, $submited_records);
        $bulk->flush(); // force final flush to avoid mess in log messages
    }

    /**
     * Index a list of records
     *
     * @param array $record_ids
     */
    public function index(array $record_ids)
    {
        $submited_records = [];

        // Make fetcher
        $delegate = new RecordIdListFetcherDelegate($record_ids);
        $fetcher = $this->createFetcherForDatabox($this->databox, $delegate);

        // post fetch : flag records as "indexing"
        $fetcher->setPostFetch(function(array $records) use ($fetcher) {
            RecordQueuer::didStartIndexingRecords($records, $this->databox);
            // do not restart the fetcher since it has no clause on jetons
        });

        $index = $this->getIndexName();
        $bulk = new BulkOperation($this->client, $index, $this->logger);
        $bulk->setAutoFlushLimit(1000);

        // bulk flush : flag records as "indexed"
        $bulk->onFlush(function($operation_identifiers) use (&$submited_records) {
            $this->onBulkFlush($operation_identifiers, $submited_records);
        });

        // Perform indexing
        $this->indexFromFetcher($bulk, $fetcher, $submited_records);
    }

    /**
     * Delete a list of records
     *
     * @param array $record_ids
     */
    public function delete(array $record_ids)
    {
        $index = $this->getIndexName();
        $bulk = new BulkOperation($this->client, $index, $this->logger);
        $bulk->setAutoFlushLimit(1000);

        foreach ($record_ids as $record_id) {
            $params = array();
            $params['id'] = $record_id;
            $params['type'] = self::TYPE_NAME;
            $bulk->delete($params, null);       // no operationIdentifier is related to a delete op
        }
    }

    private function createFetcherForDatabox(databox $databox, FetcherDelegateInterface $delegate = null)
    {
        $connection = $databox->get_connection();
        $candidateTerms = new CandidateTerms($databox);
        $fetcher = new Fetcher($databox, array(
            new CoreHydrator($databox->get_sbas_id(), $databox->get_viewname(), $this->recordHelper),
            new TitleHydrator($connection),
            new MetadataHydrator($connection, $this->structure, $this->recordHelper),
            new FlagHydrator($this->structure, $databox),
            new ThesaurusHydrator($this->structure, $this->thesaurus, $candidateTerms),
            new SubDefinitionHydrator($connection)
        ), $delegate);
        $fetcher->setBatchSize(200);
        $fetcher->onDrain(function() use ($candidateTerms) {
            $candidateTerms->save();
        });

        return $fetcher;
    }

    private function indexFromFetcher(BulkOperation $bulk, Fetcher $fetcher, array &$submited_records)
    {
        while ($record = $fetcher->fetch()) {
            $op_identifier = $this->getUniqueOperationId($record['id']);

            $params = array();
            $params['id'] = $record['id'];
            unset($record['id']);
            $params['type'] = self::TYPE_NAME;
            $params['body'] = $record;

            $submited_records[$op_identifier] = $record;
            $bulk->index($params, $op_identifier);
        }
    }

    public function getMapping()
    {
        $mapping = new Mapping();
        $mapping
            // Identifiers
            ->add('record_id', 'integer')  // Compound primary key
            ->add('databox_id', 'integer') // Compound primary key
            ->add('databox_name', 'string')->notAnalyzed() // database name (still indexed for facets)
            ->add('base_id', 'integer') // Unique collection ID
            ->add('collection_id', 'integer')->notIndexed() // Useless collection ID (local to databox)
            ->add('collection_name', 'string')->notAnalyzed() // Collection name (still indexed for facets)
            ->add('uuid', 'string')->notIndexed()
            ->add('sha256', 'string')->notIndexed()
            // Mandatory metadata
            ->add('original_name', 'string')->notIndexed()
            ->add('mime', 'string')->notAnalyzed() // Indexed for Kibana only
            ->add('type', 'string')->notAnalyzed()
            ->add('record_type', 'string')->notAnalyzed() // record or story
            // Dates
            ->add('created_on', 'date')->format(Mapping::DATE_FORMAT_MYSQL_OR_CAPTION)
            ->add('updated_on', 'date')->format(Mapping::DATE_FORMAT_MYSQL_OR_CAPTION)
            // Thesaurus
            ->add('concept_path', $this->getThesaurusPathMapping())
            // EXIF
            ->add('metadata_tags', $this->getMetadataTagMapping())
            // Status
            ->add('flags', $this->getFlagsMapping())
            ->add('flags_bitfield', 'integer')->notIndexed()
            // Keep some fields arround for display purpose
            ->add('subdefs', Mapping::disabledMapping())
            ->add('title', Mapping::disabledMapping())
        ;

        // Caption mapping
        $this->buildCaptionMapping($this->structure->getUnrestrictedFields(), $mapping, 'caption');
        $this->buildCaptionMapping($this->structure->getPrivateFields(), $mapping, 'private_caption');

        return $mapping->export();
    }

    private function buildCaptionMapping(array $fields, Mapping $root, $section)
    {
        $mapping = new Mapping();
        foreach ($fields as $field) {
            $this->addFieldToMapping($field, $mapping);
        }
        $root->add($section, $mapping);
        $root
            ->add(sprintf('%s_all', $section), 'string')
            ->addLocalizedSubfields($this->locales)
            ->addRawVersion()
        ;
    }

    private function addFieldToMapping(Field $field, Mapping $mapping)
    {
        $type = $field->getType();
        $mapping->add($field->getName(), $type);

        if ($type === Mapping::TYPE_DATE) {
            $mapping->format(Mapping::DATE_FORMAT_CAPTION);
        }

        if ($type === Mapping::TYPE_STRING) {
            $searchable = $field->isSearchable();
            $facet = $field->isFacet();
            if (!$searchable && !$facet) {
                $mapping->notIndexed();
            } else {
                $mapping->addRawVersion();
                $mapping->addAnalyzedVersion($this->locales);
                $mapping->enableTermVectors(true);
            }
        }
    }

    private function getThesaurusPathMapping()
    {
        $mapping = new Mapping();
        foreach ($this->structure->getThesaurusEnabledFields() as $name => $_) {
            $mapping
                ->add($name, 'string')
                ->analyzer('thesaurus_path', 'indexing')
                ->analyzer('keyword', 'searching')
                ->addRawVersion()
            ;
        }

        return $mapping;
    }

    private function getMetadataTagMapping()
    {
        $mapping = new Mapping();
        foreach ($this->structure->getMetadataTags() as $tag) {
            $type = $tag->getType();
            $mapping->add($tag->getName(), $type);
            if ($type === Mapping::TYPE_STRING) {
                if ($tag->isAnalyzable()) {
                    $mapping->addRawVersion();
                } else {
                    $mapping->notAnalyzed();
                }
            }
        }

        return $mapping;
    }

    private function getFlagsMapping()
    {
        $mapping = new Mapping();
        foreach ($this->structure->getAllFlags() as $name => $_) {
            $mapping->add($name, 'boolean');
        }

        return $mapping;
    }
}
