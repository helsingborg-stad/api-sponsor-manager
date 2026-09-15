<?php

// Test-only loopback endpoint. PHP, not a fixture parser, decodes the multipart body.
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit;
}
$files = $_FILES;
foreach ($files as &$file) {
    foreach (($file['tmp_name'] ?? []) as $key => $path) {
        $file['uploaded'][$key] = is_uploaded_file($path);
        $file['content'][$key] = base64_encode(file_get_contents($path));
    }
}
header('Content-Type: application/json');
echo json_encode(['params' => $_POST, 'files' => $files], JSON_THROW_ON_ERROR);
