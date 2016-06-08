<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\SearchEngine\Elastic\Indexer;

use Alchemy\Phrasea\SearchEngine\Elastic\Indexer\BulkOperation;
use Alchemy\Phrasea\SearchEngine\Elastic\Mapping;
use Alchemy\Phrasea\SearchEngine\Elastic\Thesaurus\Helper;
use Alchemy\Phrasea\SearchEngine\Elastic\Thesaurus\Navigator;
use Alchemy\Phrasea\SearchEngine\Elastic\Thesaurus\TermVisitor;
use databox;
use DOMDocument;
use Elasticsearch\Client;
use Psr\Log\LoggerInterface;


class TermIndexer
{
    const TYPE_NAME = 'term';

    /** @var Client */
    private $client;

    /** @var  databox */
    private $databox;

    private $navigator;
    private $locales;

    /** @var LoggerInterface */
    private $logger;


    public function __construct(Client $client, databox $databox, array $locales, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->databox = $databox;
        $this->locales = $locales;
        $this->logger = $logger;
        $this->navigator = new Navigator();
    }

    public function getIndexName()
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

    public function populateIndex()
    {
        $this->logger->info(sprintf("Populating thesaurus of databox \"%s\" (id=%s)...", $this->databox->get_viewname(), $this->databox->get_sbas_id()));

        $databoxId = $this->databox->get_sbas_id();

        $index = $this->getIndexName();
        $bulk = new BulkOperation($this->client, $index, $this->logger);
        $bulk->setAutoFlushLimit(1000);

        $visitor = new TermVisitor(function ($term) use ($bulk, $databoxId) {
            // Path and id are prefixed with a databox identifier to not
            // collide with other databoxes terms

            // Term structure
            $id = sprintf('%s_%s', $databoxId, $term['id']);
            unset($term['id']);
            $term['path'] = sprintf('/%s%s', $databoxId, $term['path']);
            $term['databox_id'] = $databoxId;

            // Index request
            $params = array();
            $params['id'] = $id;
            $params['type'] = self::TYPE_NAME;
            $params['body'] = $term;

            $bulk->index($params, null);
        });

        $document = Helper::thesaurusFromDatabox($this->databox);
        $this->navigator->walk($document, $visitor);
        $bulk->flush(); // force final flush to avoid mess in log messages

        $this->logger->info(sprintf("Finished populating thesaurus of databox \"%s\" (id=%s)", $this->databox->get_viewname(), $this->databox->get_sbas_id()));
    }

    public function getMapping()
    {
        $mapping = new Mapping();
        $mapping
            ->add('raw_value', 'string')->notAnalyzed()
            ->add('value', 'string')
                ->analyzer('general_light')
                ->addMultiField('strict', 'thesaurus_term_strict')
                ->addLocalizedSubfields($this->locales)
            ->add('context', 'string')
                ->analyzer('general_light')
                ->addMultiField('strict', 'thesaurus_term_strict')
                ->addLocalizedSubfields($this->locales)
            ->add('path', 'string')
                ->analyzer('thesaurus_path', 'indexing')
                ->analyzer('keyword', 'searching')
                ->addRawVersion()
            ->add('lang', 'string')->notAnalyzed()
            ->add('databox_id', 'integer')
        ;

        return $mapping->export();
    }
}
