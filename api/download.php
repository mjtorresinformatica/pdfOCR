<?php
/**
 * GET /api/download.php?job=<uuid>&words=1
 * Sends the JSON document as a file attachment named after the source PDF.
 */

declare(strict_types=1);

use PdfOcr\DocumentAssembler;
use PdfOcr\Http;
use PdfOcr\JobRepository;

require __DIR__ . '/bootstrap.php';

$runEndpoint(static function () use ($config, $connect): void {
    Http::requireMethod('GET');

    $jobId = (string) ($_GET['job'] ?? '');
    if (preg_match('/^[0-9a-f-]{36}$/i', $jobId) !== 1) {
        Http::fail('Invalid job id.', 400);
    }

    $jobs = new JobRepository($connect());
    $job = $jobs->find($jobId);
    if ($job === null) {
        Http::fail('Unknown job.', 404);
    }

    $withWords = ($_GET['words'] ?? '') === '1';
    $cached = (string) $config['paths']['output'] . '/' . $jobId . '.json';

    if (!$withWords && is_file($cached)) {
        $body = (string) file_get_contents($cached);
    } else {
        $assembler = new DocumentAssembler(
            $jobs,
            (string) $config['app']['schema_version'],
            (string) $config['paths']['output']
        );
        $body = Http::encode($assembler->build($job, $withWords));
    }

    $base = pathinfo((string) $job['original_name'], PATHINFO_FILENAME);
    $name = preg_replace('/[^\w.\- ]+/u', '', $base) ?: 'document';

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.json"');
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: no-store');
    echo $body;
});
