<?php

declare(strict_types=1);

namespace PdfOcr;

use PDO;

/**
 * Applies db/schema.sql. The schema is written to be idempotent, so this runs
 * at most once per request and is cheap enough to leave enabled in dev.
 */
final class Migrator
{
    private const STAMP = 'schema.applied';

    public function __construct(private PDO $pdo, private string $storagePath)
    {
    }

    public function ensureSchema(string $schemaFile): void
    {
        $stamp = $this->storagePath . '/' . self::STAMP;
        $schemaMtime = (string) filemtime($schemaFile);

        if (is_file($stamp) && file_get_contents($stamp) === $schemaMtime && $this->tablesExist()) {
            return;
        }

        $sql = (string) file_get_contents($schemaFile);
        foreach ($this->statements($sql) as $statement) {
            $this->pdo->exec($statement);
        }

        file_put_contents($stamp, $schemaMtime);
    }

    private function tablesExist(): bool
    {
        $stmt = $this->pdo->query("SHOW TABLES LIKE 'ocr_jobs'");

        return $stmt !== false && $stmt->fetchColumn() !== false;
    }

    /**
     * Splits the file on semicolons at end of line, ignoring comment lines.
     *
     * @return list<string>
     */
    private function statements(string $sql): array
    {
        $clean = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = array_map('trim', explode(';', $clean));

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}
