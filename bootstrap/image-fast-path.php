<?php

/**
 * Decides, before the framework boots, whether a signed variant URL can be answered from the image
 * cache; null hands the request to the application, whatever the input (docs/internals/images.md, "The fast path").
 *
 * @param  array<array-key, mixed>  $server
 * @param  array<array-key, mixed>  $query
 * @param  array<array-key, mixed>  $cookies
 * @return array{file: string, type: string, etag: string}|null
 */
function openpne_image_fast_path(array $server, array $query, array $cookies, string $storagePath): ?array
{
    try {
        $method = $server['REQUEST_METHOD'] ?? null;
        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }

        // A conditional request is the application's to answer: it knows the validator's rules.
        if (isset($server['HTTP_IF_NONE_MATCH'])) {
            return null;
        }

        $version = $query['v'] ?? null;
        $signature = $query['s'] ?? null;
        if (! is_string($version) || preg_match('/^[0-9a-f]{8}$/D', $version) !== 1
            || ! is_string($signature) || preg_match('/^[A-Za-z0-9_-]{22}$/D', $signature) !== 1) {
            return null;
        }

        $uri = $server['REQUEST_URI'] ?? null;
        $host = $server['HTTP_HOST'] ?? null;
        if (! is_string($uri) || ! is_string($host)) {
            return null;
        }

        // Anchored at the end only, so a site served under a sub-path matches too.
        $path = explode('?', $uri, 2)[0];
        if (preg_match('#/cache/img/(jpg|png|gif|webp)/(w[0-9]+_h[0-9]+(?:_sq|_a)?)/([A-Za-z0-9_-]{1,255})\.\1$#D', $path, $m) !== 1) {
            return null;
        }
        [, $format, $geometry, $name] = $m;

        $raw = @file_get_contents($storagePath.'/framework/image-fast-path.json');
        $config = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($config) || ($config['schema'] ?? null) !== 1) {
            return null;
        }

        $key = $config['key'] ?? null;
        $root = $config['root'] ?? null;
        $encoder = $config['encoder'] ?? null;
        $cookie = $config['cookie'] ?? null;
        if (! is_string($key) || preg_match('/^[0-9a-f]{64}$/D', $key) !== 1
            || ! is_string($root) || preg_match('#^/[^\x00]*$#D', $root) !== 1
            || ! is_string($encoder) || preg_match('#^g[0-9]+/[A-Za-z0-9_-]+$#D', $encoder) !== 1
            || ! in_array($cookie, ['openpne-img', '__Host-openpne-img'], true)
            || ($config['version'] ?? null) !== $version
            || ($config['host'] ?? null) !== strtolower((string) preg_replace('/:[0-9]+$/D', '', $host))) {
            return null;
        }

        $binding = $cookies[$cookie] ?? null;
        if (! is_string($binding) || preg_match('/^[0-9a-f]{64}$/D', $binding) !== 1) {
            return null;
        }

        if (! hash_equals(openpne_image_fast_path_signature($key, "{$format}/{$geometry}/{$name}", $version, $binding), $signature)) {
            return null;
        }

        $cacheKey = "{$name}/{$encoder}/{$geometry}.{$format}";

        return [
            'file' => "{$root}/{$cacheKey}",
            'type' => $format === 'jpg' ? 'image/jpeg' : "image/{$format}",
            'etag' => '"'.hash('sha1', $cacheKey).'"',
        ];
    } catch (Throwable) {
        return null;
    }
}

/** The one definition of the signature, shared by the application that writes it and the check above. */
function openpne_image_fast_path_signature(string $key, string $resource, string $version, string $binding): string
{
    $mac = hash_hmac('sha256', "{$resource}\n{$version}\n{$binding}", $key, true);

    return rtrim(strtr(base64_encode(substr($mac, 0, 16)), '+/', '-_'), '=');
}

/** True once the response has been sent; false, with nothing sent, when the application must answer. */
function openpne_image_fast_path_serve(array $server, array $query, array $cookies, string $storagePath): bool
{
    $answer = openpne_image_fast_path($server, $query, $cookies, $storagePath);
    if ($answer === null) {
        return false;
    }

    // Opened before any header: a variant purged since the check above is the application's to answer.
    $handle = @fopen($answer['file'], 'rb');
    if ($handle === false) {
        return false;
    }

    $stat = fstat($handle);
    if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
        fclose($handle);

        return false;
    }

    header('Content-Type: '.$answer['type']);
    header('Content-Length: '.$stat['size']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=31536000, immutable');
    header('ETag: '.$answer['etag']);

    if (($server['REQUEST_METHOD'] ?? null) === 'GET') {
        fpassthru($handle);
    }

    fclose($handle);

    return true;
}
