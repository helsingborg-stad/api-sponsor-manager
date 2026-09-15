<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use WP_Error;
use WP_REST_Request;
use WpService\WpService;

/** Validate actual image bytes before media storage. */
final class CreateImage
{
    public function __construct(private WpService $wpService) {}

    public function validate(array &$file, array $field, WP_REST_Request $request): ?WP_Error
    {
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return CreateReceiver::error('too_large', 413, 'The uploaded image exceeds the size limit.');
        }
        if (in_array($file['error'], [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)) {
            return CreateReceiver::error('storage_failed', 500, 'The server could not receive the uploaded image.');
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name'])
            || !is_file($file['tmp_name']) || !is_readable($file['tmp_name'])) {
            return CreateReceiver::error('invalid_file', 400, 'The uploaded image is unavailable.');
        }
        $file['size'] = filesize($file['tmp_name']);
        if (!$file['size']) { return CreateReceiver::error('invalid_file', 400, 'The uploaded image is empty.'); }
        if ($file['size'] > min(8_388_608, $this->wpService->wpMaxUploadSize())) {
            return CreateReceiver::error('too_large', 413, 'The uploaded image exceeds the size limit.');
        }
        $type = $this->wpService->wpCheckFiletypeAndExt($file['tmp_name'], (string) $file['name']);
        $dimensions = $this->wpService->wpGetimagesize($file['tmp_name']);
        if (!$dimensions || !($type['ext'] ?? false) || !str_starts_with((string) $type['type'], 'image/')
            || $dimensions['mime'] !== $type['type']) {
            return CreateReceiver::error('invalid_file_type', 415, 'The binary part must contain an allowed image.');
        }
        $limits = array_intersect_key($field, array_flip([
            'min_size', 'max_size', 'min_width', 'max_width', 'min_height', 'max_height', 'mime_types',
        ]));
        $policy = $this->wpService->applyFilters('AcfRestUpload/imagePolicy', $limits, $field, $request);
        if (!is_array($policy)) {
            return CreateReceiver::error('invalid_policy', 500, 'The image policy must return field-limit settings.');
        }
        // Validate both policies: a filter can tighten but cannot remove native restrictions.
        foreach ([$limits, $policy] as $settings) {
            $extensions = array_filter(array_map('trim', explode(',', (string) ($settings['mime_types'] ?? ''))));
            if ($extensions && !in_array($type['ext'], $extensions, true)) {
                return CreateReceiver::error('invalid_file_type', 415, 'The image type is not permitted by the field.');
            }
            foreach (['size' => $file['size'] / 1_048_576, 'width' => $dimensions[0], 'height' => $dimensions[1]] as $name => $value) {
                if (($settings['max_' . $name] ?? false) && $value > (float) $settings['max_' . $name]) {
                    return CreateReceiver::error('too_large', 413, 'The image exceeds the field limit.');
                }
                if (($settings['min_' . $name] ?? false) && $value < (float) $settings['min_' . $name]) {
                    return CreateReceiver::error('invalid_file', 400, 'The image is below the field minimum.');
                }
            }
        }
        return null;
    }

    public function sideload(array $file): int|WP_Error
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $moved = [];
        $track = static function ($move, array $upload, string $destination) use ($file, &$moved) {
            if ($move === null && ($upload['tmp_name'] ?? null) === $file['tmp_name'] && !file_exists($destination)) {
                $moved[] = $destination;
            }
            return $move;
        };
        $this->wpService->addFilter('pre_move_uploaded_file', $track, PHP_INT_MAX, 3);
        try {
            $result = $this->wpService->mediaHandleSideload($file, 0);
        } finally {
            $this->wpService->removeFilter('pre_move_uploaded_file', $track, PHP_INT_MAX);
        }
        if ($result instanceof WP_Error || $result <= 0) {
            foreach ($moved as $path) {
                if (is_file($path) && !unlink($path)) {
                    return CreateReceiver::error('cleanup_failed', 500, 'Could not delete the failed image upload.');
                }
            }
            return CreateReceiver::error('storage_failed', 500, 'Could not store the uploaded image.');
        }
        return $result;
    }
}
