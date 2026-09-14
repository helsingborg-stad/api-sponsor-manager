<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\FileReference;
use ApiSponsorManager\AcfRestUpload\FileReferenceCollector;
use ApiSponsorManager\AcfRestUpload\InvalidRequestException;
use ApiSponsorManager\AcfRestUpload\NullInjector;
use ApiSponsorManager\AcfRestUpload\PartKeyValidator;
use ApiSponsorManager\AcfRestUpload\ProtocolV1;
use PHPUnit\Framework\TestCase;

class PartKeyValidatorTest extends TestCase
{
    public function testPassesWhenKeySetsMatch(): void
    {
        $references = [
            new FileReference('a', 'x'),
            new FileReference('b', 'y'),
        ];

        (new PartKeyValidator())->validate($references, ['a', 'b']);

        self::assertTrue(true);
    }

    public function testPassesWithPlainStringReferencesInAnyOrder(): void
    {
        (new PartKeyValidator())->validate(['a', 'b'], ['b', 'a']);

        self::assertTrue(true);
    }

    public function testPassesWhenBothSidesAreEmpty(): void
    {
        (new PartKeyValidator())->validate([], []);

        self::assertTrue(true);
    }

    public function testThrowsForMissingKeysWithProtocolCode(): void
    {
        try {
            (new PartKeyValidator())->validate(['a', 'b'], ['a']);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $exception) {
            self::assertSame('acf_rest_upload_invalid_request', $exception->getCode());
            self::assertSame(ProtocolV1::INVALID_REQUEST_CODE, $exception->getCode());
            self::assertSame(InvalidRequestException::CODE, $exception->getErrorCode());
            self::assertStringContainsString('b', $exception->getMessage());
            self::assertStringContainsString('missing', $exception->getMessage());
        }
    }

    public function testThrowsForUnexpectedKeysWithProtocolCode(): void
    {
        try {
            (new PartKeyValidator())->validate(['a'], ['a', 'extra']);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $exception) {
            self::assertSame('acf_rest_upload_invalid_request', $exception->getCode());
            self::assertStringContainsString('extra', $exception->getMessage());
            self::assertStringContainsString('unexpected', $exception->getMessage());
        }
    }

    public function testThrowsForMissingAndUnexpectedKeys(): void
    {
        try {
            (new PartKeyValidator())->validate(['a', 'b'], ['a', 'c']);
            self::fail('Expected InvalidRequestException.');
        } catch (InvalidRequestException $exception) {
            self::assertStringContainsString('b', $exception->getMessage());
            self::assertStringContainsString('c', $exception->getMessage());
        }
    }

    public function testIntegratesCollectorOutputWithValidator(): void
    {
        $values = [
            'cover' => '$file:coverKey',
            'gallery' => [
                ['image' => '$file:galleryA'],
                ['image' => '$file:galleryB'],
            ],
        ];

        $collector = new FileReferenceCollector();
        $references = $collector->collect($values);

        (new PartKeyValidator())->validate($references, ['coverKey', 'galleryA', 'galleryB']);

        $result = (new NullInjector())->inject(
            $values,
            array_map(static fn(FileReference $reference): string => $reference->path, $references)
        );

        self::assertNull($result['cover']);
        self::assertNull($result['gallery'][0]['image']);
        self::assertNull($result['gallery'][1]['image']);
    }
}
