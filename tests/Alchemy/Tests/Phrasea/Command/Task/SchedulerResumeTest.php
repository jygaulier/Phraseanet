<?php

namespace Alchemy\Tests\Phrasea\Command\Task;

use Alchemy\Phrasea\Command\Task\SchedulerResumeTasks;

/**
 * @group functional
 * @group legacy
 */
class SchedulerResumeTest extends \PhraseanetTestCase
{
    public function testRunWithoutProblems()
    {
        $cli = self::$DI['cli'];
        $cli['task-manager.status'] = $this->getMockBuilder('Alchemy\Phrasea\TaskManager\TaskManagerStatus')
            ->disableOriginalConstructor()
            ->getMock();

        $cli['task-manager.status']->expects($this->once())
            ->method('start');

        $cli['monolog'] = function () {
            return $this->createMonologMock();
        };

        $input = $this->createMock('Symfony\Component\Console\Input\InputInterface');
        $output = $this->createMock('Symfony\Component\Console\Output\OutputInterface');

        $command = new SchedulerResumeTasks();
        $command->setContainer($cli);
        $command->execute($input, $output);
    }
}
