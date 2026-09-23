<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use WP_Error;
use WP_REST_Request;

/** Validate native PHP multipart parameter bags without translating them to JSON. */
final class CreatePayload
{
    public function decode(WP_REST_Request $request): array|WP_Error
    {
        $values = $request->get_body_params();
        if (!is_array($values) || isset($values['_acf_rest_payload']) || isset($values['_acf_rest_files'])) {
            return CreateReceiver::error('invalid_payload', 400, 'Supply native multipart fields.');
        }
        if ($this->fieldBytes($values) > 1_048_576) {
            return CreateReceiver::error('too_large', 413, 'Multipart fields exceed 1 MiB.');
        }
        $files = [];
        $parts = $request->get_file_params();
        if ($parts === []) { return compact('values', 'files'); }
        $part = $parts['acf'] ?? null;
        $attributes = ['name', 'type', 'tmp_name', 'error', 'size'];
        if (array_keys($parts) !== ['acf'] || !is_array($part)
            || array_diff(array_keys($part), [...$attributes, 'full_path'])) {
            return CreateReceiver::error('invalid_parts', 400, 'Supply only top-level ACF image parts.');
        }
        if (array_key_exists('full_path', $part)) { $attributes[] = 'full_path'; }
        if (!is_array($part['name'] ?? null)) {
            return CreateReceiver::error('invalid_parts', 400, 'An image part is malformed.');
        }
        $names = array_keys($part['name']);
        if ($names === [] || array_filter($names, static fn ($name) => !is_string($name) || $name === '')) {
            return CreateReceiver::error('invalid_parts', 400, 'An image part is malformed.');
        }
        foreach ($attributes as $attribute) {
            if (!is_array($part[$attribute] ?? null)
                || array_diff($names, array_keys($part[$attribute])) || array_diff(array_keys($part[$attribute]), $names)) {
                return CreateReceiver::error('invalid_parts', 400, 'A binary part is missing or malformed.');
            }
            foreach ($names as $name) {
                $value = $part[$attribute][$name];
                if (in_array($attribute, ['error', 'size'], true) ? !is_int($value) : !is_string($value)) {
                    return CreateReceiver::error('invalid_parts', 400, 'A binary attribute is malformed.');
                }
                if ($attribute !== 'full_path') { $files[$name][$attribute] = $value; }
            }
        }
        if (array_key_exists('acf', $values) && !is_array($values['acf'])) {
            return CreateReceiver::error('invalid_payload', 400, 'ACF fields must use an object bag.');
        }
        if (is_array($values['acf'] ?? null)) {
            foreach ($names as $name) {
                if (array_key_exists($name, $values['acf'])) {
                    return CreateReceiver::error('ambiguous_image', 400, 'An image cannot have both a value and an upload.');
                }
            }
        }
        return compact('values', 'files');
    }

    private function fieldBytes(mixed $value): int
    {
        if (is_array($value)) {
            return array_sum(array_map($this->fieldBytes(...), $value));
        }
        return is_scalar($value) ? strlen((string) $value) : 0;
    }
}
