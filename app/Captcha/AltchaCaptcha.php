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
use Throwable;

/**
 * Self-hosted ALTCHA (v2, PBKDF2/SHA-256 — the maintained default; plain SHA-256 PoW is too cheap to
 * parallelise to deter bots). The challenge is HMAC-signed with a key derived from APP_KEY, so it
 * needs no separate secret and a forged/tampered solution fails the signature check. verify()
 * reconstructs the widget's submitted payload into the library's objects (it has no one-call
 * deserializer) and re-derives the key to confirm the proof of work.
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
            $data = json_decode(base64_decode($payload, true) ?: '', true, 512, JSON_THROW_ON_ERROR);
            $params = ChallengeParameters::fromArray($data['challenge']['parameters']);
            $challenge = new Challenge($params, $data['challenge']['signature'] ?? null);
            $solution = new Solution((int) $data['solution']['counter'], (string) $data['solution']['derivedKey']);

            return $this->altcha->verifySolution(new VerifySolutionOptions(
                payload: new Payload($challenge, $solution),
                algorithm: $this->algorithm,
            ))->verified;
        } catch (Throwable) {
            return false;
        }
    }
}
