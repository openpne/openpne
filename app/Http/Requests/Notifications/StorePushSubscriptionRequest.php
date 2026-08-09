<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use App\Models\Member;
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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:500', new PushEndpoint],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', $this->base64UrlBytes(self::P256DH_BYTES)],
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
            $decoded = is_string($value) && preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value) === 1
                ? base64_decode(strtr($value, '-_', '+/'), true)
                : false;

            if ($decoded === false || strlen($decoded) !== $bytes) {
                $fail('validation.regex')->translate();
            }
        };
    }
}
