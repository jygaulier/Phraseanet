<?php

namespace Alchemy\Tests\Phrasea\Metadata\Tag;

use Alchemy\Phrasea\Metadata\Tag\TfDuration;

/**
 * @group functional
 * @group legacy
 */
class TfDurationTest extends \PhraseanetTestCase
{

    /**
     * @covers Alchemy\Phrasea\Metadata\Tag\TfDuration
     */
    public function testObject()
    {
        $object = new TfDuration();

        $this->assertInstanceOf('\\PHPExiftool\\Driver\\TagInterface', $object);
        $this->assertInternalType('string', $object->getDescription());
        $this->assertInternalType('string', $object->getGroupName());
        $this->assertInternalType('string', $object->getId());
        $this->assertInternalType('string', $object->getName());
        $this->assertEquals(0, strpos('Phraseanet:', $object->getTagname()));
    }
}
