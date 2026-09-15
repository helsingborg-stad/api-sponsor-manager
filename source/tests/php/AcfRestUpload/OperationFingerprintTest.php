<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test\AcfRestUpload;

use ApiSponsorManager\AcfRestUpload\OperationFingerprint;
use PHPUnit\Framework\TestCase;

class OperationFingerprintTest extends TestCase
{
    public function testUploadErrorsRetainServerFailureAndSizeStatuses(): void
    {
        foreach ([UPLOAD_ERR_CANT_WRITE => 500, UPLOAD_ERR_NO_TMP_DIR => 500, UPLOAD_ERR_EXTENSION => 500, UPLOAD_ERR_INI_SIZE => 413, UPLOAD_ERR_FORM_SIZE => 413, UPLOAD_ERR_PARTIAL => 400] as $error => $status) {
            $result = OperationFingerprint::calculate([], ['file' => ['tmp_name' => '', 'error' => $error]]);
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame($status, $result->get_error_data()['status']);
        }
    }

    public function testAggregateFilesAndStructuredDataHaveExplicitLimits(): void
    {
        $paths = [tempnam(sys_get_temp_dir(), 'limit-'), tempnam(sys_get_temp_dir(), 'limit-')];
        try {
            $files = [];
            foreach ($paths as $index => $path) {
                $handle = fopen($path, 'wb');
                ftruncate($handle, 4 * 1024 * 1024 + 1);
                fclose($handle);
                $files[$index] = ['name' => 'image.jpg', 'type' => 'image/jpeg', 'error' => UPLOAD_ERR_OK, 'tmp_name' => $path];
            }
            $result = OperationFingerprint::calculate([], $files);
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame(413, $result->get_error_data()['status']);
            $result = OperationFingerprint::calculate(['text' => str_repeat('x', 1024 * 1024 + 1)], []);
            self::assertInstanceOf(\WP_Error::class, $result);
            self::assertSame(413, $result->get_error_data()['status']);
        } finally {
            foreach ($paths as $path) { unlink($path); }
        }
    }

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
