<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use InvalidArgumentException;

/**
 * Validates that the set of uploaded multipart part keys matches the set of
 * file reference keys found in the request values.
 *
 * A missing referenced key or an unexpected uploaded key makes the request
 * invalid.
 */
final class PartKeyValidator
{
    /**
     * @param iterable<FileReference|string> $references  Collected references or plain keys.
     * @param iterable<string>               $uploadedKeys Multipart part names.
     *
     * @throws InvalidRequestException When the two key sets differ.
     * @throws InvalidArgumentException When an entry has an unsupported type.
     */
    public function validate(iterable $references, iterable $uploadedKeys): void
    {
        $referencedKeys = $this->normalizeReferencedKeys($references);
        $uploaded = $this->normalizeUploadedKeys($uploadedKeys);

        $missing = array_values(array_diff($referencedKeys, $uploaded));
        $unexpected = array_values(array_diff($uploaded, $referencedKeys));

        if ($missing === [] && $unexpected === []) {
            return;
        }

        throw InvalidRequestException::fromPartKeyMismatch($missing, $unexpected);
    }

    /**
     * @param iterable<FileReference|string> $references
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException When an entry is not a reference or string.
     */
    private function normalizeReferencedKeys(iterable $references): array
    {
        $keys = [];

        foreach ($references as $reference) {
            $key = $reference instanceof FileReference ? $reference->key : $reference;

            if (!is_string($key)) {
                throw new InvalidArgumentException('References must be FileReference objects or string keys.');
            }

            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param iterable<string> $uploadedKeys
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException When an entry is not a string.
     */
    private function normalizeUploadedKeys(iterable $uploadedKeys): array
    {
        $keys = [];

        foreach ($uploadedKeys as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Uploaded part keys must be strings.');
            }

            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
