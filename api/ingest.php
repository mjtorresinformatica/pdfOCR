<?php
/**
 * POST /api/ingest.php
 * Body: {
 *   job_id, token,
 *   page_count,            // sent with the first page so progress is known
 *   language,
 *   page: { number, width, height, rotation, source, words: [{text,x0,y0,x1,y1,conf,size}] }
 * }
 *
 * Structures one page and stores it. Coordinates are PDF points, top-left origin.
 */

declare(strict_types=1);

use PdfOcr\DocumentStructurer;
use PdfOcr\EntityExtractor;
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

    $page = $body['page'] ?? null;
    if (!is_array($page) || !isset($page['number'])) {
        Http::fail('The request carries no page payload.', 400);
    }

    $pdo = $connect();
    $jobs = new JobRepository($pdo);

    $job = $jobs->find($jobId);
    if ($job === null) {
        Http::fail('Unknown job. Upload the PDF again.', 404);
    }

    if ((int) $job['page_count'] === 0 && isset($body['page_count'])) {
        $jobs->startProcessing(
            $jobId,
            (int) $body['page_count'],
            (string) ($body['language'] ?? $job['language'])
        );
    }

    $structurer = new DocumentStructurer($config['structure']);
    $structured = $structurer->structurePage($page);

    $entities = (new EntityExtractor())->extract($structured['text'], (int) $structured['number']);

    $jobs->savePage($jobId, $structured, $entities);

    Http::json([
        'ok'   => true,
        'page' => [
            'number'      => $structured['number'],
            'source'      => $structured['source'],
            'confidence'  => $structured['confidence'],
            'word_count'  => $structured['word_count'],
            'block_count' => count($structured['blocks']),
            'blocks'      => array_map(static fn (array $b): array => [
                'id'       => $b['id'],
                'type'     => $b['type'],
                'rel_bbox' => $b['rel_bbox'],
            ], $structured['blocks']),
            'entity_count' => count($entities),
        ],
    ]);
});
