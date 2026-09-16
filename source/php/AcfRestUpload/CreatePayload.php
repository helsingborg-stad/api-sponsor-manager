<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use WP_Error;
use WP_REST_Request;

/** Decode the authoritative payload and associate top-level references with PHP parts. */
final class CreatePayload
{
    public function decode(WP_REST_Request $request): array|WP_Error
    {
        $json = $request->get_body_params()['_acf_rest_payload'] ?? null;
        if (!is_string($json)) { return CreateReceiver::error('invalid_payload', 400, 'Supply a JSON object payload.'); }
        if (strlen($json) > 1_048_576) { return CreateReceiver::error('too_large', 413, 'The payload exceeds 1 MiB.'); }
        if (!is_object(json_decode($json))) {
            return CreateReceiver::error('invalid_payload', 400, 'Supply a valid JSON object payload.');
        }
        $values = json_decode($json, true);
        $references = [];
        $scan = static function (array $values, array $path = []) use (&$scan, &$references): bool {
            foreach ($values as $name => $value) {
                $next = [...$path, $name];
                if (is_array($value) && !$scan($value, $next)) { return false; }
                if (!is_string($value) || !str_starts_with($value, '$file:')) { continue; }
                $key = substr($value, 6);
                if (count($next) !== 2 || $next[0] !== 'acf' || !preg_match('/\A[A-Za-z0-9_-]+\z/D', $key)) {
                    return false;
                }
                $references[$name] = $key;
            }
            return true;
        };
        if (!$scan($values)) { return CreateReceiver::error('invalid_reference', 400, 'Use top-level ACF image references.'); }
        $files = [];
        $parts = $request->get_file_params();
        $keys = array_values(array_unique(array_values($references)));
        if ($keys === [] && $parts === []) { return compact('values', 'references', 'files'); }
        $part = $parts['_acf_rest_files'] ?? null;
        $attributes = ['name', 'type', 'tmp_name', 'error', 'size'];
        if (array_keys($parts) !== ['_acf_rest_files'] || !is_array($part)
            || array_diff(array_keys($part), [...$attributes, 'full_path'])) {
            return CreateReceiver::error('invalid_parts', 400, 'Supply exactly the referenced binary parts.');
        }
        if (array_key_exists('full_path', $part)) { $attributes[] = 'full_path'; }
        foreach ($attributes as $attribute) {
            if (!is_array($part[$attribute] ?? null)
                || array_diff($keys, array_keys($part[$attribute])) || array_diff(array_keys($part[$attribute]), $keys)) {
                return CreateReceiver::error('invalid_parts', 400, 'A binary part is missing or malformed.');
            }
            foreach ($keys as $key) {
                $value = $part[$attribute][$key];
                if (in_array($attribute, ['error', 'size'], true) ? !is_int($value) : !is_string($value)) {
                    return CreateReceiver::error('invalid_parts', 400, 'A binary attribute is malformed.');
                }
                // full_path is checked but never used to choose storage paths.
                if ($attribute !== 'full_path') { $files[$key][$attribute] = $value; }
            }
        }
        return compact('values', 'references', 'files');
    }
}
