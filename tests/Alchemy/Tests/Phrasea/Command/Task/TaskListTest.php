<?php

namespace Alchemy\Tests\Phrasea\Command\Task;

use Alchemy\Phrasea\Command\Task\TaskList;

/**
 * @group functional
 * @group legacy
 */
class TaskListTest extends \PhraseanetTestCase
{
    public function testRunWithoutProblems()
    {
        $input = $this->createMock('Symfony\Component\Console\Input\InputInterface');
        $output = $this->createMock('Symfony\Component\Console\Output\OutputInterface');
        $output->expects($this->any())
            ->method('getFormatter')
            ->will($this->returnValue($this->createMock('Symfony\Component\Console\Formatter\OutputFormatterInterface')));

        $cli = self::$DI['cli'];
        $cli['monolog'] = function () {
            return $this->createMonologMock();
        };

        $command = new TaskList();
        $command->setContainer($cli);

        $application = new \Symfony\Component\Console\Application();
        $application->add($command);

        $setupCommand = $application->find('task-manager:task:list');
        $setupCommand->run($input, $output);
    }
}
