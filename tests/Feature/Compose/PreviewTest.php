<?php

namespace Tests\Feature\Compose;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->post('/compose/preview', ['body' => 'hi'])->assertRedirect('/login');
    }

    public function test_it_returns_rendered_markdown_html(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->postJson('/compose/preview', ['body' => '**bold**'])->assertOk();

        $this->assertStringContainsString('<strong>bold</strong>', $response->json('html'));
    }

    public function test_it_sanitizes_the_preview_like_a_stored_body(): void
    {
        $member = Member::factory()->create();

        $response = $this->actingAs($member)->postJson('/compose/preview', ['body' => '<script>alert(1)</script>'])->assertOk();

        $this->assertStringNotContainsString('<script', $response->json('html'));
    }

    public function test_it_requires_a_body(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->postJson('/compose/preview', [])->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_a_body_over_the_text_column_byte_cap_is_rejected(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->postJson('/compose/preview', ['body' => str_repeat('a', 65536)])
            ->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_it_throttles_after_the_per_member_cap(): void
    {
        // Lower the per-member limit; keep the per-IP limb loose so the member cap is what trips.
        config(['openpne.throttle.preview' => 2, 'openpne.throttle.preview_ip' => 1000]);
        $member = Member::factory()->create();

        $this->actingAs($member)->postJson('/compose/preview', ['body' => 'a'])->assertOk();
        $this->actingAs($member)->postJson('/compose/preview', ['body' => 'a'])->assertOk();
        $this->actingAs($member)->postJson('/compose/preview', ['body' => 'a'])->assertStatus(429);
    }
}
