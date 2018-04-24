<?php

namespace Alchemy\Tests\Phrasea\Command\Compile;

use Alchemy\Phrasea\Command\Compile\Configuration;

/**
 * @group functional
 * @group legacy
 */
class ConfigurationTest extends \PhraseanetTestCase
{
    public function testExecute()
    {
        $command = new Configuration();
        $command->setContainer(self::$DI['cli']);

        $cli = $this->getCLI();

        $cli->offsetUnset('configuration.store');
        $cli['configuration.store'] = $this->createMock('Alchemy\Phrasea\Core\Configuration\ConfigurationInterface');
        $cli['configuration.store']->expects($this->once())
            ->method('compileAndWrite');

        $input = $this->createMock('Symfony\Component\Console\Input\InputInterface');
        $output = $this->createMock('Symfony\Component\Console\Output\OutputInterface');

        $command->execute($input, $output);
    }
}
