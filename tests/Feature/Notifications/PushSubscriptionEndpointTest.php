<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Minishlink\WebPush\VAPID;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesWebPushTransport;
use Tests\TestCase;

/**
 * The one endpoint that takes a URL this site will later POST to, so its validation is the ingress
 * half of docs/internals/outbound-http.md#the-push-endpoint-seam — and its store is writable by
 * anyone signed in, hence the cap and the throttle.
 */
class PushSubscriptionEndpointTest extends TestCase
{
    use FakesWebPushTransport;
    use RefreshDatabase;

    private const ENDPOINT = 'https://push.example.com/subscription/abc';

    /** A real base64url P-256 point, generated once — keygen is slow to repeat per test. */
    private static ?string $validP256dh = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureVapid();
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->post('/push/subscriptions', $this->payload())->assertRedirect('/login');
    }

    public function test_the_endpoints_do_not_exist_without_a_vapid_keypair(): void
    {
        config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/push/subscriptions', $this->payload())->assertNotFound();
        $this->actingAs($member)->post('/push/subscriptions/delete', ['endpoint' => self::ENDPOINT])->assertNotFound();
    }

    public function test_a_device_registers_itself(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/push/subscriptions', $this->payload())->assertNoContent();

        $subscription = $member->pushSubscriptions()->sole();
        $this->assertSame(self::ENDPOINT, $subscription->endpoint);
        $this->assertSame('aes128gcm', $subscription->content_encoding->value);
    }

    public function test_the_same_endpoint_re_registering_updates_its_row(): void
    {
        $member = Member::factory()->create();
        $this->actingAs($member)->post('/push/subscriptions', $this->payload())->assertNoContent();
        $original = $member->pushSubscriptions()->sole();

        $rotated = $this->payload(auth: str_repeat('b', 22));
        $this->actingAs($member)->post('/push/subscriptions', $rotated)->assertNoContent();

        $subscription = $member->pushSubscriptions()->sole();
        $this->assertSame($original->getKey(), $subscription->getKey());
        $this->assertSame($rotated['keys']['auth'], $subscription->auth_token);
    }

    /**
     * The endpoint is the subscription's identity, unique across the table, so a shared device
     * signing in as someone else moves the row rather than leaving two owners for one browser.
     */
    public function test_re_registering_someone_elses_endpoint_moves_it(): void
    {
        $first = Member::factory()->create();
        $second = Member::factory()->create();
        $this->actingAs($first)->post('/push/subscriptions', $this->payload())->assertNoContent();

        $this->actingAs($second)->post('/push/subscriptions', $this->payload())->assertNoContent();

        $this->assertSame(0, $first->pushSubscriptions()->count());
        $this->assertSame(self::ENDPOINT, $second->pushSubscriptions()->sole()->endpoint);
    }

    public function test_registering_past_the_cap_drops_the_oldest_devices(): void
    {
        $member = Member::factory()->create();

        for ($i = 1; $i <= 11; $i++) {
            $this->travelTo(now()->addMinutes($i));
            $this->actingAs($member)
                ->post('/push/subscriptions', $this->payload("https://push.example.com/device/{$i}"))
                ->assertNoContent();
        }

        $endpoints = $member->pushSubscriptions()->orderBy('created_at')->pluck('endpoint')->all();
        $this->assertCount(10, $endpoints);
        $this->assertSame('https://push.example.com/device/2', $endpoints[0]);
        $this->assertSame('https://push.example.com/device/11', $endpoints[9]);
    }

    public function test_the_store_is_throttled_per_member(): void
    {
        $member = Member::factory()->create();
        // A rejected body still spends a hit — the limiter runs before validation.
        $invalid = $this->payload('http://push.example.com/plain');

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($member)->post('/push/subscriptions', $invalid)->assertInvalid('endpoint');
        }

        $this->actingAs($member)->post('/push/subscriptions', $invalid)->assertStatus(429);
    }

    /** @return array<string, array{string}> */
    public static function rejectedEndpoints(): array
    {
        return [
            'plain http' => ['http://push.example.com/s'],
            'embedded credentials' => ['https://user:pass@push.example.com/s'],
            'IPv4 literal' => ['https://127.0.0.1/s'],
            'IPv6 literal' => ['https://[::1]/s'],
            'single-label host' => ['https://intranet/s'],
            'non-default port' => ['https://push.example.com:8443/s'],
            'no host' => ['https:///s'],
            'not a URL' => ['nonsense'],
            'non-ASCII path' => ['https://push.example.com/送信'],
            'over the length bound' => ['https://push.example.com/'.str_repeat('x', 1000)],
        ];
    }

    #[DataProvider('rejectedEndpoints')]
    public function test_an_endpoint_this_site_will_not_post_to_is_rejected(string $endpoint): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->post('/push/subscriptions', $this->payload($endpoint))
            ->assertInvalid('endpoint');

        $this->assertSame(0, $member->pushSubscriptions()->count());
    }

    /**
     * Real push services issue endpoints past 500 characters. The bound is the column's width, on
     * every engine: a stored endpoint reads back whole, and the unsubscribe takes the same length.
     */
    public function test_an_endpoint_at_the_length_bound_registers_and_unsubscribes(): void
    {
        $member = Member::factory()->create();
        $endpoint = str_pad('https://push.example.com/', 1024, 'x');
        $this->assertSame(1024, strlen($endpoint));

        $this->actingAs($member)->post('/push/subscriptions', $this->payload($endpoint))->assertNoContent();
        $this->assertSame($endpoint, $member->pushSubscriptions()->sole()->endpoint);

        $this->actingAs($member)->post('/push/subscriptions/delete', ['endpoint' => $endpoint])->assertNoContent();
        $this->assertSame(0, $member->pushSubscriptions()->count());
    }

    /** @return array<string, array{string, string}> */
    public static function rejectedKeys(): array
    {
        return [
            'p256dh outside base64url' => ['keys.p256dh', 'not base64url!!'],
            'p256dh too short' => ['keys.p256dh', str_repeat('k', 43)],
            // 65 base64url bytes that clear the length gate but are not a P-256 point.
            'p256dh 65 bytes, wrong prefix' => ['keys.p256dh', self::base64Url(str_repeat("\x01", 65))],
            'p256dh 0x04 but off-curve' => ['keys.p256dh', self::base64Url("\x04".str_repeat("\x00", 64))],
            'auth too long' => ['keys.auth', str_repeat('a', 43)],
            'auth empty' => ['keys.auth', ''],
        ];
    }

    #[DataProvider('rejectedKeys')]
    public function test_a_key_that_would_only_fail_inside_the_encryption_is_rejected(string $field, string $value): void
    {
        $member = Member::factory()->create();
        $payload = $this->payload();
        data_set($payload, $field, $value);

        $this->actingAs($member)->post('/push/subscriptions', $payload)->assertInvalid($field);

        $this->assertSame(0, $member->pushSubscriptions()->count());
    }

    public function test_unsubscribing_touches_only_the_callers_own_device(): void
    {
        $member = Member::factory()->create();
        $other = Member::factory()->create();
        $this->actingAs($member)->post('/push/subscriptions', $this->payload())->assertNoContent();
        $this->actingAs($other)->post('/push/subscriptions', $this->payload('https://push.example.com/other'))->assertNoContent();

        $this->actingAs($member)
            ->post('/push/subscriptions/delete', ['endpoint' => 'https://push.example.com/other'])
            ->assertNoContent();
        $this->assertSame(1, $other->pushSubscriptions()->count());

        $this->actingAs($member)
            ->post('/push/subscriptions/delete', ['endpoint' => self::ENDPOINT])
            ->assertNoContent();
        $this->assertSame(0, $member->pushSubscriptions()->count());
    }

    /**
     * With no VAPID keypair the store does not exist, so the 404 gate has to win over an invalid
     * body — otherwise a bad request on an unconfigured site leaks a 422 for an absent endpoint.
     */
    public function test_an_unconfigured_site_404s_before_it_validates_the_body(): void
    {
        config(['webpush.vapid.public_key' => '', 'webpush.vapid.private_key' => '']);
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->post('/push/subscriptions', $this->payload('http://push.example.com/plain'))
            ->assertNotFound();
    }

    /**
     * The endpoint is byte-exact identity: two that differ only in path case are two devices, not a
     * collision that transfers one over the other. Only MySQL needs forcing — utf8mb4_bin makes its
     * default case-insensitive collation binary; SQLite TEXT is already BINARY.
     */
    public function test_endpoints_differing_only_in_case_are_distinct_devices(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Endpoint case-sensitivity is a MySQL collation concern; SQLite TEXT is already binary.');
        }

        $member = Member::factory()->create();
        $lower = 'https://push.example.com/device/token';
        $upper = 'https://push.example.com/device/TOKEN';

        $this->actingAs($member)->post('/push/subscriptions', $this->payload($lower))->assertNoContent();
        $this->actingAs($member)->post('/push/subscriptions', $this->payload($upper))->assertNoContent();

        $endpoints = $member->pushSubscriptions()->pluck('endpoint')->all();
        $this->assertCount(2, $endpoints);
        $this->assertContains($lower, $endpoints);
        $this->assertContains($upper, $endpoints);
    }

    /** @return array<string, mixed> */
    private function payload(string $endpoint = self::ENDPOINT, string $auth = 'YWJjZGVmZ2hpamtsbW5vcA'): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => $this->validP256dh(), 'auth' => $auth],
        ];
    }

    /** The real uncompressed P-256 point a browser hands over — an on-curve value the ingress accepts. */
    private function validP256dh(): string
    {
        return self::$validP256dh ??= VAPID::createVapidKeys()['publicKey'];
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
