<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use PHPUnit\Framework\TestCase;

/** Registry self-consistency: every kind is fully specified and the imported key mapping is well-formed. */
class NotificationKindTest extends TestCase
{
    public function test_op3_names_are_unique(): void
    {
        $names = array_map(static fn (NotificationKind $kind): string => $kind->definition()->op3Name, NotificationKind::cases());

        $this->assertSame($names, array_values(array_unique($names)));
    }

    public function test_op3_config_names_follow_the_openpne3_construction(): void
    {
        $this->assertSame('is_send_diaryNewPost_web', NotificationKind::DiaryNewPost->op3ConfigName(NotificationChannel::Web));
        $this->assertSame('is_send_pc_diaryNewPost_mail', NotificationKind::DiaryNewPost->op3ConfigName(NotificationChannel::Mail));
        $this->assertSame('is_send_messageNewOnlyFriends_web', NotificationKind::MessageNewOnlyFriends->op3ConfigName(NotificationChannel::Web));
        $this->assertSame('is_send_pc_communityTopicReplyNewPost_mail', NotificationKind::CommunityTopicReplyNewPost->op3ConfigName(NotificationChannel::Mail));
    }

    public function test_config_names_are_unique_across_kinds_and_channels(): void
    {
        $names = [];
        foreach (NotificationKind::cases() as $kind) {
            foreach (NotificationChannel::cases() as $channel) {
                $names[] = $kind->op3ConfigName($channel);
            }
        }

        $this->assertSame($names, array_values(array_unique($names)));
    }

    public function test_depend_on_not_targets_are_broad_kinds_in_the_same_category(): void
    {
        foreach (NotificationKind::cases() as $kind) {
            $target = $kind->dependOnNot();
            if ($target === null) {
                continue;
            }

            $this->assertSame($kind->category(), $target->category(), "{$kind->value} depends across categories");
            $this->assertNull($target->dependOnNot(), "{$kind->value} depends on a kind that itself depends (no chains)");
        }
    }

    public function test_wired_kinds_are_the_expected_set(): void
    {
        $this->assertSame(
            [
                NotificationKind::DiaryReplyPost,
                NotificationKind::DiaryRelatedPost,
                NotificationKind::CommunityTopicReplyNewPost,
                NotificationKind::CommunityTopicRelatedNewPost,
                NotificationKind::CommunityEventReplyNewPost,
                NotificationKind::CommunityEventRelatedNewPost,
                NotificationKind::FriendLinkConfirm,
                NotificationKind::FriendLinkComplete,
                NotificationKind::MessageNew,
                NotificationKind::MessageNewOnlyFriends,
            ],
            NotificationKind::wiredCases(),
        );
    }

    public function test_every_kind_defaults_enabled(): void
    {
        // Imported kinds must default on (an absent source key meant enabled); flipping one is a
        // deliberate one-arm change, never an accident.
        foreach (NotificationKind::cases() as $kind) {
            $this->assertTrue($kind->defaultEnabled(), "{$kind->value} should default on");
        }
    }

    public function test_every_kind_has_a_caption_source_string(): void
    {
        foreach (NotificationKind::cases() as $kind) {
            $this->assertNotSame('', $kind->definition()->caption);
        }
    }
}
