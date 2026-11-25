<?php

declare(strict_types=1);

namespace Tests\Feature\Captcha;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Captcha\AltchaCaptcha;
use Tests\TestCase;

final class AltchaCaptchaTest extends TestCase
{
    public function test_a_solved_payload_takes_a_quarter_of_the_cap_at_most(): void
    {
        $this->assertLessThanOrEqual(intdiv(AltchaCaptcha::MAX_PAYLOAD_BYTES, 4), strlen($this->solved($this->captcha())));
    }

    /** Padded with a field verify() ignores, so both payloads are still solved ones. */
    public function test_a_solved_payload_past_the_length_cap_is_refused(): void
    {
        $captcha = $this->captcha();

        $this->assertFalse($captcha->verify($this->padded($this->solved($captcha), AltchaCaptcha::MAX_PAYLOAD_BYTES + 4)));
        $this->assertTrue($captcha->verify($this->padded($this->solved($captcha), AltchaCaptcha::MAX_PAYLOAD_BYTES)));
    }

    private function captcha(): AltchaCaptcha
    {
        return new AltchaCaptcha('test-key', cost: 100, maxNumber: 100, expiresSeconds: 600);
    }

    private function solved(AltchaCaptcha $captcha): string
    {
        $challenge = Challenge::fromArray($captcha->challenge());
        $solution = (new Altcha)->solveChallenge(new SolveChallengeOptions(challenge: $challenge, algorithm: new Pbkdf2));

        return (new Payload($challenge, $solution))->toBase64();
    }

    private function padded(string $payload, int $length): string
    {
        $data = json_decode(base64_decode($payload, true), true) + ['padding' => ''];
        $data['padding'] = str_repeat('x', intdiv($length, 4) * 3 - strlen((string) json_encode($data)));
        $padded = base64_encode((string) json_encode($data));

        $this->assertSame($length, strlen($padded));

        return $padded;
    }
}
