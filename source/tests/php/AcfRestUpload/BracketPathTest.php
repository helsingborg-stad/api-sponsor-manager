<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\BracketPath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class BracketPathTest extends TestCase
{
    public function testParsesRootSegment(): void
    {
        self::assertSame(['title'], BracketPath::parse('title'));
    }

    public function testParsesNestedPath(): void
    {
        self::assertSame(['items', 0, 'image'], BracketPath::parse('items[0][image]'));
    }

    public function testParsesRootListPath(): void
    {
        self::assertSame([0, 'image'], BracketPath::parse('[0][image]'));
    }

    public function testParsesGroupSubFieldPath(): void
    {
        self::assertSame(['group', 'sub'], BracketPath::parse('group[sub]'));
    }

    public function testKeepsNonCanonicalNumericKeysAsStrings(): void
    {
        self::assertSame(['items', '01'], BracketPath::parse('items[01]'));
    }

    public function testEmptyPathParsesToNoSegments(): void
    {
        self::assertSame([], BracketPath::parse(''));
    }

    public function testFormatsSegments(): void
    {
        self::assertSame('title', BracketPath::format(['title']));
        self::assertSame('items[0][image]', BracketPath::format(['items', 0, 'image']));
        self::assertSame('[0][image]', BracketPath::format([0, 'image']));
        self::assertSame('group[sub]', BracketPath::format(['group', 'sub']));
        self::assertSame('', BracketPath::format([]));
    }

    public function testAppendsSegments(): void
    {
        self::assertSame('items', BracketPath::append('', 'items'));
        self::assertSame('[0]', BracketPath::append('', 0));
        self::assertSame('items[0]', BracketPath::append('items', 0));
        self::assertSame('items[0][image]', BracketPath::append('items[0]', 'image'));
        self::assertSame('[0][image]', BracketPath::append('[0]', 'image'));
    }

    public function testParseAndFormatRoundTrip(): void
    {
        $paths = ['title', 'group[sub]', 'items[0][image]', '[0][image]', 'items[01]'];

        foreach ($paths as $path) {
            self::assertSame($path, BracketPath::format(BracketPath::parse($path)));
        }
    }

    public function testRejectsMalformedPaths(): void
    {
        $malformed = ['items[', 'items[]', 'items[a]b', 'items[[a]', 'a]b', '[', '[]'];

        foreach ($malformed as $path) {
            try {
                BracketPath::parse($path);
                self::fail(sprintf('Expected path "%s" to be rejected.', $path));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
