<?php

declare(strict_types=1);

namespace PdfOcr;

/**
 * Turns a flat list of positioned words into a page tree:
 *
 *     words -> lines -> blocks (paragraph | heading | list | key_value | table)
 *
 * Every coordinate that comes in is expected to use a top-left origin measured
 * in PDF points, which is what assets/js/pdf-processor.js emits for both the
 * embedded text layer and the Tesseract output.
 */
final class DocumentStructurer
{
    private float $lineTolerance;
    private float $blockGapFactor;
    private float $kvBlockRatio;
    private float $pageHeight = 0.0;
    private float $pageWidth = 0.0;

    /** @param array<string,mixed> $config The "structure" section of config.php */
    public function __construct(array $config = [])
    {
        $this->lineTolerance = (float) ($config['line_tolerance'] ?? 0.6);
        $this->blockGapFactor = (float) ($config['block_gap_factor'] ?? 1.45);
        $this->kvBlockRatio = (float) ($config['kv_block_ratio'] ?? 0.6);
    }

    /**
     * @param array<string,mixed> $page Raw page payload posted by the browser.
     *
     * @return array<string,mixed> Structured page
     */
    public function structurePage(array $page): array
    {
        $number = max(1, (int) ($page['number'] ?? 1));
        $width = (float) ($page['width'] ?? 0);
        $height = (float) ($page['height'] ?? 0);
        $source = in_array($page['source'] ?? '', ['text_layer', 'ocr', 'empty'], true)
            ? (string) $page['source']
            : 'ocr';

        $this->pageWidth = $width;
        $this->pageHeight = $height;

        $words = $this->normaliseWords(is_array($page['words'] ?? null) ? $page['words'] : []);

        if ($words === []) {
            return [
                'number'     => $number,
                'width'      => round($width, 2),
                'height'     => round($height, 2),
                'unit'       => 'pt',
                'rotation'   => (int) ($page['rotation'] ?? 0),
                'source'     => 'empty',
                'confidence' => null,
                'word_count' => 0,
                'text'       => '',
                'blocks'     => [],
                'tables'     => [],
                'key_values' => [],
            ];
        }

        $medianHeight = $this->median(array_map(
            static fn (array $w): float => $w['bbox']['y1'] - $w['bbox']['y0'],
            $words
        )) ?: 10.0;

        $lines = $this->groupLines($words, $medianHeight);
        $blocks = $this->groupBlocks($lines, $medianHeight);

        $blocks = array_map(
            fn (array $block, int $i): array => $this->classifyBlock($block, $i, $number, $medianHeight),
            $blocks,
            array_keys($blocks)
        );

        $tables = [];
        $keyValues = [];
        foreach ($blocks as $block) {
            if ($block['type'] === 'table' && isset($block['table'])) {
                $tables[] = ['block_id' => $block['id'], 'bbox' => $block['bbox']] + $block['table'];
            }
            foreach ($block['key_values'] ?? [] as $kv) {
                $keyValues[] = $kv;
            }
        }

        $text = implode("\n\n", array_map(static fn (array $b): string => $b['text'], $blocks));
        $confidences = array_values(array_filter(
            array_map(static fn (array $w): ?float => $w['confidence'], $words),
            static fn (?float $c): bool => $c !== null
        ));

        return [
            'number'     => $number,
            'width'      => round($width, 2),
            'height'     => round($height, 2),
            'unit'       => 'pt',
            'rotation'   => (int) ($page['rotation'] ?? 0),
            'source'     => $source,
            'confidence' => $confidences === [] ? null : round(array_sum($confidences) / count($confidences), 2),
            'word_count' => count($words),
            'line_count' => count($lines),
            'text'       => $text,
            'blocks'     => array_map(
                static function (array $b): array {
                    unset($b['table'], $b['key_values']);

                    return $b;
                },
                $blocks
            ),
            'tables'     => $tables,
            'key_values' => $keyValues,
        ];
    }

    /**
     * @param list<mixed> $raw
     *
     * @return list<array{text:string,bbox:array{x0:float,y0:float,x1:float,y1:float},confidence:?float,font_size:?float}>
     */
    private function normaliseWords(array $raw): array
    {
        $words = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $text = trim((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $x0 = (float) ($item['x0'] ?? 0);
            $y0 = (float) ($item['y0'] ?? 0);
            $x1 = (float) ($item['x1'] ?? $x0);
            $y1 = (float) ($item['y1'] ?? $y0);

            $words[] = [
                'text'       => $text,
                'bbox'       => [
                    'x0' => min($x0, $x1),
                    'y0' => min($y0, $y1),
                    'x1' => max($x0, $x1),
                    'y1' => max($y0, $y1),
                ],
                'confidence' => isset($item['conf']) ? round((float) $item['conf'], 2) : null,
                'font_size'  => isset($item['size']) ? round((float) $item['size'], 2) : null,
            ];
        }

        usort($words, static function (array $a, array $b): int {
            return [$a['bbox']['y0'], $a['bbox']['x0']] <=> [$b['bbox']['y0'], $b['bbox']['x0']];
        });

        return $words;
    }

    /**
     * Clusters words whose vertical centres sit within a fraction of the median
     * glyph height. Reading order inside a line is left to right.
     *
     * @param list<array<string,mixed>> $words
     *
     * @return list<array<string,mixed>>
     */
    private function groupLines(array $words, float $medianHeight): array
    {
        $tolerance = $medianHeight * $this->lineTolerance;
        $lines = [];
        $current = [];
        $currentCentre = null;

        foreach ($words as $word) {
            $centre = ($word['bbox']['y0'] + $word['bbox']['y1']) / 2;
            if ($currentCentre === null || abs($centre - $currentCentre) <= $tolerance) {
                $current[] = $word;
                $currentCentre = $currentCentre === null
                    ? $centre
                    : ($currentCentre * (count($current) - 1) + $centre) / count($current);
                continue;
            }

            $lines[] = $this->buildLine($current, $medianHeight);
            $current = [$word];
            $currentCentre = $centre;
        }

        if ($current !== []) {
            $lines[] = $this->buildLine($current, $medianHeight);
        }

        return $lines;
    }

    /**
     * @param list<array<string,mixed>> $words
     *
     * @return array<string,mixed>
     */
    private function buildLine(array $words, float $medianHeight): array
    {
        usort($words, static fn (array $a, array $b): int => $a['bbox']['x0'] <=> $b['bbox']['x0']);

        $text = '';
        $segments = [];       // Text split on wide gaps: the raw material for columns.
        $segment = '';
        $segmentStart = $words[0]['bbox']['x0'];
        $gapThreshold = $medianHeight * 1.6;
        $previousRight = null;

        foreach ($words as $word) {
            $gap = $previousRight === null ? 0.0 : $word['bbox']['x0'] - $previousRight;

            if ($previousRight !== null) {
                $text .= $gap > $gapThreshold ? '  ' : ' ';
            }
            $text .= $word['text'];

            if ($previousRight !== null && $gap > $gapThreshold) {
                $segments[] = ['text' => $segment, 'x0' => $segmentStart, 'x1' => $previousRight];
                $segment = '';
                $segmentStart = $word['bbox']['x0'];
            }
            $segment .= ($segment === '' ? '' : ' ') . $word['text'];
            $previousRight = $word['bbox']['x1'];
        }
        $segments[] = ['text' => $segment, 'x0' => $segmentStart, 'x1' => (float) $previousRight];

        $confidences = array_values(array_filter(
            array_map(static fn (array $w): ?float => $w['confidence'], $words),
            static fn (?float $c): bool => $c !== null
        ));
        $sizes = array_values(array_filter(
            array_map(static fn (array $w): ?float => $w['font_size'], $words),
            static fn (?float $s): bool => $s !== null && $s > 0
        ));

        return [
            'text'       => $text,
            'bbox'       => $this->hull(array_map(static fn (array $w): array => $w['bbox'], $words)),
            'confidence' => $confidences === [] ? null : round(array_sum($confidences) / count($confidences), 2),
            'font_size'  => $sizes === [] ? null : round($this->median($sizes), 2),
            'segments'   => $segments,
            'words'      => array_map(static fn (array $w): array => [
                'text'       => $w['text'],
                'bbox'       => $w['bbox'],
                'confidence' => $w['confidence'],
            ], $words),
        ];
    }

    /**
     * A new block starts when the vertical gap to the previous line grows past
     * the usual leading, or when the two lines do not overlap horizontally at all.
     *
     * @param list<array<string,mixed>> $lines
     *
     * @return list<list<array<string,mixed>>>
     */
    private function groupBlocks(array $lines, float $medianHeight): array
    {
        $blocks = [];
        $current = [];
        $previous = null;

        foreach ($lines as $line) {
            if ($previous !== null) {
                $gap = $line['bbox']['y0'] - $previous['bbox']['y1'];
                $overlaps = $line['bbox']['x0'] < $previous['bbox']['x1']
                    && $previous['bbox']['x0'] < $line['bbox']['x1'];

                if ($gap > $medianHeight * $this->blockGapFactor || !$overlaps || $this->sizeShift($previous, $line)) {
                    $blocks[] = $current;
                    $current = [];
                }
            }
            $current[] = $line;
            $previous = $line;
        }

        if ($current !== []) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * A change of type size between consecutive lines separates a heading from
     * the body that follows it. Only applied when both lines report a font size,
     * which is the case for the embedded text layer but not for OCR output,
     * where glyph heights swing with ascenders and descenders.
     *
     * @param array<string,mixed> $previous
     * @param array<string,mixed> $line
     */
    private function sizeShift(array $previous, array $line): bool
    {
        $before = $previous['font_size'] ?? null;
        $after = $line['font_size'] ?? null;

        if ($before === null || $after === null || $before <= 0) {
            return false;
        }

        $ratio = $after / $before;

        return $ratio < 0.88 || $ratio > 1.14;
    }

    /**
     * @param list<array<string,mixed>> $lines
     *
     * @return array<string,mixed>
     */
    private function classifyBlock(array $lines, int $index, int $pageNumber, float $medianHeight): array
    {
        $bbox = $this->hull(array_map(static fn (array $l): array => $l['bbox'], $lines));
        $text = implode("\n", array_map(static fn (array $l): string => $l['text'], $lines));
        $id = sprintf('p%d-b%d', $pageNumber, $index + 1);

        $confidences = array_values(array_filter(
            array_map(static fn (array $l): ?float => $l['confidence'], $lines),
            static fn (?float $c): bool => $c !== null
        ));

        $table = $this->detectTable($lines);
        $keyValues = $this->detectKeyValues($lines, $pageNumber, $id);
        $type = $this->blockType($lines, $table, $keyValues, $medianHeight);

        $block = [
            'id'         => $id,
            'page'       => $pageNumber,
            'type'       => $type,
            'text'       => $text,
            'bbox'       => $bbox,
            'rel_bbox'   => $this->relative($bbox),
            'confidence' => $confidences === [] ? null : round(array_sum($confidences) / count($confidences), 2),
            'line_count' => count($lines),
            'lines'      => array_map(static function (array $l): array {
                unset($l['segments']);

                return $l;
            }, $lines),
        ];

        if ($type === 'table' && $table !== null) {
            $block['table'] = $table;
        }
        if ($keyValues !== []) {
            $block['key_values'] = $keyValues;
        }

        return $block;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string,mixed>|null  $table
     * @param list<array<string,mixed>> $keyValues
     */
    private function blockType(array $lines, ?array $table, array $keyValues, float $medianHeight): string
    {
        $count = count($lines);
        $first = $lines[0]['text'];

        if ($table !== null) {
            return 'table';
        }
        if ($count > 0 && count($keyValues) / $count >= $this->kvBlockRatio) {
            return 'key_value';
        }
        if (preg_match('/^\s*([•·▪\-–—*]|\(?\d{1,2}[.)]|[a-zA-Z][.)])\s+/u', $first) === 1) {
            return 'list';
        }

        if ($count <= 2) {
            $height = $lines[0]['bbox']['y1'] - $lines[0]['bbox']['y0'];
            $isShort = mb_strlen(trim($first)) <= 80;
            $isBig = $height > $medianHeight * 1.25;
            $isUpper = $isShort && mb_strtoupper($first, 'UTF-8') === $first && preg_match('/\p{L}/u', $first) === 1;
            $endsClean = preg_match('/[.;,]$/u', trim($first)) !== 1;

            if ($isShort && $endsClean && ($isBig || $isUpper)) {
                return 'heading';
            }
        }

        return 'paragraph';
    }

    /**
     * A block is treated as a table when most of its lines split into the same
     * number of column segments (>= 2) at roughly the same x positions.
     *
     * @param list<array<string,mixed>> $lines
     *
     * @return array<string,mixed>|null
     */
    private function detectTable(array $lines): ?array
    {
        if (count($lines) < 2) {
            return null;
        }

        $multi = array_values(array_filter($lines, static fn (array $l): bool => count($l['segments']) >= 2));
        if (count($multi) < 2 || count($multi) / count($lines) < 0.6) {
            return null;
        }

        $counts = [];
        foreach ($multi as $line) {
            $counts[count($line['segments'])] = ($counts[count($line['segments'])] ?? 0) + 1;
        }
        arsort($counts);
        $columns = (int) array_key_first($counts);
        if ($columns < 2 || $counts[$columns] < 2) {
            return null;
        }

        // Column anchors: median left edge of each segment index.
        $anchors = [];
        for ($i = 0; $i < $columns; $i++) {
            $xs = [];
            foreach ($multi as $line) {
                if (isset($line['segments'][$i])) {
                    $xs[] = (float) $line['segments'][$i]['x0'];
                }
            }
            $anchors[] = $xs === [] ? 0.0 : round($this->median($xs), 2);
        }

        $rows = [];
        foreach ($lines as $line) {
            $cells = array_fill(0, $columns, '');
            foreach ($line['segments'] as $segment) {
                $best = 0;
                $bestDistance = PHP_FLOAT_MAX;
                foreach ($anchors as $i => $anchor) {
                    $distance = abs((float) $segment['x0'] - $anchor);
                    if ($distance < $bestDistance) {
                        $bestDistance = $distance;
                        $best = $i;
                    }
                }
                $cells[$best] = trim($cells[$best] === '' ? $segment['text'] : $cells[$best] . ' ' . $segment['text']);
            }
            $rows[] = $cells;
        }

        $header = null;
        if (count($rows) > 1 && $this->looksLikeHeader($rows[0])) {
            $header = array_shift($rows);
        }

        return [
            'columns'       => $columns,
            'column_anchors' => $anchors,
            'header'        => $header,
            'rows'          => $rows,
        ];
    }

    /** @param list<string> $row */
    private function looksLikeHeader(array $row): bool
    {
        $filled = array_values(array_filter($row, static fn (string $c): bool => trim($c) !== ''));
        if (count($filled) < 2) {
            return false; // A single filled cell is a caption, not a header row.
        }

        foreach ($filled as $cell) {
            if (preg_match('/^\s*[\d.,€$%\/-]+\s*$/u', $cell) === 1) {
                return false; // Numeric cells belong to the body, not the header.
            }
        }

        return true;
    }

    /**
     * Finds "Label: value" pairs, both inline and across table-like columns.
     *
     * @param list<array<string,mixed>> $lines
     *
     * @return list<array<string,mixed>>
     */
    private function detectKeyValues(array $lines, int $pageNumber, string $blockId): array
    {
        $pairs = [];

        foreach ($lines as $line) {
            $text = trim($line['text']);
            if ($text === '') {
                continue;
            }

            $label = null;
            $value = null;

            if (preg_match('/^(?<k>[^:]{2,60}?)\s*:\s*(?<v>\S.*)$/u', $text, $m) === 1) {
                $label = $m['k'];
                $value = $m['v'];
            } elseif (count($line['segments']) === 2) {
                [$left, $right] = $line['segments'];
                $leftText = trim((string) $left['text']);
                $rightText = trim((string) $right['text']);
                if ($leftText !== '' && $rightText !== '' && mb_strlen($leftText) <= 60 && $this->looksLikeLabel($leftText)) {
                    $label = $leftText;
                    $value = $rightText;
                }
            }

            if ($label === null || $value === null) {
                continue;
            }

            $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);
            $value = trim(preg_replace('/\s{2,}/u', ' ', $value) ?? $value);
            if ($label === '' || $value === '' || mb_strlen($label) < 2) {
                continue;
            }

            $pairs[] = [
                'page'       => $pageNumber,
                'block_id'   => $blockId,
                'label'      => $label,
                'value'      => $value,
                'key'        => self::slug($label),
                'confidence' => $line['confidence'],
                'bbox'       => $line['bbox'],
            ];
        }

        return $pairs;
    }

    private function looksLikeLabel(string $text): bool
    {
        // Labels are wordy and rarely end in a digit-only token.
        return preg_match('/\p{L}/u', $text) === 1
            && preg_match('/^[\d.,€$%\s\/-]+$/u', $text) !== 1;
    }

    public static function slug(string $label): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $label);
        $ascii = $ascii === false ? $label : $ascii;
        $ascii = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $ascii) ?? $ascii);

        return trim($ascii, '_');
    }

    /**
     * @param list<array{x0:float,y0:float,x1:float,y1:float}> $boxes
     *
     * @return array{x0:float,y0:float,x1:float,y1:float}
     */
    private function hull(array $boxes): array
    {
        $x0 = min(array_column($boxes, 'x0'));
        $y0 = min(array_column($boxes, 'y0'));
        $x1 = max(array_column($boxes, 'x1'));
        $y1 = max(array_column($boxes, 'y1'));

        return [
            'x0' => round((float) $x0, 2),
            'y0' => round((float) $y0, 2),
            'x1' => round((float) $x1, 2),
            'y1' => round((float) $y1, 2),
        ];
    }

    /**
     * @param array{x0:float,y0:float,x1:float,y1:float} $bbox
     *
     * @return array{x:float,y:float,w:float,h:float}
     */
    private function relative(array $bbox): array
    {
        $width = $this->pageWidth > 0 ? $this->pageWidth : 1.0;
        $height = $this->pageHeight > 0 ? $this->pageHeight : 1.0;

        return [
            'x' => round($bbox['x0'] / $width, 4),
            'y' => round($bbox['y0'] / $height, 4),
            'w' => round(($bbox['x1'] - $bbox['x0']) / $width, 4),
            'h' => round(($bbox['y1'] - $bbox['y0']) / $height, 4),
        ];
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        $values = array_values(array_filter($values, static fn (float $v): bool => $v > 0));
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? ($values[$mid - 1] + $values[$mid]) / 2
            : $values[$mid];
    }
}
