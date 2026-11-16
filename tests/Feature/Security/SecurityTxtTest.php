<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Controllers\SecurityTxtController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SecurityTxtTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_reads_the_file_as_plain_text(): void
    {
        $response = $this->get('/.well-known/security.txt');

        $response->assertOk();
        $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('max-age=86400, public', $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies(), 'a publicly cacheable reply sets no cookie');
        $this->assertSame(
            [
                'Contact' => SecurityTxtController::CONTACT,
                'Policy' => SecurityTxtController::POLICY,
                'Preferred-Languages' => 'en, ja',
            ],
            array_diff_key($this->fields($response->getContent()), ['Expires' => true]),
        );
    }

    public function test_expires_is_six_months_ahead_in_utc(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        Carbon::setTestNow(Carbon::parse('2026-11-16 12:34:56', 'Asia/Tokyo'));

        try {
            $fields = $this->fields($this->get('/.well-known/security.txt')->getContent());
        } finally {
            date_default_timezone_set($zone);
        }

        $this->assertSame('2027-05-16T03:34:56Z', $fields['Expires']);
    }

    /**
     * @return array<string, string>
     */
    private function fields(string $body): array
    {
        $fields = [];
        foreach (explode("\n", trim($body)) as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $this->assertArrayNotHasKey($name, $fields, "{$name} appears twice");
            $fields[$name] = $value;
        }

        return $fields;
    }
}
