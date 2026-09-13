<?php

declare(strict_types=1);

namespace App\Files\Imgproxy;

use InvalidArgumentException;

/** Signed request URLs for the sidecar: HMAC-SHA256 over salt + path, base64url without padding. */
final class ImgproxyUrl
{
    private function __construct(
        private readonly string $base,
        private readonly string $key,
        private readonly string $salt,
        private readonly string $sourcePrefix,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidArgumentException
     */
    public static function fromConfig(array $config): self
    {
        $base = trim((string) ($config['url'] ?? ''));

        if ($base === '' || ! in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new InvalidArgumentException('OPENPNE_IMAGE_PROCESSOR=imgproxy needs OPENPNE_IMGPROXY_URL, the http(s) address of the imgproxy sidecar.');
        }

        return new self(
            rtrim($base, '/'),
            self::hex('OPENPNE_IMGPROXY_KEY', (string) ($config['key'] ?? '')),
            self::hex('OPENPNE_IMGPROXY_SALT', (string) ($config['salt'] ?? '')),
            (string) ($config['source_prefix'] ?? ''),
        );
    }

    /** The URL that processes the spooled $name with $options and returns $format. */
    public function signed(string $options, string $name, string $format): string
    {
        $path = "/{$options}/plain/local:///{$this->sourcePrefix}{$name}@{$format}";
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $this->salt.$path, $this->key, true)), '+/', '-_'), '=');

        return "{$this->base}/{$signature}{$path}";
    }

    public function health(): string
    {
        return "{$this->base}/health";
    }

    private static function hex(string $env, string $value): string
    {
        $value = trim($value);

        if ($value === '' || strlen($value) % 2 !== 0 || ! ctype_xdigit($value)) {
            throw new InvalidArgumentException("OPENPNE_IMAGE_PROCESSOR=imgproxy needs {$env}: the sidecar's own value, hex-encoded (openssl rand -hex 32).");
        }

        return (string) hex2bin($value);
    }
}
