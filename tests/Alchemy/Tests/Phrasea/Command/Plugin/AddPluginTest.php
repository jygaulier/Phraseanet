<?php

namespace Alchemy\Tests\Phrasea\Command\Plugin;

use Alchemy\Phrasea\Command\Plugin\AddPlugin;

/**
 * @group functional
 * @group legacy
 */
class AddPluginTest extends PluginCommandTestCase
{
    public function testExecute()
    {
        $source = 'TestPlugin';

        $input = $this->createMock('Symfony\Component\Console\Input\InputInterface');
        $input->expects($this->once())
            ->method('getArgument')
            ->with($this->equalTo('source'))
            ->will($this->returnValue($source));

        $output = $this->createMock('Symfony\Component\Console\Output\OutputInterface');

        $command = new AddPlugin();
        $command->setContainer(self::$DI['cli']);

        $manifest = $this->createManifestMock();
        $manifest->expects($this->any())
            ->method('getName')
            ->will($this->returnValue($source));

        $cli = self::getCLI();

        $cli['temporary-filesystem'] = $this->createTemporaryFilesystemMock();
        $cli['plugins.autoloader-generator'] = $this->createPluginsAutoloaderGeneratorMock();
        $cli['plugins.explorer'] = [self::$DI['cli']['plugin.path'].'/TestPlugin'];
        $cli['plugins.plugins-validator'] = $this->createPluginsValidatorMock();
        $cli->offsetUnset('filesystem');
        $cli['filesystem'] = $this->createFilesystemMock();
        $cli['plugins.composer-installer'] = $this->createComposerInstallerMock();
        $cli['plugins.importer'] = $this->createPluginsImporterMock();

        $cli['temporary-filesystem']->expects($this->once())
            ->method('createTemporaryDirectory')
            ->will($this->returnValue('tempdir'));

        $cli['plugins.importer']->expects($this->once())
            ->method('import')
            ->with($source, 'tempdir');

        // the plugin is checked when updating config files
        $cli['plugins.plugins-validator']->expects($this->at(0))
            ->method('validatePlugin')
            ->with('tempdir')
            ->will($this->returnValue($manifest));

        $cli['plugins.plugins-validator']->expects($this->at(1))
            ->method('validatePlugin')
            ->with(self::$DI['cli']['plugin.path'].'/TestPlugin')
            ->will($this->returnValue($manifest));

        $cli['plugins.composer-installer']->expects($this->once())
            ->method('install')
            ->with('tempdir');

        $cli['filesystem']->expects($this->at(0))
            ->method('mirror')
            ->with('tempdir', self::$DI['cli']['plugin.path'].'/TestPlugin');

        $cli['filesystem']->expects($this->at(1))
            ->method('mirror')
            ->with(self::$DI['cli']['plugin.path'].'/TestPlugin/public', self::$DI['cli']['root.path'].'/www/plugins/TestPlugin');

        $cli['filesystem']->expects($this->at(2))
            ->method('remove')
            ->with('tempdir');

        $cli['plugins.autoloader-generator']->expects($this->once())
            ->method('write')
            ->with([$manifest]);

        $result = $command->execute($input, $output);

        $this->assertSame(0, $result);
    }
}
