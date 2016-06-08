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
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class IndexListCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('searchengine:index:list')
            ->setDescription('List search index(es)')
        ;
    }

    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        /** @var Indexer $indexer */
        $indexer = $this->container['elasticsearch.indexer'];
        foreach($indexer->listIndexes() as $sbas_id => $db) {
            $output->writeln(sprintf("databox \"%s\" (sbas_id=%s)", $db['dbname'], $sbas_id));
            foreach($db['indexes'] as $indexName => $index) {
                $msg = $index['exists'] ? "<info>OK</info>" : "<error>ERROR</error>";
                $output->writeln(sprintf("  index \"%s\" : %s", $indexName, $msg));
                foreach($index['aliases'] as $aliasName => $alias) {
                    $msg = $alias['exists'] ? "<info>OK</info>" : "<error>ERROR</error>";
                    $output->writeln(sprintf("    alias \"%s\" : %s", $aliasName, $msg));
                }
            }
        }
    }
}
