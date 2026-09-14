<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\ProtocolV1;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ProtocolV1Test extends TestCase
{
    public function testRecognizesExactReference(): void
    {
        self::assertTrue(ProtocolV1::isReference('$file:image_1'));
        self::assertSame('image_1', ProtocolV1::keyFromReference('$file:image_1'));
        self::assertTrue(ProtocolV1::isReference('$file:a-b_c-123'));
    }

    public function testRejectsNonExactOrUnsafeReferences(): void
    {
        self::assertFalse(ProtocolV1::isReference('$file:'));
        self::assertFalse(ProtocolV1::isReference('$file:a b'));
        self::assertFalse(ProtocolV1::isReference('$file:a/b'));
        self::assertFalse(ProtocolV1::isReference('$file:a]b'));
        self::assertFalse(ProtocolV1::isReference(' $file:a'));
        self::assertFalse(ProtocolV1::isReference('$file:a '));
        self::assertFalse(ProtocolV1::isReference('prefix $file:a'));
        self::assertFalse(ProtocolV1::isReference('$file:a[0]'));
        self::assertFalse(ProtocolV1::isReference(123));
        self::assertFalse(ProtocolV1::isReference(['$file:a']));

        self::assertNull(ProtocolV1::keyFromReference('$file:'));
        self::assertNull(ProtocolV1::keyFromReference('prefix $file:a'));
    }

    public function testBuildsReference(): void
    {
        self::assertSame('$file:hero', ProtocolV1::reference('hero'));
    }

    public function testRejectsUnsafeKeyWhenBuildingReference(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProtocolV1::reference('bad key');
    }

    public function testRejectsEmptyKeyWhenBuildingReference(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ProtocolV1::reference('');
    }
}
