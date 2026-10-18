<?php

namespace Tests\Feature\Http;

use App\Models\Member;
use App\Models\RegistrationToken;
use App\Support\Housekeeping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TickHousekeepingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    public function test_the_first_signed_in_request_of_the_day_prunes_expired_tokens(): void
    {
        $ttl = (int) config('openpne.registration.token_ttl_minutes');
        $expired = RegistrationToken::query()->create(['email' => 'old@example.com', 'token' => str_repeat('a', 64), 'source' => 'self', 'created_at' => now()->subMinutes($ttl + 1)]);
        $live = RegistrationToken::query()->create(['email' => 'new@example.com', 'token' => str_repeat('b', 64), 'source' => 'self', 'created_at' => now()]);

        $this->actingAs(Member::factory()->create())->get('/dashboard');

        $this->assertDatabaseMissing('registration_tokens', ['id' => $expired->getKey()]);
        $this->assertDatabaseHas('registration_tokens', ['id' => $live->getKey()]);
    }

    public function test_it_runs_once_per_site_day_and_not_for_guests(): void
    {
        $this->mock(Housekeeping::class)->shouldReceive('run')->twice()->andReturn(0);
        $member = Member::factory()->create();

        $this->get('/login');
        $this->actingAs($member)->get('/dashboard');
        $this->actingAs($member)->get('/dashboard');
        $this->travel(1)->days();
        $this->actingAs($member)->get('/dashboard');
    }
}
