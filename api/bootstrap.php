<?php
/**
 * Shared bootstrap for every entry point (index.php and the /api endpoints).
 */

declare(strict_types=1);

use PdfOcr\Database;
use PdfOcr\Http;
use PdfOcr\Migrator;

require __DIR__ . '/../src/autoload.php';

/** @var array<string,mixed> $config */
$config = require __DIR__ . '/../config/config.php';

date_default_timezone_set((string) $config['app']['timezone']);
mb_internal_encoding('UTF-8');

if ($config['app']['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '0'); // Never break a JSON response with HTML notices.
    ini_set('log_errors', '1');
}

foreach ([$config['paths']['storage'], $config['paths']['uploads'], $config['paths']['output']] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

Database::configure($config['db']);

/**
 * Connects, creating the database and schema on first use.
 */
$connect = static function () use ($config): PDO {
    $pdo = Database::pdo();

    if ($config['app']['auto_migrate']) {
        (new Migrator($pdo, (string) $config['paths']['storage']))
            ->ensureSchema(__DIR__ . '/../db/schema.sql');
    }

    return $pdo;
};

/**
 * Wraps an endpoint so any uncaught error becomes a JSON error instead of a
 * blank 500 the dropzone cannot explain.
 */
$runEndpoint = static function (callable $handler) use ($config): void {
    try {
        $handler();
    } catch (Throwable $e) {
        error_log('[pdfOCR] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        Http::fail(
            'The server could not complete the request.',
            500,
            $config['app']['debug'] ? $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() : null
        );
    }
};
