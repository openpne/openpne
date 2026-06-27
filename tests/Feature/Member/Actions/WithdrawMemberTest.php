<?php

declare(strict_types=1);

namespace Tests\Feature\Member\Actions;

use App\Features\Community\CommunityRole;
use App\Features\Member\Actions\WithdrawMember;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryImage;
use App\Models\File;
use App\Models\Member;
use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WithdrawMemberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Reserve id 1 as the un-withdrawable primary member so factory subjects below get id >= 2.
        Member::factory()->create(['id' => 1]);
    }

    private function withdraw(Member $member): void
    {
        app(WithdrawMember::class)($member);
    }

    public function test_deletes_the_member_and_cascade_owned_rows(): void
    {
        $member = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);
        $membership = CommunityMember::factory()->create(['member_id' => $member->getKey()]);

        $this->withdraw($member);

        $this->assertModelMissing($member);
        $this->assertModelMissing($diary);
        $this->assertModelMissing($membership);
    }

    public function test_purges_image_bytes_of_owned_diaries_and_timeline_posts(): void
    {
        $member = Member::factory()->create();

        $diary = Diary::factory()->create(['member_id' => $member->getKey()]);
        $diaryImage = DiaryImage::factory()->create(['diary_id' => $diary->getKey()]);
        $post = TimelinePost::factory()->create(['member_id' => $member->getKey()]);
        $postImage = TimelinePostImage::factory()->create(['timeline_post_id' => $post->getKey()]);

        $diaryFile = File::findOrFail($diaryImage->file_id);
        $postFile = File::findOrFail($postImage->file_id);

        $this->withdraw($member);

        $this->assertModelMissing($diaryFile);
        $this->assertModelMissing($postFile);
    }

    public function test_retains_set_null_content_with_a_null_author(): void
    {
        $member = Member::factory()->create();
        $author = Member::factory()->create();

        // The withdrawing member's comment on someone else's diary stays (null author).
        $othersDiary = Diary::factory()->create(['member_id' => $author->getKey()]);
        $comment = DiaryComment::factory()->create([
            'diary_id' => $othersDiary->getKey(),
            'member_id' => $member->getKey(),
        ]);

        // A message they sent stays for the recipient's copy.
        $message = Message::factory()->create(['sender_id' => $member->getKey()]);
        $receipt = MessageRecipient::factory()->create(['message_id' => $message->getKey()]);

        $this->withdraw($member);

        $this->assertDatabaseHas('diary_comments', ['id' => $comment->getKey(), 'member_id' => null]);
        $this->assertDatabaseHas('messages', ['id' => $message->getKey(), 'sender_id' => null]);
        $this->assertModelExists($receipt);
    }

    public function test_sole_admin_community_with_other_members_hands_over_to_oldest(): void
    {
        $admin = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $admin->getKey(),
            'role' => CommunityRole::Admin,
        ]);
        // Two ordinary members; the oldest membership row should be promoted.
        $oldest = CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'role' => CommunityRole::Member,
        ]);
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'role' => CommunityRole::Member,
        ]);

        $this->withdraw($admin);

        $this->assertModelExists($community);
        $oldest->refresh();
        $this->assertSame(CommunityRole::Admin, $oldest->role);
    }

    public function test_sub_admin_is_not_treated_as_admin_and_is_promoted(): void
    {
        $admin = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $admin->getKey(),
            'role' => CommunityRole::Admin,
        ]);
        // A SubAdmin is not an admin: with the sole Admin leaving, it must be promoted, not skipped.
        $subAdmin = CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'role' => CommunityRole::SubAdmin,
        ]);

        $this->withdraw($admin);

        $this->assertModelExists($community);
        $subAdmin->refresh();
        $this->assertSame(CommunityRole::Admin, $subAdmin->role);
    }

    public function test_sole_admin_community_with_no_other_members_is_deleted(): void
    {
        $admin = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $admin->getKey(),
            'role' => CommunityRole::Admin,
        ]);

        $this->withdraw($admin);

        $this->assertModelMissing($community);
    }

    public function test_community_with_another_admin_is_kept_without_promotion(): void
    {
        $leaving = Member::factory()->create();
        $community = Community::factory()->create();
        CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'member_id' => $leaving->getKey(),
            'role' => CommunityRole::Admin,
        ]);
        $other = CommunityMember::factory()->create([
            'community_id' => $community->getKey(),
            'role' => CommunityRole::Admin,
        ]);

        $this->withdraw($leaving);

        $this->assertModelExists($community);
        $other->refresh();
        $this->assertSame(CommunityRole::Admin, $other->role); // unchanged
    }

    public function test_primary_member_cannot_be_withdrawn(): void
    {
        $primary = Member::findOrFail(1);

        try {
            $this->withdraw($primary);
            $this->fail('Expected the primary member to be un-withdrawable.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertModelExists($primary);
    }
}
