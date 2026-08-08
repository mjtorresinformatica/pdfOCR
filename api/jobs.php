<?php
/**
 * GET    /api/jobs.php            -> the most recent jobs
 * DELETE /api/jobs.php?job=<uuid> -> remove a job, its pages and its files
 */

declare(strict_types=1);

use PdfOcr\Http;
use PdfOcr\JobRepository;
use PdfOcr\Uploader;

require __DIR__ . '/bootstrap.php';

$runEndpoint(static function () use ($config, $connect): void {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $jobs = new JobRepository($connect());

    if ($method === 'DELETE') {
        Http::requireSameOrigin();
        Http::checkToken('pdfocr_token', $_GET['token'] ?? null);

        $jobId = (string) ($_GET['job'] ?? '');
        if (preg_match('/^[0-9a-f-]{36}$/i', $jobId) !== 1) {
            Http::fail('Invalid job id.', 400);
        }

        $job = $jobs->find($jobId);
        if ($job !== null) {
            @unlink((string) $config['paths']['uploads'] . '/' . $job['stored_name']);
            @unlink((string) $config['paths']['output'] . '/' . $jobId . '.json');
            $jobs->delete($jobId);
        }

        Http::json(['ok' => true]);
    }

    Http::requireMethod('GET');

    $limit = max(1, min(50, (int) ($_GET['limit'] ?? 12)));
    $rows = array_map(static function (array $row): array {
        $row['size_human'] = Uploader::humanBytes((int) $row['size_bytes']);
        $row['page_count'] = (int) $row['page_count'];
        $row['word_count'] = (int) $row['word_count'];
        $row['mean_confidence'] = $row['mean_confidence'] === null ? null : (float) $row['mean_confidence'];

        return $row;
    }, $jobs->recent($limit));

    Http::json(['ok' => true, 'jobs' => $rows]);
});
