<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\NullInjector;
use PHPUnit\Framework\TestCase;

class NullInjectorTest extends TestCase
{
    public function testInjectsNullAtNestedPaths(): void
    {
        $values = [
            'title' => '$file:hero',
            'items' => [
                ['image' => '$file:first', 'caption' => 'keep'],
                ['image' => '$file:second'],
            ],
        ];

        $result = (new NullInjector())->inject($values, [
            'title',
            'items[0][image]',
            'items[1][image]',
        ]);

        self::assertNull($result['title']);
        self::assertNull($result['items'][0]['image']);
        self::assertNull($result['items'][1]['image']);
        self::assertSame('keep', $result['items'][0]['caption']);
    }

    public function testInjectsIntoRootList(): void
    {
        $values = [
            ['image' => '$file:a'],
            ['image' => '$file:b'],
        ];

        $result = (new NullInjector())->inject($values, ['[1][image]']);

        self::assertSame('$file:a', $result[0]['image']);
        self::assertNull($result[1]['image']);
    }

    public function testCreatesMissingIntermediates(): void
    {
        $result = (new NullInjector())->inject([], ['group[sub][0]']);

        self::assertSame(['group' => ['sub' => [0 => null]]], $result);
    }

    public function testReplacesScalarIntermediateWithArray(): void
    {
        $result = (new NullInjector())->inject(['group' => 'scalar'], ['group[sub]']);

        self::assertNull($result['group']['sub']);
    }

    public function testIgnoresEmptyPath(): void
    {
        $result = (new NullInjector())->inject(['a' => 1], ['']);

        self::assertSame(['a' => 1], $result);
    }

    public function testDoesNotMutateTheInputArray(): void
    {
        $values = ['a' => ['b' => 1]];

        (new NullInjector())->inject($values, ['a[b]']);

        self::assertSame(1, $values['a']['b']);
    }
}
