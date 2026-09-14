<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\FileReferenceCollector;
use PHPUnit\Framework\TestCase;

class FileReferenceCollectorTest extends TestCase
{
    public function testCollectsNestedReferencesWithBracketPaths(): void
    {
        $values = [
            'title' => '$file:hero',
            'items' => [
                ['image' => '$file:first', 'caption' => 'plain'],
                ['image' => '$file:second'],
            ],
            'group' => ['icon' => '$file:iconkey'],
        ];

        $collector = new FileReferenceCollector();
        $references = $collector->collect($values);

        self::assertCount(4, $references);
        self::assertSame('hero', $references[0]->key);
        self::assertSame('title', $references[0]->path);
        self::assertSame('first', $references[1]->key);
        self::assertSame('items[0][image]', $references[1]->path);
        self::assertSame('second', $references[2]->key);
        self::assertSame('items[1][image]', $references[2]->path);
        self::assertSame('iconkey', $references[3]->key);
        self::assertSame('group[icon]', $references[3]->path);

        self::assertSame(['hero', 'first', 'second', 'iconkey'], $collector->collectKeys($values));
    }

    public function testCollectsReferencesInsideRootList(): void
    {
        $values = [
            ['image' => '$file:a'],
            ['image' => '$file:b'],
        ];

        $references = (new FileReferenceCollector())->collect($values);

        self::assertSame('[0][image]', $references[0]->path);
        self::assertSame('[1][image]', $references[1]->path);
    }

    public function testCollectsRootMarker(): void
    {
        $references = (new FileReferenceCollector())->collect('$file:solo');

        self::assertCount(1, $references);
        self::assertSame('solo', $references[0]->key);
        self::assertSame('', $references[0]->path);
    }

    public function testIgnoresNonExactMarkers(): void
    {
        $values = [
            'a' => 'prefix $file:x',
            'b' => '$file:',
            'c' => '$file:bad/key',
            'd' => '$file:ok',
        ];

        $references = (new FileReferenceCollector())->collect($values);

        self::assertCount(1, $references);
        self::assertSame('ok', $references[0]->key);
        self::assertSame('d', $references[0]->path);
    }

    public function testCollectKeysDeduplicatesPreservingOrder(): void
    {
        $values = [
            'a' => '$file:shared',
            'b' => ['c' => '$file:shared'],
            'd' => '$file:other',
        ];

        self::assertSame(['shared', 'other'], (new FileReferenceCollector())->collectKeys($values));
    }

    public function testCollectsNothingFromPlainValues(): void
    {
        self::assertSame([], (new FileReferenceCollector())->collect(['a' => 'plain', 'b' => 12]));
    }
}
