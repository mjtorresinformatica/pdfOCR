<?php
/**
 * POST /api/upload.php
 * Body: multipart/form-data { file: <pdf>, language: "spa", token: <session token> }
 * Returns: { ok, job: { id, file_name, size_bytes, sha256, language } }
 */

declare(strict_types=1);

use PdfOcr\Http;
use PdfOcr\JobRepository;
use PdfOcr\Uploader;

require __DIR__ . '/bootstrap.php';

$runEndpoint(static function () use ($config, $connect): void {
    Http::requireMethod('POST');
    Http::requireSameOrigin();
    Http::checkToken('pdfocr_token', $_POST['token'] ?? null);

    if (!isset($_FILES['file'])) {
        Http::fail('No file was received. Check upload_max_filesize and post_max_size in php.ini.', 400);
    }

    $language = (string) ($_POST['language'] ?? $config['ocr']['default_language']);
    if (!array_key_exists($language, $config['ocr']['languages'])) {
        $language = (string) $config['ocr']['default_language'];
    }

    $uploader = new Uploader($config['upload'], (string) $config['paths']['uploads']);

    try {
        $stored = $uploader->store($_FILES['file']);
    } catch (RuntimeException $e) {
        Http::fail($e->getMessage(), 422);
    }

    $pdo = $connect();
    $jobs = new JobRepository($pdo);
    $id = JobRepository::newId();

    $jobs->create([
        'id'            => $id,
        'original_name' => $stored['original_name'],
        'stored_name'   => $stored['stored_name'],
        'mime_type'     => $stored['mime'],
        'size_bytes'    => $stored['size'],
        'sha256'        => $stored['sha256'],
        'language'      => $language,
        'engine'        => [
            'ocr'      => 'tesseract.js',
            'pdf'      => 'pdf.js',
            'server'   => 'PHP ' . PHP_VERSION,
            'pipeline' => 'browser-ocr + php-structuring',
        ],
    ]);

    if (!$config['upload']['retain_source']) {
        @unlink($stored['path']);
    }

    Http::json([
        'ok'  => true,
        'job' => [
            'id'         => $id,
            'file_name'  => $stored['original_name'],
            'size_bytes' => $stored['size'],
            'size_human' => Uploader::humanBytes($stored['size']),
            'sha256'     => $stored['sha256'],
            'language'   => $language,
        ],
    ], 201);
});
