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

    public function testAppendsNullAtNextNumericPosition(): void
    {
        $result = (new NullInjector())->inject(['gallery' => [456]], ['acf[gallery][]']);

        self::assertSame(['gallery' => [456, null]], $result);
    }

    public function testAppendsNullIntoEmptyNode(): void
    {
        $result = (new NullInjector())->inject([], ['acf[gallery][]']);

        self::assertSame(['gallery' => [0 => null]], $result);
    }

    public function testInjectsExplicitRootedNullPaths(): void
    {
        $result = (new NullInjector())->inject(['image' => '$file:hero'], ['acf[image]']);

        self::assertNull($result['image']);
    }

    public function testInjectsEmptyArrays(): void
    {
        $result = (new NullInjector())->injectEmptyArrays(
            ['gallery' => [1, 2], 'other' => 'keep'],
            ['acf[gallery]', 'acf[fresh][]']
        );

        self::assertSame([], $result['gallery']);
        self::assertSame('keep', $result['other']);
        self::assertSame([], $result['fresh']);
    }

    public function testAppendAddressedEmptyListDefinesTheListAsEmpty(): void
    {
        // acf[fresh][] with an empty list value must set fresh to [] exactly,
        // never append one empty item (which would nest "[[]]").
        $result = (new NullInjector())->injectEmptyArrays([], ['acf[fresh][]']);
        self::assertSame(['fresh' => []], $result);

        $cleared = (new NullInjector())->injectEmptyArrays(['fresh' => [1, 2]], ['acf[fresh][]']);
        self::assertSame(['fresh' => []], $cleared);
    }

    public function testAppendAddressedNullStillAppendsAtNextPosition(): void
    {
        $result = (new NullInjector())->inject(['fresh' => [456]], ['acf[fresh][]']);

        self::assertSame(['fresh' => [456, null]], $result);
    }

    public function testDoesNotMutateInputWhenInjectingEmptyArrays(): void
    {
        $values = ['gallery' => [1]];

        (new NullInjector())->injectEmptyArrays($values, ['acf[gallery]']);

        self::assertSame([1], $values['gallery']);
    }
}
