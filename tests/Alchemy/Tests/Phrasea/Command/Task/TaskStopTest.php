<?php

namespace Alchemy\Tests\Phrasea\Command\Task;

use Alchemy\Phrasea\Command\Task\TaskStop;
use Alchemy\Phrasea\Model\Entities\Task;

/**
 * @group functional
 * @group legacy
 */
class TaskStopTest extends \PhraseanetTestCase
{
    public function testRunWithoutProblems()
    {
        $input = $this->createMock('Symfony\Component\Console\Input\InputInterface');
        $output = $this->createMock('Symfony\Component\Console\Output\OutputInterface');

        $input->expects($this->any())
                ->method('getArgument')
                ->with('task_id')
                ->will($this->returnValue(1));

        $cli = self::$DI['cli'];
        $cli['monolog'] = function () {
            return $this->createMonologMock();
        };

        $command = new TaskStop();
        $command->setContainer($cli);
        $command->execute($input, $output);
    }
}
