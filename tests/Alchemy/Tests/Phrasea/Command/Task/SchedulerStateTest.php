<?php

namespace Alchemy\Tests\Phrasea\Command\Task;

use Alchemy\Phrasea\Command\Task\SchedulerState;

/**
 * @group functional
 * @group legacy
 */
class SchedulerStateTest extends \PhraseanetTestCase
{
    public function testRunWithoutProblems()
    {
        $input = $this->createMock('Symfony\Component\Console\Input\InputInterface');
        $output = $this->createMock('Symfony\Component\Console\Output\OutputInterface');

        $cli = self::$DI['cli'];
        $cli['monolog'] = function () {
            return $this->createMonologMock();
        };

        $command = new SchedulerState();
        $command->setContainer(self::$DI['cli']);
        $command->execute($input, $output);
    }
}
