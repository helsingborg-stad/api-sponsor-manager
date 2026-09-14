<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

/**
 * A single "$file:<key>" marker found inside nested ACF values.
 */
final class FileReference
{
    public function __construct(
        public readonly string $key,
        public readonly string $path,
    ) {
    }
}
