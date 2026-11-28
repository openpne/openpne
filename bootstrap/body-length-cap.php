<?php

const OPENPNE_MAX_BODY_BYTES = 1048576;

const OPENPNE_BODY_TOO_LARGE_HEADERS = [
    'Content-Type' => 'application/json',
    'X-Content-Type-Options' => 'nosniff',
    // Spelled as Symfony's ResponseHeaderBag normalises `no-store`, so both refusals send the same value.
    'Cache-Control' => 'no-store, private',
];

const OPENPNE_BODY_TOO_LARGE_BODY = '{"message":"The request body is too large."}';

/**
 * True when the declared length or the body read so far passes the limit; reads one byte past it at
 * most and leaves the stream part-read (docs/internals/security.md, "Request body length").
 *
 * @param  array<array-key, mixed>  $server
 */
function openpne_body_exceeds(array $server, mixed $body): bool
{
    $declared = $server['CONTENT_LENGTH'] ?? null;
    if ((is_int($declared) || (is_string($declared) && ctype_digit($declared))) && (int) $declared > OPENPNE_MAX_BODY_BYTES) {
        return true;
    }

    if (! is_resource($body)) {
        return true;
    }

    return strlen((string) stream_get_contents($body, OPENPNE_MAX_BODY_BYTES + 1)) > OPENPNE_MAX_BODY_BYTES;
}

/**
 * A form type is cut where PHP's own form parser cuts it, and stays in scope when either header names
 * JSON, as Laravel would decode it (docs/internals/security.md, "Request body length").
 *
 * @param  array<array-key, mixed>  $server
 */
function openpne_body_length_exceeded(array $server, mixed $body = null): bool
{
    $type = is_string($server['CONTENT_TYPE'] ?? null) ? $server['CONTENT_TYPE'] : '';
    $headerType = is_string($server['HTTP_CONTENT_TYPE'] ?? null) ? $server['HTTP_CONTENT_TYPE'] : '';
    $mediaType = strtolower(preg_split('/[;, ]/', $type, 2)[0]);
    $namesJson = (bool) preg_match('#[/+]json#i', $type.' '.$headerType);

    if (in_array($mediaType, ['application/x-www-form-urlencoded', 'multipart/form-data'], true) && ! $namesJson) {
        return false;
    }

    if ($body !== null) {
        return openpne_body_exceeds($server, $body);
    }

    $input = @fopen('php://input', 'rb');
    $exceeds = openpne_body_exceeds($server, $input);
    if (is_resource($input)) {
        fclose($input);
    }

    return $exceeds;
}

function openpne_refuse_body_length(): never
{
    http_response_code(413);
    foreach (OPENPNE_BODY_TOO_LARGE_HEADERS as $name => $value) {
        header("{$name}: {$value}");
    }
    echo OPENPNE_BODY_TOO_LARGE_BODY;

    exit;
}
