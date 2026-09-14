<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use RuntimeException;

/**
 * Raised when the multipart file parts do not match the file references
 * found in the request values.
 *
 * The exception code is the string "acf_rest_upload_invalid_request" so a
 * REST layer can translate it into a client error without depending on the
 * framework-independent internals.
 */
class InvalidRequestException extends RuntimeException
{
    /**
     * Error code carried by this exception.
     */
    public const CODE = ProtocolV1::INVALID_REQUEST_CODE;

    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->code = self::CODE;
    }

    /**
     * Build an exception describing a mismatch between referenced and uploaded keys.
     *
     * @param list<string> $missing    Referenced keys without an uploaded part.
     * @param list<string> $unexpected Uploaded keys without a reference.
     */
    public static function fromPartKeyMismatch(array $missing, array $unexpected): self
    {
        $details = [];

        if ($missing !== []) {
            $details[] = 'missing file parts for keys: ' . implode(', ', $missing);
        }

        if ($unexpected !== []) {
            $details[] = 'unexpected file parts for keys: ' . implode(', ', $unexpected);
        }

        return new self('Invalid ACF REST upload request: ' . implode('; ', $details) . '.');
    }

    /**
     * Explicit accessor for the string protocol code.
     */
    public function getErrorCode(): string
    {
        return self::CODE;
    }
}
