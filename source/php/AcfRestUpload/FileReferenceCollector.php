<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

/**
 * Recursively walks nested ACF values and collects every file reference.
 *
 * References are reported in depth-first order together with their bracket
 * path, for example "items[0][image]".
 */
final class FileReferenceCollector
{
    /**
     * Collect all file references found in the value.
     *
     * @return list<FileReference>
     */
    public function collect(mixed $value): array
    {
        $references = [];
        $this->walk($value, '', $references);

        return $references;
    }

    /**
     * Collect the unique referenced keys in first-seen order.
     *
     * @return list<string>
     */
    public function collectKeys(mixed $value): array
    {
        $keys = [];

        foreach ($this->collect($value) as $reference) {
            if (!in_array($reference->key, $keys, true)) {
                $keys[] = $reference->key;
            }
        }

        return $keys;
    }

    /**
     * @param FileReference[] $references
     */
    private function walk(mixed $value, string $path, array &$references): void
    {
        if (!is_array($value)) {
            $key = ProtocolV1::keyFromReference($value);

            if ($key !== null) {
                $references[] = new FileReference($key, $path);
            }

            return;
        }

        foreach ($value as $segment => $child) {
            $childPath = BracketPath::append($path, is_int($segment) ? $segment : (string) $segment);
            $this->walk($child, $childPath, $references);
        }
    }
}
