<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use InvalidArgumentException;

/**
 * Forces explicit values at given bracket paths inside a nested array.
 *
 * This is used after file references have been collected and the original
 * "$file:<key>" markers must be cleared so ACF stores null instead of the
 * marker string, and to apply explicit "empty list" values from the reserved
 * `_acf_rest_empty[]` part.
 *
 * A null segment (parsed from an empty "[]" bracket) appends at the next
 * numeric position instead of addressing a fixed key. An append-addressed
 * empty list defines the addressed field as [] exactly, so the reserved
 * `_acf_rest_empty[]` entry "acf[field][]" clears the field instead of
 * nesting an empty item.
 */
final class NullInjector
{
    /**
     * Return a copy of the values with null written at every valid path.
     *
     * Missing intermediate arrays are created so the target can always be
     * addressed. An empty path is ignored.
     *
     * @param array<mixed> $values
     * @param list<string> $paths
     *
     * @return array<mixed>
     *
     * @throws InvalidArgumentException When a path is malformed.
     */
    public function inject(array $values, array $paths): array
    {
        foreach ($paths as $path) {
            $segments = BracketPath::parse($path, true);

            if ($segments === []) {
                continue;
            }

            $this->setValue($values, $segments, null);
        }

        return $values;
    }

    /**
     * Return a copy of the values with an empty array written at every path.
     *
     * @param array<mixed> $values
     * @param list<string> $paths
     *
     * @return array<mixed>
     *
     * @throws InvalidArgumentException When a path is malformed.
     */
    public function injectEmptyArrays(array $values, array $paths): array
    {
        foreach ($paths as $path) {
            $segments = BracketPath::parse($path, true);

            if ($segments === []) {
                continue;
            }

            $this->setValue($values, $segments, []);
        }

        return $values;
    }

    /**
     * @param array<mixed>          $values
     * @param list<int|string|null> $segments
     */
    private function setValue(array &$values, array $segments, mixed $value): void
    {
        $last = array_pop($segments);
        $node = &$values;

        foreach ($segments as $segment) {
            if ($segment === null) {
                $segment = self::nextIndex($node);
            }

            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }

            $node = &$node[$segment];
        }

        if ($last === null) {
            // An append-addressed empty list defines the list itself as empty
            // instead of appending one empty item (which would nest "[[]]").
            if (is_array($value) && $value === []) {
                $node = [];

                return;
            }

            $last = self::nextIndex($node);
        }

        $node[$last] = $value;
    }

    /**
     * The next numeric append position of a node.
     *
     * @param array<mixed> $node
     */
    private static function nextIndex(array $node): int
    {
        $keys = array_filter(array_keys($node), 'is_int');

        if ($keys === []) {
            return 0;
        }

        return max($keys) + 1;
    }
}
