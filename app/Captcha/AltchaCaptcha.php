<?php

namespace App\Captcha;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\ChallengeParameters;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\Solution;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * PBKDF2/SHA-256 rather than plain SHA-256: plain-hash proof of work is too cheap to parallelise to
 * deter bots. The HMAC key defaults to one derived from APP_KEY, so no separate secret is needed and
 * a tampered solution fails the signature check.
 */
class AltchaCaptcha implements Captcha
{
    private readonly Altcha $altcha;

    private readonly Pbkdf2 $algorithm;

    public function __construct(
        string $hmacKey,
        private readonly int $cost,
        private readonly int $maxNumber,
        private readonly int $expiresSeconds,
    ) {
        $this->altcha = new Altcha(hmacSignatureSecret: $hmacKey);
        $this->algorithm = new Pbkdf2;
    }

    public function enabled(): bool
    {
        return true;
    }

    public function challenge(): array
    {
        return $this->altcha->createChallenge(new CreateChallengeOptions(
            algorithm: $this->algorithm,
            cost: $this->cost,
            counter: random_int(1, $this->maxNumber),
            expiresAt: time() + $this->expiresSeconds,
        ))->toArray();
    }

    public function verify(?string $payload): bool
    {
        if (! filled($payload)) {
            return false;
        }

        try {
            // Rebuilt into the library's objects by hand because it offers no one-call deserializer.
            $data = json_decode(base64_decode($payload, true) ?: '', true, 512, JSON_THROW_ON_ERROR);
            $signature = $data['challenge']['signature'] ?? null;
            $params = ChallengeParameters::fromArray($data['challenge']['parameters']);
            $challenge = new Challenge($params, $signature);
            $solution = new Solution((int) $data['solution']['counter'], (string) $data['solution']['derivedKey']);

            $verified = $this->altcha->verifySolution(new VerifySolutionOptions(
                payload: new Payload($challenge, $solution),
                algorithm: $this->algorithm,
            ))->verified;

            // Cache::add is atomic, so a solved payload is accepted once per TTL and a concurrent
            // replay loses the race.
            return $verified
                && is_string($signature)
                && Cache::add('altcha:used:'.$signature, true, $this->expiresSeconds);
        } catch (Throwable) {
            return false;
        }
    }
}
