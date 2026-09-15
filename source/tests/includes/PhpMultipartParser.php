<?php

declare(strict_types=1);

namespace ApiSponsorManager\Test;

/** Round-trip a sender body through PHP's native HTTP multipart parser. */
final class PhpMultipartParser
{
    public static function parse(array $encoded): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) { throw new \RuntimeException($error); }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $log = tempnam(sys_get_temp_dir(), 'sponsor-http-');
        $process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/multipart-parser.php'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, __DIR__);
        if (!is_resource($process)) { throw new \RuntimeException('Could not start the isolated multipart parser.'); }
        fclose($pipes[0]);
        $curl = curl_init('http://' . $address . '/');
        try {
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 5]);
            $ready = false;
            for ($attempt = 0; $attempt < 40; $attempt++) {
                if (curl_exec($curl) !== false) { $ready = true; break; }
                usleep(50000);
            }
            if (!$ready) { throw new \RuntimeException('Parser connection failed: ' . curl_error($curl) . '\n' . file_get_contents($log)); }
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $encoded['body'], CURLOPT_HTTPHEADER => ['Content-Type: ' . $encoded['contentType']]]);
            $response = curl_exec($curl);
            if ($response === false || curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200) {
                throw new \RuntimeException('Multipart HTTP submission failed: ' . curl_error($curl));
            }
            return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        } finally {
            curl_close($curl);
            proc_terminate($process);
            proc_close($process);
            unlink($log);
        }
    }
}
