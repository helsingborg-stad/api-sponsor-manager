<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\OperationFingerprint;
use PHPUnit\Framework\TestCase;

class OperationFingerprintTest extends TestCase
{
    public function testMapOrderingAndResponseControlsDoNotChangeAnOperation(): void
    {
        $first = OperationFingerprint::calculate(['title' => 'Saved', 'acf' => ['b' => 2, 'a' => 1]], []);
        $retry = OperationFingerprint::calculate(['context' => 'edit', '_fields' => 'id', 'acf' => ['a' => 1, 'b' => 2], 'title' => 'Saved'], []);
        self::assertSame($first, $retry);
        self::assertNotSame($first, OperationFingerprint::calculate(['title' => 'Changed', 'acf' => ['a' => 1, 'b' => 2]], []));
        self::assertNotSame(OperationFingerprint::calculate(['acf' => ['list' => []]], []), OperationFingerprint::calculate(['acf' => ['list' => null]], []));
    }

    public function testFileBytesAndMetadataAreBoundButTemporaryPathsAreNot(): void
    {
        $first = tempnam(sys_get_temp_dir(), 'fingerprint-');
        $second = tempnam(sys_get_temp_dir(), 'fingerprint-');
        try {
            file_put_contents($first, "binary\0bytes");
            file_put_contents($second, "binary\0bytes");
            $file = ['name' => 'image.jpg', 'type' => 'image/jpeg', 'error' => UPLOAD_ERR_OK, 'tmp_name' => $first];
            $hash = OperationFingerprint::calculate([], ['hero' => $file]);
            $file['tmp_name'] = $second;
            self::assertSame($hash, OperationFingerprint::calculate([], ['hero' => $file]));
            $file['name'] = 'renamed.jpg';
            self::assertNotSame($hash, OperationFingerprint::calculate([], ['hero' => $file]));
            $file['name'] = 'image.jpg';
            file_put_contents($second, 'different');
            self::assertNotSame($hash, OperationFingerprint::calculate([], ['hero' => $file]));
        } finally {
            unlink($first);
            unlink($second);
        }
    }
}
