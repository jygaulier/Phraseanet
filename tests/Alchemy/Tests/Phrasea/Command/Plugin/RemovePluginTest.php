<?php

namespace Alchemy\Tests\Phrasea\Command\Plugin;

use Alchemy\Phrasea\Command\Plugin\RemovePlugin;

/**
 * @group functional
 * @group legacy
 */
class RemovePluginTest extends PluginCommandTestCase
{
    public function testExecute()
    {
        $name = 'test-plugin';

        $input = $this->createMock('Symfony\Component\Console\Input\InputInterface');
        $input->expects($this->once())
              ->method('getArgument')
              ->with($this->equalTo('name'))
              ->will($this->returnValue($name));

        $output = $this->createMock('Symfony\Component\Console\Output\OutputInterface');

        $command = new RemovePlugin();
        $command->setContainer(self::$DI['cli']);

        $cli = self::getCLI();

        $cli['plugins.manager'] = $this->getMockBuilder('Alchemy\Phrasea\Plugin\PluginManager')
            ->disableOriginalConstructor()
            ->getMock();

        $cli['plugins.manager']->expects($this->once())
            ->method('hasPlugin')
            ->with('test-plugin')
            ->will($this->returnValue(true));

        $cli->offsetUnset('filesystem');
        $cli['filesystem'] = $this->createFilesystemMock();
        $cli['filesystem']->expects($this->at(0))
            ->method('remove')
            ->with(self::$DI['cli']['root.path'].'/www/plugins/'.$name);

        $cli['filesystem']->expects($this->at(1))
            ->method('remove')
            ->with(self::$DI['cli']['plugin.path'].'/'.$name);

        $result = $command->execute($input, $output);

        $this->assertSame(0, $result);

        $conf = self::$DI['cli']['phraseanet.configuration']->getConfig();
        $this->assertArrayNotHasKey('test-plugin', $conf['plugins']);
    }

    private function addPluginData()
    {
        $data = ['key' => 'value'];

        $conf = self::$DI['cli']['phraseanet.configuration']->getConfig();
        $conf['plugins']['test-plugin'] = $data;
        self::$DI['cli']['phraseanet.configuration']->setConfig($conf);

        return $data;
    }
}
