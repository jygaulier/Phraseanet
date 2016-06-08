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
use Alchemy\Phrasea\Command\Helper as CommandHelper;
use Alchemy\Phrasea\SearchEngine\Elastic\Indexer;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class IndexDropCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('searchengine:index:drop')
            ->setDescription('Delete all search indexes')
            ->addOption(
                'databox',
                null,
                InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY,
                'databox(es) to drop index, by id or dbname (default: all databoxes)'
            )
            ->addOption(
               'force',
               null,
               InputOption::VALUE_NONE,
               "Don't ask for for the dropping of the index, but force the operation to run."
            )
        ;
    }

    protected function doExecute(InputInterface $input, OutputInterface $output)
    {
        $matchMethod = CommandHelper::MATCH_ALL_DB_IF_EMPTY | CommandHelper::MATCH_DB_BY_ID | CommandHelper::MATCH_DB_BY_NAME;
        $databoxes = CommandHelper::getDataboxesByIdOrName($this->container, $input, 'databox', $matchMethod);

        if ($input->getOption('force')) {
            $confirmation = true;
        } else {
            /** @var QuestionHelper $questionHelper */
            $questionHelper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf(
                    "You are about to delete %d index(es) and all contained data. Are you sure you wish to continue? (y/n)",
                    count($databoxes)
                ),
                false
            );
            $confirmation = $questionHelper->ask($input, $output, $question);
        }

        if ($confirmation) {
            /** @var Indexer $indexer */
            $indexer = $this->container['elasticsearch.indexer'];
            foreach($databoxes as $dbox) {
                $indexer->dropIndexForDatabox($dbox);
                $output->writeln('Search indexes have been dropped');
            }
            if(!$input->getOption('databox')) {
                // if all is to be deleted, delete also the main alias
                // todo
            }
        } else {
            $output->writeln('Canceled.');
        }
    }
}
