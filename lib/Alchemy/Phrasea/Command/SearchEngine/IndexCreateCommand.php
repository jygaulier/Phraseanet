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

class IndexCreateCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('searchengine:index:create')
            ->setDescription('Creates search index')
            ->addOption(
                'databox',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'databox(es) to create index, by id or dbname (default: all databoxes)'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'force new creation (drop before create)'
            )
        ;
    }

    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $matchMethod = Helper::MATCH_ALL_DB_IF_EMPTY | Helper::MATCH_DB_BY_ID | Helper::MATCH_DB_BY_NAME;
        $databoxes = Helper::getDataboxesByIdOrName($this->container, $input, 'databox', $matchMethod);

        /** @var Indexer $indexer */
        $indexer = $this->container['elasticsearch.indexer'];
        foreach($databoxes as $dbox) {
            if($input->getOption('force')) {
                $indexer->dropIndexForDatabox($dbox);
            }
            if ($indexer->indexExistForDatabox($dbox)) {
                $output->writeln(sprintf("Index already exists for databox \"%s\" (id=%s)", $dbox->get_dbname(), $dbox->get_sbas_id()));
            } else {
                $indexer->createIndexForDatabox($dbox, Indexer::WITH_MAPPING);
                $output->writeln('Search index was created');
            }
        }
    }
}
