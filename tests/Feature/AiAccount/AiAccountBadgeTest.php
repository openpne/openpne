<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Features\Member\Serializers\MemberRefSerializer;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DirectMessage;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The AI fact reaches every surface a member is drawn on, because every author reference is one
 * shape ({@see MemberRefSerializer}). One assertion per surface, paired with a human on the same
 * page: what would break this is a serializer that went back to assembling a reference of its own,
 * and the pairing is what catches a field hard-coded true.
 */
class AiAccountBadgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['openpne.surface_mode' => 'modern_default']);
    }

    /** An AI account and its owner, both joined to $group. */
    private function pairIn(Group $group): array
    {
        $human = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($human)->create();

        foreach ([$human, $aiAccount] as $member) {
            GroupMember::factory()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey()]);
        }

        return [$human, $aiAccount];
    }

    public function test_a_talk_message_says_which_of_its_authors_is_an_ai_account(): void
    {
        $group = Group::factory()->create();
        [$human, $aiAccount] = $this->pairIn($group);

        foreach ([$human, $aiAccount] as $author) {
            GroupMessage::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey()]);
        }

        $this->actingAs($human)
            ->get("/groups/{$group->getKey()}/talk")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('page.messages.0.author.isAi', false)
                ->where('page.messages.1.author.isAi', true));
    }

    public function test_a_timeline_post_says_so(): void
    {
        $human = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($human)->create();

        foreach ([$human, $aiAccount] as $author) {
            TimelinePost::factory()->create(['member_id' => $author->getKey(), 'visibility' => Visibility::Members]);
        }

        $this->actingAs($human)
            ->get('/timeline')
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Newest first, so the AI account's post leads.
                ->where('posts.data.0.author.isAi', true)
                ->where('posts.data.1.author.isAi', false));
    }

    public function test_a_diary_and_its_comments_say_so(): void
    {
        $human = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($human)->create();
        $diary = Diary::factory()->create(['member_id' => $aiAccount->getKey()]);
        DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'member_id' => $human->getKey(), 'number' => 1]);
        DiaryComment::factory()->create(['diary_id' => $diary->getKey(), 'member_id' => $aiAccount->getKey(), 'number' => 2]);

        $this->actingAs($human)
            ->get("/diary/{$diary->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('diary.author.isAi', true)
                ->where('comments.0.author.isAi', false)
                ->where('comments.1.author.isAi', true));
    }

    public function test_a_conversation_message_says_so(): void
    {
        $human = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($human)->create();
        $message = DirectMessage::factory()->create(['sender_id' => $aiAccount->getKey()]);
        $message->recipients()->create(['recipient_id' => $human->getKey()]);

        $this->actingAs($human)
            ->get("/messages/{$aiAccount->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('page.messages.0.author.isAi', true)
                ->where('counterpart.isAi', true));
    }

    public function test_a_search_result_says_so(): void
    {
        $viewer = Member::factory()->create();
        $human = Member::factory()->create(['name' => 'Kaoru Human']);
        Member::factory()->aiAccount($human)->create(['name' => 'Shirabe Agent']);

        // A name search each, so the row asserted on is the one named rather than the one that
        // happened to sort first.
        $this->actingAs($viewer)
            ->get('/member/search?name=Shirabe')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('members.data', 1)
                ->where('members.data.0.isAi', true));

        $this->actingAs($viewer)
            ->get('/member/search?name=Kaoru')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('members.data', 1)
                ->where('members.data.0.isAi', false));
    }

    public function test_a_profile_page_says_so(): void
    {
        $human = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($human)->create();

        $this->actingAs($human)
            ->get("/member/{$aiAccount->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('profile.owner.isAi', true));

        $this->actingAs($human)
            ->get("/member/{$human->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('profile.owner.isAi', false));
    }

    public function test_the_signed_in_member_shares_the_reference_shape(): void
    {
        // Never true in practice — an AI account has no credentials — but the shell's own avatar is
        // drawn by the same component as every other, and it has to have an answer to give it.
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->get('/timeline')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.isAi', false));
    }
}
