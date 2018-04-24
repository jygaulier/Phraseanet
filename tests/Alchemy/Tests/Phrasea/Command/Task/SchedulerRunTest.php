<?php

namespace Alchemy\Tests\Phrasea\Command\Task;

use Alchemy\Phrasea\Command\Task\SchedulerRun;

/**
 * @group functional
 * @group legacy
 */
class SchedulerRunTest extends \PhraseanetTestCase
{
    public function testRunWithoutProblems()
    {
        $cli = self::$DI['cli'];
        $cli['task-manager'] = $this->getMockBuilder('Alchemy\TaskManager\TaskManager')
            ->disableOriginalConstructor()
            ->getMock();

        $cli['task-manager']->expects($this->once())
            ->method('addSubscriber')
            ->with($this->isInstanceOf('Alchemy\TaskManager\Event\TaskManagerSubscriber\LockFileSubscriber'));

        $cli['task-manager']->expects($this->once())
            ->method('start');

        $cli['monolog'] = function () {
            return $this->createMonologMock();
        };

        $input = $this->createMock('Symfony\Component\Console\Input\InputInterface');
        $output = $this->createMock('Symfony\Component\Console\Output\OutputInterface');

        $command = new SchedulerRun();
        $command->setContainer($cli);
        $command->execute($input, $output);
    }
}
