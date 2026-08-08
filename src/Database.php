<?php

declare(strict_types=1);

namespace PdfOcr;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Lazy PDO connection holder. One connection per request.
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** @var array<string,mixed> */
    private static array $config = [];

    /** @param array<string,mixed> $config The "db" section of config.php */
    public static function configure(array $config): void
    {
        self::$config = $config;
        self::$pdo = null;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $c = self::$config;
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'],
            (int) $c['port'],
            $c['database'],
            $c['charset']
        );

        try {
            self::$pdo = new PDO($dsn, $c['username'], $c['password'], self::options());
        } catch (PDOException $e) {
            // 1049 = unknown database. Create it, then retry once.
            if (self::isUnknownDatabase($e)) {
                self::createDatabase();
                self::$pdo = new PDO($dsn, $c['username'], $c['password'], self::options());
            } else {
                throw new RuntimeException('Cannot connect to MySQL: ' . $e->getMessage(), 0, $e);
            }
        }

        return self::$pdo;
    }

    /**
     * Connection without a database selected, used to bootstrap the schema.
     */
    public static function serverPdo(): PDO
    {
        $c = self::$config;
        $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], (int) $c['port'], $c['charset']);

        return new PDO($dsn, $c['username'], $c['password'], self::options());
    }

    private static function createDatabase(): void
    {
        $name = self::$config['database'];
        if (preg_match('/^[A-Za-z0-9_]+$/', (string) $name) !== 1) {
            throw new RuntimeException('Refusing to create a database with an unsafe name.');
        }

        self::serverPdo()->exec(
            sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $name
            )
        );
    }

    private static function isUnknownDatabase(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 1049 || str_contains($e->getMessage(), 'Unknown database');
    }

    /** @return array<int,mixed> */
    private static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];
    }
}
