<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use LogicException;
use PHPUnit\Framework\TestCase;

/** Registry self-consistency: every kind is fully specified and the imported key mapping is well-formed. */
class NotificationKindTest extends TestCase
{
    public function test_op3_names_are_unique(): void
    {
        $names = array_map(
            static fn (NotificationKind $kind): ?string => $kind->definition()->op3Name,
            NotificationKind::importableCases(),
        );

        $this->assertSame($names, array_values(array_unique($names)));
    }

    public function test_importable_cases_are_exactly_the_kinds_carrying_a_source_name(): void
    {
        $importable = NotificationKind::importableCases();

        foreach (NotificationKind::cases() as $kind) {
            $this->assertSame(
                $kind->definition()->op3Name !== null,
                in_array($kind, $importable, true),
                "{$kind->value} is on the wrong side of the import boundary",
            );
        }
    }

    public function test_a_native_kind_has_no_op3_config_name(): void
    {
        // Asking for one is a caller that forgot to select with importableCases(), not a fallback
        // to invent a key from — a made-up name would silently migrate nothing.
        $this->expectException(LogicException::class);

        NotificationKind::TimelineMention->op3ConfigName(NotificationChannel::Web);
    }

    public function test_op3_config_names_follow_the_openpne3_construction(): void
    {
        $this->assertSame('is_send_diaryNewPost_web', NotificationKind::DiaryNewPost->op3ConfigName(NotificationChannel::Web));
        $this->assertSame('is_send_pc_diaryNewPost_mail', NotificationKind::DiaryNewPost->op3ConfigName(NotificationChannel::Mail));
        $this->assertSame('is_send_messageNewOnlyFriends_web', NotificationKind::DirectMessageNewOnlyFriends->op3ConfigName(NotificationChannel::Web));
        $this->assertSame('is_send_pc_communityTopicReplyNewPost_mail', NotificationKind::GroupTopicReplyNewPost->op3ConfigName(NotificationChannel::Mail));
    }

    public function test_config_names_are_unique_across_kinds_and_channels(): void
    {
        $names = [];
        foreach (NotificationKind::importableCases() as $kind) {
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
                NotificationKind::TimelineNewPost,
                NotificationKind::TimelineNewPostOnlyFriends,
                NotificationKind::TimelineNewPostCommunity,
                NotificationKind::TimelineReplyPost,
                NotificationKind::TimelineRelatedPost,
                NotificationKind::TimelineMention,
                NotificationKind::DiaryNewPost,
                NotificationKind::DiaryNewPostOnlyFriends,
                NotificationKind::DiaryReplyPost,
                NotificationKind::DiaryRelatedPost,
                NotificationKind::GroupTopicNewPost,
                NotificationKind::GroupTopicCommentNewPost,
                NotificationKind::GroupTopicReplyNewPost,
                NotificationKind::GroupTopicRelatedNewPost,
                NotificationKind::GroupEventNewPost,
                NotificationKind::GroupEventCommentNewPost,
                NotificationKind::GroupEventReplyNewPost,
                NotificationKind::GroupEventRelatedNewPost,
                NotificationKind::FriendLinkConfirm,
                NotificationKind::FriendLinkComplete,
                NotificationKind::DirectMessageNew,
                NotificationKind::DirectMessageNewOnlyFriends,
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
