<?php
/**
 * POST /api/finalize.php
 * Body: { job_id, token, duration_ms?, include_words? }
 *
 * Assembles every stored page into the final JSON document, writes it to
 * storage/output/<job>.json and returns it.
 */

declare(strict_types=1);

use PdfOcr\DocumentAssembler;
use PdfOcr\Http;
use PdfOcr\JobRepository;

require __DIR__ . '/bootstrap.php';

$runEndpoint(static function () use ($config, $connect): void {
    Http::requireMethod('POST');
    Http::requireSameOrigin();

    $body = Http::jsonBody();
    Http::checkToken('pdfocr_token', isset($body['token']) ? (string) $body['token'] : null);

    $jobId = (string) ($body['job_id'] ?? '');
    if (preg_match('/^[0-9a-f-]{36}$/i', $jobId) !== 1) {
        Http::fail('Invalid job id.', 400);
    }

    $pdo = $connect();
    $jobs = new JobRepository($pdo);

    $job = $jobs->find($jobId);
    if ($job === null) {
        Http::fail('Unknown job.', 404);
    }

    if (isset($body['error'])) {
        $jobs->fail($jobId, (string) $body['error']);
        Http::fail('The job was marked as failed: ' . (string) $body['error'], 200);
    }

    $assembler = new DocumentAssembler(
        $jobs,
        (string) $config['app']['schema_version'],
        (string) $config['paths']['output']
    );

    $document = $assembler->build($job, (bool) ($body['include_words'] ?? false));
    $path = $assembler->write($document, $jobId);

    $jobs->complete($jobId, [
        'source_type'     => $document['document']['source'] === 'unknown' ? 'unknown' : $document['document']['source'],
        'page_count'      => $document['document']['page_count'],
        'word_count'      => $document['summary']['word_count'],
        'mean_confidence' => $document['document']['mean_confidence'],
        'duration_ms'     => isset($body['duration_ms']) ? (int) $body['duration_ms'] : null,
        'result_path'     => 'storage/output/' . basename($path),
    ]);

    Http::json([
        'ok'          => true,
        'download_url' => 'api/download.php?job=' . urlencode($jobId),
        'document'    => $document,
    ]);
});
