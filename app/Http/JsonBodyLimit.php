<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Runs from public/index.php before the framework, which decodes a JSON body as it captures the request (docs/internals/security.md, "JSON request bodies").
 */
final class JsonBodyLimit
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public const MAX_SEPARATORS = 50_000;

    public const MAX_KEYS = 15_000;

    /**
     * Reads at most one byte past MAX_BYTES of the body, and nothing when the type is not JSON or the declared length is already over.
     *
     * @param  array<string, mixed>  $server
     * @param  resource|null  $body  the request body, php://input when null
     */
    public static function exceeded(array $server, mixed $body = null): bool
    {
        if (! self::isJson($server['CONTENT_TYPE'] ?? null) && ! self::isJson($server['HTTP_CONTENT_TYPE'] ?? null)) {
            return false;
        }

        $declared = $server['CONTENT_LENGTH'] ?? null;

        if (is_numeric($declared) && (int) $declared > self::MAX_BYTES) {
            return true;
        }

        $read = (string) stream_get_contents($body ?? fopen('php://input', 'rb'), self::MAX_BYTES + 1);

        if (strlen($read) > self::MAX_BYTES) {
            return true;
        }

        // Every JSON value but the first follows one of these, and every object key a colon.
        $keys = substr_count($read, ':');

        return $keys > self::MAX_KEYS
            || $keys + substr_count($read, ',') + substr_count($read, '[') + substr_count($read, '{') > self::MAX_SEPARATORS;
    }

    /** @param  array<string, mixed>  $server */
    public static function refusal(array $server): JsonResponse
    {
        $response = new JsonResponse(['message' => 'The JSON body is too large.'], 413, ['X-Content-Type-Options' => 'nosniff']);

        // The framework's prepare(), which this response never passes through, would answer 1.1 the same way.
        return ($server['SERVER_PROTOCOL'] ?? null) === 'HTTP/1.0' ? $response : $response->setProtocolVersion('1.1');
    }

    /**
     * A superset of Laravel's isJson(), which searches the whole header, and Symfony's json format, which adds application/x-json.
     */
    private static function isJson(mixed $type): bool
    {
        if (! is_string($type)) {
            return false;
        }

        $type = strtolower($type);

        return str_contains($type, '/json') || str_contains($type, '+json') || str_contains(explode(';', $type, 2)[0], 'json');
    }
}
