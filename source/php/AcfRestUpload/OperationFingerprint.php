<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use WP_Error;

/** Hash operation input, excluding transient file paths and response controls. */
final class OperationFingerprint
{
    public static function calculate(array $params, array $files): string|WP_Error
    {
        foreach (['id', 'context', '_fields', '_embed', '_wpnonce'] as $control) {
            unset($params[$control]);
        }
        $uploads = [];
        foreach ($files as $key => $file) {
            $path = $file['tmp_name'];
            if ($file['error'] !== UPLOAD_ERR_OK || !is_readable($path)) {
                return new WP_Error('acf_rest_upload_invalid_file', 'An uploaded file cannot be read.', ['status' => 400]);
            }
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                return new WP_Error('acf_rest_upload_invalid_file', 'An uploaded file cannot be read.', ['status' => 400]);
            }
            $uploads[$key] = ['name' => $file['name'], 'type' => $file['type'], 'sha256' => $hash];
        }
        try {
            return hash('sha256', json_encode(self::canonicalize(['params' => $params, 'files' => $uploads]), JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return new WP_Error('acf_rest_upload_invalid_request', 'The operation data cannot be encoded.', ['status' => 400]);
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) { return $value; }
        ksort($value, SORT_STRING);
        return array_map(self::canonicalize(...), $value);
    }
}
