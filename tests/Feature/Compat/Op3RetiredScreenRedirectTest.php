<?php

namespace Tests\Feature\Compat;

use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Op3RetiredScreenRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get('/confirmation')->assertRedirect('/login');
        $this->get('/message/chain/5')->assertRedirect('/login');
        $this->get('/message/smtChain?id=5')->assertRedirect('/login');
    }

    public function test_the_confirmation_center_lands_on_the_friend_requests_by_default(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get('/confirmation')->assertRedirect(route('friend.requests'));
        $this->actingAs($viewer)->get('/confirmation?category=friend_confirm')->assertRedirect(route('friend.requests'));
    }

    /** @return array<string, array{string}> */
    public static function groupCategories(): array
    {
        return [
            'join requests' => ['community_confirm'],
            'admin transfer' => ['community_admin_request'],
            'sub-admin nomination' => ['community_sub_admin_request'],
        ];
    }

    #[DataProvider('groupCategories')]
    public function test_a_group_category_lands_where_the_group_cautions_are(string $category): void
    {
        $viewer = Member::factory()->create();

        config(['openpne.surface_mode' => 'classic_default']);
        $this->actingAs($viewer)->get("/confirmation?category={$category}")->assertRedirect(route('home'));

        config(['openpne.surface_mode' => 'modern_default']);
        $this->actingAs($viewer)->get("/confirmation?category={$category}")->assertRedirect(route('dashboard'));
    }

    public function test_an_unknown_confirmation_category_is_not_found(): void
    {
        $this->actingAs(Member::factory()->create())->get('/confirmation?category=nope')->assertNotFound();
    }

    public function test_the_smartphone_message_thread_urls_land_on_the_conversation_with_that_member(): void
    {
        $viewer = Member::factory()->create();
        $other = Member::factory()->create();

        $this->actingAs($viewer)->get("/message/chain/{$other->id}")
            ->assertRedirect(route('message.compose', ['id' => $other->id]));
        $this->actingAs($viewer)->get("/message/smtChain?id={$other->id}")
            ->assertRedirect(route('message.compose', ['id' => $other->id]));

        config(['openpne.surface_mode' => 'modern_default']);
        $this->actingAs($viewer)->get(route('message.compose', ['id' => $other->id]))
            ->assertRedirect(route('message.chat.show', ['member' => $other->id]));
    }
}
