<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\Command\SearchEngine;

use Alchemy\Phrasea\Command\Command;
use Alchemy\Phrasea\Command\Helper;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Stopwatch\Stopwatch;

class IndexPopulateCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('searchengine:index:populate')
            ->setDescription('Populate search index')
            ->addOption(
                'thesaurus',
                null,
                InputOption::VALUE_NONE,
                'Only populate thesaurus data'
            )
            ->addOption(
                'records',
                null,
                InputOption::VALUE_NONE,
                'Only populate record data'
            )
            ->addOption(
                'databox',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'databox(es) to populate, by id or dbname (default: all databoxes)'
            )
        ;
    }

    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $stopwatch = new Stopwatch();
        $stopwatch->start('populate');

        $what = Indexer::THESAURUS | Indexer::RECORDS;

        if ($thesaurusOnly = $input->getOption('thesaurus')) {
            $what = Indexer::THESAURUS;
        }
        if ($recordsOnly = $input->getOption('records')) {
            $what = Indexer::RECORDS;
        }
        if ($thesaurusOnly && $recordsOnly) {
            throw new \RuntimeException("Could not provide --thesaurus and --records option at the same time.");
        }

        $matchMethod = Helper::MATCH_ALL_DB_IF_EMPTY | Helper::MATCH_DB_BY_ID | Helper::MATCH_DB_BY_NAME;
        $databoxes = Helper::getDataboxesByIdOrName($this->container, $input, 'databox', $matchMethod);

        /** @var Indexer $indexer */
        $indexer = $this->container['elasticsearch.indexer'];
        foreach($databoxes as $dbox) {
            $indexer->populateIndexForDatabox($dbox, $what);
        }

        $event = $stopwatch->stop('populate');

        $output->writeln(
            sprintf(
                "Indexation finished in %s min (Mem. %s Mo)",
                ($event->getDuration()/1000/60),
                bcdiv($event->getMemory(), 1048576, 2)
            )
        );
    }
}
