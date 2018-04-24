<?php

namespace Alchemy\Tests\Phrasea\Command\Task;

use Alchemy\Phrasea\Command\Task\SchedulerPauseTasks;

/**
 * @group functional
 * @group legacy
 */
class SchedulerPauseTest extends \PhraseanetTestCase
{
    public function testRunWithoutProblems()
    {
        $cli = self::$DI['cli'];
        $cli['task-manager.status'] = $this->getMockBuilder('Alchemy\Phrasea\TaskManager\TaskManagerStatus')
            ->disableOriginalConstructor()
            ->getMock();

        $cli['task-manager.status']->expects($this->once())
            ->method('stop');

        $input = $this->createMock('Symfony\Component\Console\Input\InputInterface');
        $output = $this->createMock('Symfony\Component\Console\Output\OutputInterface');

        $cli['monolog'] = function () {
            return $this->createMonologMock();
        };

        $command = new SchedulerPauseTasks();
        $command->setContainer(self::$DI['cli']);
        $command->execute($input, $output);
    }
}
