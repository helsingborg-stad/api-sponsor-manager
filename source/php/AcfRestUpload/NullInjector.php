<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use InvalidArgumentException;

/**
 * Forces explicit null values at given bracket paths inside a nested array.
 *
 * This is used after file references have been collected and the original
 * "$file:<key>" markers must be cleared so ACF stores null instead of the
 * marker string.
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
            $segments = BracketPath::parse($path);

            if ($segments === []) {
                continue;
            }

            $this->setNull($values, $segments);
        }

        return $values;
    }

    /**
     * @param array<mixed>         $values
     * @param non-empty-list<int|string> $segments
     */
    private function setNull(array &$values, array $segments): void
    {
        $last = array_pop($segments);
        $node = &$values;

        foreach ($segments as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }

            $node = &$node[$segment];
        }

        $node[$last] = null;
    }
}
