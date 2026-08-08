<?php
/**
 * Application configuration.
 *
 * Copy config.local.php.example to config.local.php to override anything here
 * without touching version control.
 */

declare(strict_types=1);

$config = [
    'app' => [
        'name'          => 'pdfOCR',
        'schema_version' => '1.0',
        'debug'         => true,
        'timezone'      => 'Europe/Madrid',
        // Auto-create the database and tables on first request.
        'auto_migrate'  => true,
    ],

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'pdfocr',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    'upload' => [
        // Hard ceiling enforced in PHP, independent of php.ini.
        'max_bytes'         => 25 * 1024 * 1024,
        'allowed_mime'      => ['application/pdf', 'application/x-pdf'],
        'allowed_extension' => ['pdf'],
        'retain_source'     => true, // keep the uploaded PDF next to its JSON
    ],

    'paths' => [
        'storage' => __DIR__ . '/../storage',
        'uploads' => __DIR__ . '/../storage/uploads',
        'output'  => __DIR__ . '/../storage/output',
    ],

    'ocr' => [
        'default_language' => 'spa',
        'languages'        => [
            'spa'     => 'Español',
            'eng'     => 'English',
            'fra'     => 'Français',
            'deu'     => 'Deutsch',
            'ita'     => 'Italiano',
            'por'     => 'Português',
            'cat'     => 'Català',
            'ara'     => 'العربية',
            'rus'     => 'Русский',
            'chi_sim' => '简体中文',
        ],
    ],

    'structure' => [
        // A page whose embedded text layer yields at least this many characters
        // is trusted and never rasterised for OCR.
        'text_layer_min_chars' => 120,
        // Vertical tolerance (as a fraction of median line height) for deciding
        // that two words belong to the same line.
        'line_tolerance'       => 0.6,
        // Gap larger than this many times the median line height starts a new block.
        'block_gap_factor'     => 1.45,
        // Minimum share of lines that must look like "Label: value" for a block
        // to be tagged as a key/value group.
        'kv_block_ratio'       => 0.6,
    ],
];

$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $override = require $local;
    if (is_array($override)) {
        foreach ($override as $section => $values) {
            $config[$section] = is_array($values) && isset($config[$section]) && is_array($config[$section])
                ? array_replace($config[$section], $values)
                : $values;
        }
    }
}

return $config;
