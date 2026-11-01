<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Features\Block\Actions\BlockMember;
use App\Mcp\McpAbilities;
use App\Mcp\Servers\OpenPneServer;
use App\Mcp\Tools\ReadMemberProfileTool;
use App\Models\Member;
use App\Models\MemberProfile;
use App\Models\Profile;
use App\Support\Visibility;
use Laravel\Sanctum\Sanctum;

class MemberProfileToolTest extends McpTestCase
{
    private Profile $bio;

    private Profile $hobby;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bio = Profile::factory()->preset('self_introduction')->create(['form_type' => 'textarea', 'sort_order' => 1]);
        $this->hobby = Profile::factory()->create(['sort_order' => 2]);
        $this->hobby->setTranslation('ja_JP', 'Hobby');
    }

    private function says(Member $member, Profile $field, string $value, Visibility $visibility = Visibility::Members): void
    {
        MemberProfile::factory()->create([
            'member_id' => $member->getKey(), 'profile_id' => $field->getKey(), 'value' => $value, 'visibility' => $visibility,
        ]);
    }

    public function test_an_ai_account_reads_its_own_self_introduction_by_default(): void
    {
        $owner = Member::factory()->create();
        $bot = Member::factory()->aiAccount($owner)->create(['name' => 'Translator']);
        $this->says($bot, $this->bio, 'I translate Japanese and English posts.');
        Sanctum::actingAs($bot, [McpAbilities::READ]);

        OpenPneServer::tool(ReadMemberProfileTool::class)
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('member.id', $bot->getKey())
                ->where('member.name', 'Translator')
                ->where('member.isAi', true)
                ->where('member.isYou', true)
                ->where('member.selfIntroduction', 'I translate Japanese and English posts.')
                ->where('member.fields', []));
    }

    public function test_another_members_profile_shows_only_the_fields_you_may_see(): void
    {
        $subject = Member::factory()->create();
        $this->says($subject, $this->bio, 'Hello');
        $this->says($subject, $this->hobby, 'Climbing');
        $private = Profile::factory()->create(['sort_order' => 3]);
        $this->says($subject, $private, 'Hidden', Visibility::Private);
        Sanctum::actingAs(Member::factory()->create(), [McpAbilities::READ]);

        OpenPneServer::tool(ReadMemberProfileTool::class, ['member_id' => $subject->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->where('member.isYou', false)
                ->where('member.selfIntroduction', 'Hello')
                ->where('member.fields', [['caption' => 'Hobby', 'value' => 'Climbing']])
                ->etc());
    }

    public function test_the_fields_are_read_in_the_callers_own_language(): void
    {
        $this->hobby->setTranslation('en', 'Pastime');
        $subject = Member::factory()->create();
        $this->says($subject, $this->hobby, 'Climbing');
        Sanctum::actingAs(Member::factory()->create(['locale' => 'en']), [McpAbilities::READ]);

        OpenPneServer::tool(ReadMemberProfileTool::class, ['member_id' => $subject->getKey()])
            ->assertStructuredContent(fn ($json) => $json->where('member.fields', [['caption' => 'Pastime', 'value' => 'Climbing']])->etc());
    }

    public function test_a_member_who_blocks_you_does_not_exist(): void
    {
        $subject = Member::factory()->create();
        $viewer = Member::factory()->create();
        app(BlockMember::class)($subject, $viewer);
        Sanctum::actingAs($viewer, [McpAbilities::READ]);

        OpenPneServer::tool(ReadMemberProfileTool::class, ['member_id' => $subject->getKey()])
            ->assertHasErrors(['No such member']);
        OpenPneServer::tool(ReadMemberProfileTool::class, ['member_id' => 999999])
            ->assertHasErrors(['No such member']);
    }

    public function test_the_tool_is_listed_at_the_endpoint(): void
    {
        $member = Member::factory()->create();

        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], ['Authorization' => 'Bearer '.$this->token($member, [McpAbilities::READ])])
            ->assertOk()
            ->assertSee('read-member-profile');
    }
}
