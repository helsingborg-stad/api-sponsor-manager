<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use InvalidArgumentException;

/**
 * Protocol v1 marker format for ACF REST file/image uploads.
 *
 * A file is attached to a nested ACF value by replacing the value with the
 * exact string "$file:<key>". The matching binary is sent as a multipart
 * part whose name is the same <key>.
 *
 * The marker is recognized only when the complete string matches, so values
 * such as "prefix $file:key" or "$file:key " are treated as plain values.
 */
final class ProtocolV1
{
    /**
     * Prefix that introduces a file reference marker.
     */
    public const PREFIX = '$file:';

    /**
     * Upload keys must be non-empty and contain only part-safe characters.
     */
    public const KEY_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /**
     * Error code carried by {@see InvalidRequestException}.
     */
    public const INVALID_REQUEST_CODE = 'acf_rest_upload_invalid_request';

    /**
     * Matches a complete file reference marker.
     */
    private const REFERENCE_PATTERN = '/^\$file:([A-Za-z0-9_-]+)$/';

    private function __construct()
    {
    }

    /**
     * Whether the value is exactly a file reference marker.
     */
    public static function isReference(mixed $value): bool
    {
        return is_string($value) && preg_match(self::REFERENCE_PATTERN, $value) === 1;
    }

    /**
     * Extract the upload key from a marker.
     *
     * Returns null when the value is not a valid marker.
     */
    public static function keyFromReference(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        if (preg_match(self::REFERENCE_PATTERN, $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Build a marker from an upload key.
     *
     * @throws InvalidArgumentException When the key is empty or unsafe.
     */
    public static function reference(string $key): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Invalid upload key "%s". Keys must match %s.', $key, self::KEY_PATTERN)
            );
        }

        return self::PREFIX . $key;
    }
}
