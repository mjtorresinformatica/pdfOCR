<?php

declare(strict_types=1);

namespace PdfOcr;

/**
 * Reads the stored pages back out of MySQL and assembles the single JSON
 * document that the UI shows and the user downloads.
 */
final class DocumentAssembler
{
    public function __construct(
        private JobRepository $jobs,
        private string $schemaVersion,
        private string $outputDir
    ) {
    }

    /**
     * @param array<string,mixed> $job Row from ocr_jobs
     *
     * @return array<string,mixed>
     */
    public function build(array $job, bool $includeWords = false): array
    {
        $pageRows = $this->jobs->pages((string) $job['id']);

        $pages = [];
        $sources = [];
        $confidences = [];
        $wordCount = 0;
        $blockCount = 0;
        $keyValues = [];
        $tables = [];
        $fullText = [];

        foreach ($pageRows as $row) {
            $layout = json_decode((string) $row['layout'], true) ?: ['blocks' => [], 'tables' => [], 'key_values' => []];
            $blocks = $layout['blocks'] ?? [];

            if (!$includeWords) {
                $blocks = array_map(static function (array $block): array {
                    $block['lines'] = array_map(static function (array $line): array {
                        unset($line['words']);

                        return $line;
                    }, $block['lines'] ?? []);

                    return $block;
                }, $blocks);
            }

            $sources[] = (string) $row['source'];
            if ($row['confidence'] !== null) {
                $confidences[] = (float) $row['confidence'];
            }
            $wordCount += (int) $row['word_count'];
            $blockCount += count($blocks);
            $fullText[] = (string) $row['text'];

            foreach ($layout['key_values'] ?? [] as $kv) {
                $keyValues[] = $kv;
            }
            foreach ($layout['tables'] ?? [] as $table) {
                $tables[] = ['page' => (int) $row['page_number']] + $table;
            }

            $pages[] = [
                'number'     => (int) $row['page_number'],
                'width'      => (float) $row['width'],
                'height'     => (float) $row['height'],
                'unit'       => 'pt',
                'rotation'   => (int) $row['rotation'],
                'source'     => (string) $row['source'],
                'confidence' => $row['confidence'] === null ? null : (float) $row['confidence'],
                'word_count' => (int) $row['word_count'],
                'text'       => (string) $row['text'],
                'blocks'     => array_values($blocks),
                'tables'     => array_values($layout['tables'] ?? []),
                'key_values' => array_values($layout['key_values'] ?? []),
            ];
        }

        $uniqueSources = array_values(array_unique(array_filter($sources, static fn (string $s): bool => $s !== 'empty')));
        $sourceType = match (count($uniqueSources)) {
            0       => 'unknown',
            1       => $uniqueSources[0],
            default => 'mixed',
        };

        $meanConfidence = $confidences === [] ? null : round(array_sum($confidences) / count($confidences), 2);
        $text = implode("\n\n", $fullText);

        return [
            'schema_version' => $this->schemaVersion,
            'generated_at'   => date('c'),
            'job'            => [
                'id'         => (string) $job['id'],
                'file_name'  => (string) $job['original_name'],
                'size_bytes' => (int) $job['size_bytes'],
                'sha256'     => (string) $job['sha256'],
                'language'   => (string) $job['language'],
                'engine'     => json_decode((string) ($job['engine'] ?? '{}'), true) ?: new \stdClass(),
                'created_at' => (string) $job['created_at'],
            ],
            'document'       => [
                'page_count'      => count($pages),
                'source'          => $sourceType,
                'mean_confidence' => $meanConfidence,
                'title'           => $this->guessTitle($pages),
                'text'            => $text,
            ],
            'summary'        => [
                'word_count'      => $wordCount,
                'char_count'      => mb_strlen($text),
                'line_count'      => $text === '' ? 0 : count(preg_split('/\R/u', $text) ?: []),
                'block_count'     => $blockCount,
                'table_count'     => count($tables),
                'key_value_count' => count($keyValues),
            ],
            'key_values'     => $this->mergeKeyValues($keyValues),
            'tables'         => $tables,
            'entities'       => $this->groupEntities((string) $job['id']),
            'pages'          => $pages,
            'warnings'       => $this->warnings($pages, $meanConfidence),
        ];
    }

    /**
     * @param array<string,mixed> $document
     *
     * @return string Absolute path of the written file
     */
    public function write(array $document, string $jobId): string
    {
        if (!is_dir($this->outputDir) && !mkdir($this->outputDir, 0775, true) && !is_dir($this->outputDir)) {
            throw new \RuntimeException('Cannot create the output directory.');
        }

        $path = $this->outputDir . '/' . $jobId . '.json';
        file_put_contents($path, Http::encode($document));

        return $path;
    }

    /**
     * Collapses duplicated labels across pages into one entry with occurrences,
     * which is the shape the field-mapping step will consume next.
     *
     * @param list<array<string,mixed>> $keyValues
     *
     * @return list<array<string,mixed>>
     */
    private function mergeKeyValues(array $keyValues): array
    {
        $merged = [];

        foreach ($keyValues as $kv) {
            $key = (string) ($kv['key'] ?? DocumentStructurer::slug((string) $kv['label']));
            if ($key === '') {
                continue;
            }

            if (!isset($merged[$key])) {
                $merged[$key] = [
                    'key'         => $key,
                    'label'       => $kv['label'],
                    'value'       => $kv['value'],
                    'occurrences' => [],
                ];
            }

            $merged[$key]['occurrences'][] = [
                'page'       => $kv['page'],
                'block_id'   => $kv['block_id'] ?? null,
                'value'      => $kv['value'],
                'confidence' => $kv['confidence'] ?? null,
                'bbox'       => $kv['bbox'] ?? null,
            ];
        }

        return array_values($merged);
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function groupEntities(string $jobId): array
    {
        $grouped = [];
        foreach ($this->jobs->entities($jobId) as $row) {
            $type = (string) $row['entity_type'];
            $grouped[$type][] = [
                'page'  => (int) $row['page_number'],
                'raw'   => (string) $row['raw_value'],
                'value' => $row['normalized'],
            ];
        }
        ksort($grouped);

        return $grouped;
    }

    /** @param list<array<string,mixed>> $pages */
    private function guessTitle(array $pages): ?string
    {
        if ($pages === []) {
            return null;
        }

        // Reading order wins: the first title-looking line on page one, whether
        // it stands alone as a heading block or opens a mixed block.
        foreach ($pages[0]['blocks'] as $block) {
            $first = trim((string) explode("\n", (string) $block['text'])[0]);
            if ($first === '' || mb_strlen($first) > 90) {
                continue;
            }

            $isUpper = mb_strtoupper($first, 'UTF-8') === $first && preg_match('/\p{L}/u', $first) === 1;
            if ($block['type'] === 'heading' || $isUpper) {
                return $first;
            }
        }

        foreach ($pages[0]['blocks'] as $block) {
            $first = trim((string) explode("\n", (string) $block['text'])[0]);
            if ($first !== '') {
                return mb_substr($first, 0, 120);
            }
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $pages
     *
     * @return list<string>
     */
    private function warnings(array $pages, ?float $meanConfidence): array
    {
        $warnings = [];

        if ($pages === []) {
            $warnings[] = 'No pages were stored for this job.';
        }
        if ($meanConfidence !== null && $meanConfidence < 75) {
            $warnings[] = sprintf(
                'Mean OCR confidence is %.1f%%. Rescan at a higher resolution or check the language setting.',
                $meanConfidence
            );
        }

        foreach ($pages as $page) {
            if ($page['source'] === 'empty' || $page['word_count'] === 0) {
                $warnings[] = sprintf('Page %d produced no text.', $page['number']);
            } elseif ($page['confidence'] !== null && $page['confidence'] < 60) {
                $warnings[] = sprintf('Page %d has low confidence (%.1f%%).', $page['number'], $page['confidence']);
            }
        }

        return $warnings;
    }
}
