<?php

declare(strict_types=1);

namespace PdfOcr;

use JsonException;

/**
 * Minimal request/response helpers for the JSON endpoints under /api.
 */
final class Http
{
    /**
     * Decodes a JSON request body.
     *
     * @return array<string,mixed>
     */
    public static function jsonBody(int $maxBytes = 32 * 1024 * 1024): array
    {
        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            self::fail('Empty request body.', 400);
        }
        if (strlen($raw) > $maxBytes) {
            self::fail('Request body is too large.', 413);
        }

        try {
            $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            self::fail('Malformed JSON: ' . $e->getMessage(), 400);
        }

        if (!is_array($data)) {
            self::fail('Expected a JSON object.', 400);
        }

        return $data;
    }

    /** @param array<string,mixed> $payload */
    public static function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo self::encode($payload);
        exit;
    }

    /** @param array<string,mixed> $payload */
    public static function encode(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    public static function fail(string $message, int $status = 400, ?string $detail = null): never
    {
        $body = ['ok' => false, 'error' => $message];
        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        self::json($body, $status);
    }

    public static function requireMethod(string $method): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
            self::fail('Method not allowed. Use ' . $method . '.', 405);
        }
    }

    /**
     * Same-origin guard. The tool is meant to run on a local host, so this only
     * rejects cross-site posts rather than acting as a full CSRF token scheme.
     */
    public static function requireSameOrigin(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if ($origin === null) {
            return; // Same-origin fetch without an Origin header (or a CLI call).
        }

        $host = $_SERVER['HTTP_HOST'] ?? '';
        $originHost = parse_url($origin, PHP_URL_HOST);
        $originPort = parse_url($origin, PHP_URL_PORT);
        $expected = $originPort !== null ? $originHost . ':' . $originPort : (string) $originHost;

        if ($expected !== $host) {
            self::fail('Cross-origin requests are not accepted.', 403);
        }
    }

    public static function token(string $key): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax']);
        }
        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(16));
        }

        return (string) $_SESSION[$key];
    }

    public static function checkToken(string $key, ?string $given): void
    {
        $expected = self::token($key);
        if ($given === null || !hash_equals($expected, $given)) {
            self::fail('Invalid or expired session token. Reload the page.', 419);
        }
    }
}
