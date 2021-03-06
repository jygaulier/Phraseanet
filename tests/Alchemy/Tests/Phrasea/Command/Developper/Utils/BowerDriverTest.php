<?php

namespace Alchemy\Tests\Phrasea\Command\Developper\Utils;

use Alchemy\Phrasea\Command\Developer\Utils\BowerDriver;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * @group functional
 * @group legacy
 */
class BowerDriverTest extends \PhraseanetTestCase
{
    public function testCreate()
    {
        $driver = BowerDriver::create();
        $this->assertInstanceOf('Alchemy\Phrasea\Command\Developer\Utils\BowerDriver', $driver);
        $this->assertEquals('bower', $driver->getName());
    }

    public function testCreateWithCustomBinary()
    {
        $finder = new PhpExecutableFinder();
        $php = $finder->find();

        $driver = BowerDriver::create(['bower.binaries' => $php]);
        $this->assertInstanceOf('Alchemy\Phrasea\Command\Developer\Utils\BowerDriver', $driver);
        $this->assertEquals($php, $driver->getProcessBuilderFactory()->getBinary());
    }
}
