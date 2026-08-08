<?php

declare(strict_types=1);

namespace PdfOcr;

use PDO;

/**
 * All persistence for a job. Every statement is a prepared PDO statement.
 */
final class JobRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public static function newId(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): void
    {
        $sql = 'INSERT INTO ocr_jobs
                (id, original_name, stored_name, mime_type, size_bytes, sha256, language,
                 status, engine, created_at, updated_at)
                VALUES
                (:id, :original_name, :stored_name, :mime_type, :size_bytes, :sha256, :language,
                 :status, :engine, :created_at, :updated_at)';

        $now = self::now();
        $this->pdo->prepare($sql)->execute([
            ':id'            => $data['id'],
            ':original_name' => $data['original_name'],
            ':stored_name'   => $data['stored_name'],
            ':mime_type'     => $data['mime_type'],
            ':size_bytes'    => $data['size_bytes'],
            ':sha256'        => $data['sha256'],
            ':language'      => $data['language'],
            ':status'        => 'pending',
            ':engine'        => json_encode($data['engine'] ?? [], JSON_UNESCAPED_UNICODE),
            ':created_at'    => $now,
            ':updated_at'    => $now,
        ]);
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ocr_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function recent(int $limit = 12): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, original_name, size_bytes, page_count, word_count, status, source_type,
                    mean_confidence, language, created_at, completed_at
             FROM ocr_jobs
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function startProcessing(string $id, int $pageCount, string $language): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ocr_jobs
             SET status = :status, page_count = :pages, language = :language, pages_done = 0, updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            ':status'   => 'processing',
            ':pages'    => $pageCount,
            ':language' => $language,
            ':now'      => self::now(),
            ':id'       => $id,
        ]);
    }

    /**
     * Stores one structured page and everything derived from it.
     *
     * @param array<string,mixed> $page      Output of DocumentStructurer::structurePage()
     * @param list<array<string,mixed>> $entities Output of EntityExtractor::extract()
     */
    public function savePage(string $jobId, array $page, array $entities): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->deletePage($jobId, (int) $page['number']);

            $stmt = $this->pdo->prepare(
                'INSERT INTO ocr_pages
                    (job_id, page_number, width, height, rotation, source, confidence, word_count, text, layout, created_at)
                 VALUES
                    (:job_id, :page_number, :width, :height, :rotation, :source, :confidence, :word_count, :text, :layout, :now)'
            );
            $stmt->execute([
                ':job_id'      => $jobId,
                ':page_number' => $page['number'],
                ':width'       => $page['width'],
                ':height'      => $page['height'],
                ':rotation'    => $page['rotation'],
                ':source'      => $page['source'],
                ':confidence'  => $page['confidence'],
                ':word_count'  => $page['word_count'],
                ':text'        => $page['text'],
                ':layout'      => json_encode(
                    [
                        'blocks'     => $page['blocks'],
                        'tables'     => $page['tables'],
                        'key_values' => $page['key_values'],
                    ],
                    JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                ),
                ':now'         => self::now(),
            ]);

            $this->insertBlocks($jobId, $page);
            $this->insertEntities($jobId, $page, $entities);

            // Correlated subqueries keep this to a single bound job id, which
            // matters because native prepares reject a repeated placeholder.
            $stmt = $this->pdo->prepare(
                'UPDATE ocr_jobs
                 SET pages_done = (SELECT COUNT(*) FROM ocr_pages WHERE job_id = ocr_jobs.id),
                     word_count = (SELECT COALESCE(SUM(word_count), 0) FROM ocr_pages WHERE job_id = ocr_jobs.id),
                     status = :status,
                     updated_at = :now
                 WHERE id = :id'
            );
            $stmt->execute([
                ':status' => 'processing',
                ':now'    => self::now(),
                ':id'     => $jobId,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    /** @param array<string,mixed> $page */
    private function insertBlocks(string $jobId, array $page): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ocr_blocks (job_id, page_number, block_index, block_type, text, line_count, confidence, bbox)
             VALUES (:job_id, :page_number, :block_index, :block_type, :text, :line_count, :confidence, :bbox)'
        );

        foreach ($page['blocks'] as $index => $block) {
            $stmt->execute([
                ':job_id'      => $jobId,
                ':page_number' => $page['number'],
                ':block_index' => $index,
                ':block_type'  => $block['type'],
                ':text'        => $block['text'],
                ':line_count'  => $block['line_count'],
                ':confidence'  => $block['confidence'],
                ':bbox'        => json_encode($block['bbox']),
            ]);
        }
    }

    /**
     * @param array<string,mixed>       $page
     * @param list<array<string,mixed>> $entities
     */
    private function insertEntities(string $jobId, array $page, array $entities): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ocr_entities (job_id, page_number, kind, entity_type, label, raw_value, normalized, confidence, bbox)
             VALUES (:job_id, :page_number, :kind, :entity_type, :label, :raw_value, :normalized, :confidence, :bbox)'
        );

        foreach ($page['key_values'] as $kv) {
            $stmt->execute([
                ':job_id'      => $jobId,
                ':page_number' => $page['number'],
                ':kind'        => 'key_value',
                ':entity_type' => 'key_value',
                ':label'       => mb_substr((string) $kv['label'], 0, 255),
                ':raw_value'   => $kv['value'],
                ':normalized'  => mb_substr((string) $kv['key'], 0, 255),
                ':confidence'  => $kv['confidence'],
                ':bbox'        => json_encode($kv['bbox']),
            ]);
        }

        foreach ($entities as $entity) {
            $stmt->execute([
                ':job_id'      => $jobId,
                ':page_number' => $page['number'],
                ':kind'        => 'entity',
                ':entity_type' => $entity['type'],
                ':label'       => null,
                ':raw_value'   => $entity['raw'],
                ':normalized'  => $entity['value'] === null ? null : mb_substr((string) $entity['value'], 0, 255),
                ':confidence'  => null,
                ':bbox'        => null,
            ]);
        }
    }

    public function deletePage(string $jobId, int $pageNumber): void
    {
        foreach (['ocr_pages', 'ocr_blocks', 'ocr_entities'] as $table) {
            $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE job_id = :job_id AND page_number = :page");
            $stmt->execute([':job_id' => $jobId, ':page' => $pageNumber]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function pages(string $jobId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ocr_pages WHERE job_id = :job_id ORDER BY page_number');
        $stmt->execute([':job_id' => $jobId]);

        return $stmt->fetchAll();
    }

    /** @return list<array<string,mixed>> */
    public function entities(string $jobId, string $kind = 'entity'): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT page_number, entity_type, label, raw_value, normalized, confidence
             FROM ocr_entities
             WHERE job_id = :job_id AND kind = :kind
             ORDER BY page_number, entity_type'
        );
        $stmt->execute([':job_id' => $jobId, ':kind' => $kind]);

        return $stmt->fetchAll();
    }

    /** @param array<string,mixed> $summary */
    public function complete(string $jobId, array $summary): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ocr_jobs
             SET status = :status, source_type = :source_type, page_count = :page_count,
                 word_count = :word_count, mean_confidence = :confidence, duration_ms = :duration,
                 result_path = :result_path, error_message = NULL,
                 updated_at = :updated_at, completed_at = :completed_at
             WHERE id = :id'
        );
        $now = self::now();
        $stmt->execute([
            ':status'      => 'completed',
            ':source_type' => $summary['source_type'],
            ':page_count'  => $summary['page_count'],
            ':word_count'  => $summary['word_count'],
            ':confidence'  => $summary['mean_confidence'],
            ':duration'    => $summary['duration_ms'],
            ':result_path' => $summary['result_path'],
            ':updated_at'  => $now,
            ':completed_at' => $now,
            ':id'          => $jobId,
        ]);
    }

    public function fail(string $jobId, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ocr_jobs SET status = :status, error_message = :message, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            ':status'  => 'failed',
            ':message' => mb_substr($message, 0, 2000),
            ':now'     => self::now(),
            ':id'      => $jobId,
        ]);
    }

    public function delete(string $jobId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ocr_jobs WHERE id = :id');
        $stmt->execute([':id' => $jobId]);
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
