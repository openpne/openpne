<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityTxtTest extends TestCase
{
    use RefreshDatabase;

    private const ADVISORY_FORM = 'https://github.com/openpne/openpne/security/advisories/new';

    public function test_a_guest_reads_the_file_as_plain_text(): void
    {
        $response = $this->get('/.well-known/security.txt');

        $response->assertOk();
        $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('max-age=86400, public', $response->headers->get('Cache-Control'));
        $this->assertSame([], $response->headers->getCookies(), 'a fetch sets no cookie');
        $this->assertSame(
            [
                'Contact' => self::ADVISORY_FORM,
                'Policy' => 'https://github.com/openpne/openpne/blob/main/SECURITY.md',
                'Preferred-Languages' => 'en, ja',
            ],
            array_diff_key($this->fields($response->getContent()), ['Expires' => true]),
        );
    }

    public function test_a_fetch_writes_no_session_row(): void
    {
        // The suite's array driver would keep a started session out of the database, so the database
        // driver is what makes the absence observable.
        config(['session.driver' => 'database']);

        $this->get('/.well-known/security.txt')->assertOk();

        $this->assertSame(0, DB::table(config('session.table'))->count());
    }

    public function test_the_policy_names_the_contact_the_file_gives(): void
    {
        $this->assertStringContainsString('<'.self::ADVISORY_FORM.'>', (string) file_get_contents(base_path('SECURITY.md')));
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
