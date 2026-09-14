<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use InvalidArgumentException;

/**
 * Parses and formats bracket paths that address nested ACF values.
 *
 * Examples:
 *   "title"                -> ["title"]
 *   "group[sub]"           -> ["group", "sub"]
 *   "items[0][image]"      -> ["items", 0, "image"]
 *   "[0][image]"           -> [0, "image"]
 *
 * Only canonical non-negative integers are converted to int segments, so
 * keys such as "01" stay strings and round-trip unchanged.
 */
final class BracketPath
{
    private function __construct()
    {
    }

    /**
     * Parse a bracket path into its segments.
     *
     * @return list<int|string>
     *
     * @throws InvalidArgumentException When the path is malformed.
     */
    public static function parse(string $path): array
    {
        if ($path === '') {
            return [];
        }

        $bracket = strpos($path, '[');

        if ($bracket === false) {
            if (str_contains($path, ']')) {
                throw new InvalidArgumentException(sprintf('Malformed bracket path "%s".', $path));
            }

            return [$path];
        }

        $segments = [];

        if ($bracket > 0) {
            $root = substr($path, 0, $bracket);

            if (str_contains($root, ']')) {
                throw new InvalidArgumentException(sprintf('Malformed bracket path "%s".', $path));
            }

            $segments[] = $root;
        }

        $offset = $bracket;
        $length = strlen($path);

        while ($offset < $length) {
            if ($path[$offset] !== '[') {
                throw new InvalidArgumentException(sprintf('Malformed bracket path "%s".', $path));
            }

            $closing = strpos($path, ']', $offset);

            if ($closing === false) {
                throw new InvalidArgumentException(sprintf('Unclosed bracket in path "%s".', $path));
            }

            $segment = substr($path, $offset + 1, $closing - $offset - 1);

            if ($segment === '' || str_contains($segment, '[')) {
                throw new InvalidArgumentException(sprintf('Malformed bracket path "%s".', $path));
            }

            $segments[] = self::normalizeSegment($segment);
            $offset = $closing + 1;
        }

        return $segments;
    }

    /**
     * Build a bracket path from its segments.
     *
     * The first string segment is used as the root. Every other segment is
     * wrapped in brackets.
     *
     * @param list<int|string> $segments
     *
     * @throws InvalidArgumentException When a segment has an unsupported type.
     */
    public static function format(array $segments): string
    {
        $path = '';
        $index = 0;

        foreach ($segments as $segment) {
            if (!is_int($segment) && !is_string($segment)) {
                throw new InvalidArgumentException('Path segments must be integers or strings.');
            }

            if ($index === 0 && is_string($segment)) {
                $path = $segment;
                $index++;

                continue;
            }

            $path .= '[' . $segment . ']';
            $index++;
        }

        return $path;
    }

    /**
     * Append a segment to an existing bracket path.
     */
    public static function append(string $path, int|string $segment): string
    {
        if ($path === '') {
            return is_string($segment) ? $segment : '[' . $segment . ']';
        }

        return $path . '[' . $segment . ']';
    }

    /**
     * Convert a canonical numeric segment to int, keep everything else as string.
     */
    private static function normalizeSegment(string $segment): int|string
    {
        if (preg_match('/^\d+$/', $segment) === 1 && (string) (int) $segment === $segment) {
            return (int) $segment;
        }

        return $segment;
    }
}
