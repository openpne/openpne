<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use App\Models\Member;
use App\Notifications\Push\WebPushConfig;
use App\Rules\PushEndpoint;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Minishlink\WebPush\ContentEncoding;

/**
 * A device registering (or re-registering) itself for push. The body is the browser's
 * PushSubscription serialized as-is, so the field names are the Push API's.
 */
class StorePushSubscriptionRequest extends FormRequest
{
    /** Uncompressed P-256 point, and the auth secret from RFC 8291 — both are fixed sizes. */
    private const P256DH_BYTES = 65;

    private const AUTH_BYTES = 16;

    public function authorize(): bool
    {
        return $this->user() instanceof Member;
    }

    /**
     * With no VAPID keypair the feature does not exist, so a store belongs at 404, not 422 — run the
     * gate before the rules so an invalid body on an unconfigured site never leaks a validation error.
     */
    protected function prepareForValidation(): void
    {
        abort_unless(WebPushConfig::configured(), 404);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:500', new PushEndpoint],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', $this->p256dhPoint()],
            'keys.auth' => ['required', 'string', $this->base64UrlBytes(self::AUTH_BYTES)],
            'contentEncoding' => ['nullable', Rule::enum(ContentEncoding::class)],
        ];
    }

    /**
     * A base64url value decoding to exactly $bytes. Checked here because a malformed key surfaces
     * nowhere useful otherwise: it fails inside the payload encryption, in a queued job, once per
     * notification, for as long as the row exists.
     */
    private function base64UrlBytes(int $bytes): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($bytes): void {
            if ($this->decodeToBytes($value, $bytes) === null) {
                $fail('validation.regex')->translate();
            }
        };
    }

    /**
     * The p256dh key must be a point on the P-256 curve, not merely 65 bytes: an off-curve or
     * non-0x04 value clears the length gate yet still throws inside Encryption at send time — the
     * very failure ingress validation is here to prevent. OpenSSL is the on-curve check.
     */
    private function p256dhPoint(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $point = $this->decodeToBytes($value, self::P256DH_BYTES);

            if ($point === null || ! $this->isP256Point($point)) {
                $fail('validation.regex')->translate();
            }
        };
    }

    /** The raw bytes of a base64url value of exactly $bytes, or null if it is neither. */
    private function decodeToBytes(mixed $value, int $bytes): ?string
    {
        $decoded = is_string($value) && preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value) === 1
            ? base64_decode(strtr($value, '-_', '+/'), true)
            : false;

        return $decoded !== false && strlen($decoded) === $bytes ? $decoded : null;
    }

    private function isP256Point(string $point): bool
    {
        // 3059… is the fixed ASN.1 SubjectPublicKeyInfo header for id-ecPublicKey + prime256v1;
        // wrapping the raw 0x04||X||Y point in it makes a public key OpenSSL loads only when on-curve.
        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point), 64, "\n")
            .'-----END PUBLIC KEY-----'."\n";

        return openssl_pkey_get_public($pem) !== false;
    }
}
