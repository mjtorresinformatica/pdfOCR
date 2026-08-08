<?php
/**
 * PSR-4 style autoloader for the PdfOcr namespace. No Composer required.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'PdfOcr\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
