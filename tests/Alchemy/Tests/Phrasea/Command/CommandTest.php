<?php

namespace Alchemy\Tests\Phrasea\Command;

use Alchemy\Phrasea\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @group functional
 * @group legacy
 */
class CommandTest extends \PhraseanetTestCase
{
    /**
     * @var Command
     */
    protected $object;

    public function setUp()
    {
        parent::setUp();
        $this->object = new AbstractCommandTester('name');
    }

    /**
     * @covers Alchemy\Phrasea\Command\Command::getFormattedDuration
     */
    public function testGetFormattedDuration()
    {
        $this->assertRegExp('/3(\.|,)6 days/', $this->object->getFormattedDuration(86400 * 3.6));
        $this->assertRegExp('/2(\.|,)4 hours/', $this->object->getFormattedDuration(3600 * 2.4));
        $this->assertRegExp('/1(\.|,)2 minutes/', $this->object->getFormattedDuration(60 * 1.2));
        $this->assertRegExp('/30 seconds/', $this->object->getFormattedDuration(30));
    }
}

class AbstractCommandTester extends Command
{
    protected function doExecute(InputInterface $input, OutputInterface $output)
    {

    }
}
