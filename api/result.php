<?php
/**
 * GET /api/result.php?job=<uuid>&words=1
 * Rebuilds the JSON document for a stored job.
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

    $assembler = new DocumentAssembler(
        $jobs,
        (string) $config['app']['schema_version'],
        (string) $config['paths']['output']
    );

    Http::json([
        'ok'       => true,
        'status'   => $job['status'],
        'document' => $assembler->build($job, ($_GET['words'] ?? '') === '1'),
    ]);
});
