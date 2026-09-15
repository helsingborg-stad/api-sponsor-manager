<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use WP_Error;

/** Hash operation input, excluding transient file paths and response controls. */
final class OperationFingerprint
{
    public const MAX_FILE_BYTES = 8 * 1024 * 1024;
    public const MAX_DATA_BYTES = 1024 * 1024;

    public static function calculate(array $params, array $files): string|WP_Error
    {
        foreach (['id', 'context', '_fields', '_embed', '_wpnonce'] as $control) {
            unset($params[$control]);
        }
        try {
            if (strlen(json_encode($params, JSON_THROW_ON_ERROR)) > self::MAX_DATA_BYTES) {
                return self::limitError();
            }
        } catch (\JsonException) {
            return new WP_Error('acf_rest_upload_invalid_request', 'The operation data cannot be encoded.', ['status' => 400]);
        }
        $uploads = [];
        $totalBytes = 0;
        foreach ($files as $key => $file) {
            $path = $file['tmp_name'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) { return self::limitError(); }
                $status = in_array($file['error'], [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true) ? 500 : 400;
                return new WP_Error('acf_rest_upload_failed', 'Upload failed before the file was received.', ['status' => $status]);
            }
            if (!is_readable($path)) {
                return new WP_Error('acf_rest_upload_failed', 'The server cannot read an uploaded file.', ['status' => 500]);
            }
            $size = filesize($path);
            if ($size === false) {
                return new WP_Error('acf_rest_upload_failed', 'The server cannot inspect an uploaded file.', ['status' => 500]);
            }
            if ($size === 0) { return new WP_Error('acf_rest_upload_invalid_file', 'An uploaded file is empty.', ['status' => 400]); }
            $totalBytes += $size;
            if ($totalBytes > self::MAX_FILE_BYTES) { return self::limitError(); }
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                return new WP_Error('acf_rest_upload_failed', 'The server cannot read an uploaded file.', ['status' => 500]);
            }
            $uploads[$key] = ['name' => $file['name'], 'type' => $file['type'], 'sha256' => $hash];
        }
        try {
            return hash('sha256', json_encode(self::canonicalize(['params' => $params, 'files' => $uploads]), JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return new WP_Error('acf_rest_upload_invalid_request', 'The operation data cannot be encoded.', ['status' => 400]);
        }
    }

    private static function limitError(): WP_Error
    {
        return new WP_Error('acf_rest_upload_too_large', 'Multipart operations support at most 8 MiB of files and 1 MiB of structured data.', ['status' => 413]);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        ksort($value, SORT_STRING);
        return array_map(self::canonicalize(...), $value);
    }
}
