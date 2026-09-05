<?php

namespace Tests\Feature\DirectMessage\Conversation;

use App\Models\Member;
use App\Support\SnsSettingKey;

class ConversationGateTest extends ConversationTestCase
{
    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $other = Member::factory()->create();

        $this->get("/messages/{$other->getKey()}")->assertRedirect('/login');
        $this->get("/messages/{$other->getKey()}/messages")->assertRedirect('/login');
        $this->post("/messages/{$other->getKey()}/read", ['messageId' => 1])->assertRedirect('/login');
        $this->get('/messages/withdrawn')->assertRedirect('/login');
        $this->get('/messages/withdrawn/messages')->assertRedirect('/login');
        $this->post('/messages/withdrawn/read', ['messageId' => 1])->assertRedirect('/login');
    }

    public function test_the_unit_switched_off_takes_every_conversation_route(): void
    {
        [$viewer, $other] = Member::factory()->count(2)->create();
        $this->setSnsSetting(SnsSettingKey::FeatureDirectMessageEnabled, false);

        $this->actingAs($viewer)->get("/messages/{$other->getKey()}")->assertNotFound();
        $this->actingAs($viewer)->getJson("/messages/{$other->getKey()}/messages")->assertNotFound();
        $this->actingAs($viewer)->postJson("/messages/{$other->getKey()}/read", ['messageId' => 1])->assertNotFound();
        $this->actingAs($viewer)->get('/messages/withdrawn')->assertNotFound();
        $this->actingAs($viewer)->getJson('/messages/withdrawn/messages')->assertNotFound();
        $this->actingAs($viewer)->postJson('/messages/withdrawn/read', ['messageId' => 1])->assertNotFound();
    }

    /** There is no room to be in with yourself (OpenPNE 3 404s a self-addressed message). */
    public function test_a_conversation_with_yourself_is_refused(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get("/messages/{$viewer->getKey()}")->assertNotFound();
        $this->actingAs($viewer)->getJson("/messages/{$viewer->getKey()}/messages")->assertNotFound();
        $this->actingAs($viewer)->postJson("/messages/{$viewer->getKey()}/read", ['messageId' => 1])->assertNotFound();
    }

    public function test_a_member_who_does_not_exist_is_refused(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get('/messages/999999')->assertNotFound();
        $this->actingAs($viewer)->getJson('/messages/999999/messages')->assertNotFound();
    }

    /** The literal is declared first, so it is never read as a member id. */
    public function test_the_withdrawn_bucket_is_reachable_by_its_own_name(): void
    {
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get('/messages/withdrawn')->assertOk();
        $this->actingAs($viewer)->getJson('/messages/withdrawn/messages')->assertOk();
    }
}
