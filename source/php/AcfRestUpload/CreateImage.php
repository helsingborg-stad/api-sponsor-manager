<?php

declare(strict_types=1);

namespace ApiSponsorManager\AcfRestUpload;

use WP_Error;
use WpService\WpService;

/** Validate actual image bytes before media storage. */
final class CreateImage
{
    public function __construct(private WpService $wpService) {}

    public function validate(array &$file, array $field): ?WP_Error
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
        $extensions = array_filter(array_map('trim', explode(',', (string) ($field['mime_types'] ?? ''))));
        if ($extensions && !in_array($type['ext'], $extensions, true)) {
            return CreateReceiver::error('invalid_file_type', 415, 'The image type is not permitted by the field.');
        }
        foreach (['size' => $file['size'] / 1_048_576, 'width' => $dimensions[0], 'height' => $dimensions[1]] as $name => $value) {
            if (($field['max_' . $name] ?? false) && $value > (float) $field['max_' . $name]) {
                return CreateReceiver::error('too_large', 413, 'The image exceeds the field limit.');
            }
            if (($field['min_' . $name] ?? false) && $value < (float) $field['min_' . $name]) {
                return CreateReceiver::error('invalid_file', 400, 'The image is below the field minimum.');
            }
        }
        return null;
    }

    public function sideload(array $file, array $postData): int|WP_Error
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        // Temporary owner metadata identifies this request's uploads before native metadata generation.
        $result = $this->wpService->mediaHandleSideload($file, 0, null, $postData);
        if ($result instanceof WP_Error || $result <= 0) {
            return CreateReceiver::error('storage_failed', 500, 'Could not store the uploaded image.');
        }
        return $result;
    }
}
