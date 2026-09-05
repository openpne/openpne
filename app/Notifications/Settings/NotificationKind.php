<?php

declare(strict_types=1);

namespace App\Notifications\Settings;

use App\Features\GroupTalk\GroupTalkNotifyDefault;
use App\Features\GroupTalk\GroupTalkNotifyMode;
use LogicException;

/**
 * The closed registry of member-configurable notification kinds; the case value is the stored
 * `member_notification_settings.kind` (docs/internals/notifications.md, "The per-member catalog").
 */
enum NotificationKind: string
{
    case TimelineNewPost = 'timeline_new_post';
    case TimelineNewPostOnlyFriends = 'timeline_new_post_only_friends';
    case TimelineNewPostCommunity = 'timeline_new_post_community';
    case TimelineReplyPost = 'timeline_reply_post';
    case TimelineRelatedPost = 'timeline_related_post';
    case TimelineMention = 'timeline_mention';

    case DiaryNewPost = 'diary_new_post';
    case DiaryNewPostOnlyFriends = 'diary_new_post_only_friends';
    case DiaryReplyPost = 'diary_reply_post';
    case DiaryRelatedPost = 'diary_related_post';

    case GroupTopicNewPost = 'group_topic_new_post';
    case GroupTopicCommentNewPost = 'group_topic_comment_new_post';
    case GroupTopicReplyNewPost = 'group_topic_reply_new_post';
    case GroupTopicRelatedNewPost = 'group_topic_related_new_post';

    case GroupEventNewPost = 'group_event_new_post';
    case GroupEventCommentNewPost = 'group_event_comment_new_post';
    case GroupEventReplyNewPost = 'group_event_reply_new_post';
    case GroupEventRelatedNewPost = 'group_event_related_new_post';

    case GroupTalkMention = 'group_talk_mention';
    case GroupTalkNewMessage = 'group_talk_new_message';

    case FriendLinkConfirm = 'friend_link_confirm';
    case FriendLinkComplete = 'friend_link_complete';

    case DirectMessageNew = 'direct_message_new';
    case DirectMessageNewOnlyFriends = 'direct_message_new_only_friends';

    public function definition(): NotificationKindDefinition
    {
        return match ($this) {
            self::TimelineNewPost => new NotificationKindDefinition(
                category: NotificationCategory::Timeline,
                op3Name: 'timelineNewPost',
                caption: 'New %activity% posts (everyone)',
                isWired: true,
            ),
            self::TimelineNewPostOnlyFriends => new NotificationKindDefinition(
                category: NotificationCategory::Timeline,
                op3Name: 'timelineNewPostOnlyFriends',
                caption: 'New %activity% posts (%friends% only)',
                dependOnNot: self::TimelineNewPost,
                isWired: true,
            ),
            // Dormant: the community timeline it announced is gone, but the case and its imported rows
            // stay, a member's stored choice for it being no consent to a different kind.
            self::TimelineNewPostCommunity => new NotificationKindDefinition(
                category: NotificationCategory::Timeline,
                op3Name: 'timelineNewPostCommunity',
                caption: 'New %community% %activity% posts',
                isWired: false,
            ),
            self::TimelineReplyPost => new NotificationKindDefinition(
                category: NotificationCategory::Timeline,
                op3Name: 'timelineReplyPost',
                caption: 'Comments on your %activity% posts',
                isWired: true,
            ),
            self::TimelineRelatedPost => new NotificationKindDefinition(
                category: NotificationCategory::Timeline,
                op3Name: 'timelineRelatedPost',
                caption: 'Comments on %activity% posts you commented on',
                isWired: true,
            ),
            self::TimelineMention => new NotificationKindDefinition(
                category: NotificationCategory::Timeline,
                caption: 'When you are mentioned in a %activity% post',
                isWired: true,
            ),
            self::DiaryNewPost => new NotificationKindDefinition(
                category: NotificationCategory::Diary,
                op3Name: 'diaryNewPost',
                caption: 'New %diaries% (everyone)',
                isWired: true,
            ),
            self::DiaryNewPostOnlyFriends => new NotificationKindDefinition(
                category: NotificationCategory::Diary,
                op3Name: 'diaryNewPostOnlyFriends',
                caption: 'New %diaries% (%friends% only)',
                dependOnNot: self::DiaryNewPost,
                isWired: true,
            ),
            self::DiaryReplyPost => new NotificationKindDefinition(
                category: NotificationCategory::Diary,
                op3Name: 'diaryReplyPost',
                caption: 'Comments on your %diaries%',
                isWired: true,
            ),
            self::DiaryRelatedPost => new NotificationKindDefinition(
                category: NotificationCategory::Diary,
                op3Name: 'diaryRelatedPost',
                caption: 'Comments on %diaries% you commented on',
                isWired: true,
            ),
            self::GroupTopicNewPost => new NotificationKindDefinition(
                category: NotificationCategory::GroupTopic,
                op3Name: 'communityTopicNewPost',
                caption: 'New %topics% in your %communities%',
                isWired: true,
            ),
            self::GroupTopicCommentNewPost => new NotificationKindDefinition(
                category: NotificationCategory::GroupTopic,
                op3Name: 'communityTopicCommentNewPost',
                caption: 'New comments on %topics% in your %communities%',
                isWired: true,
            ),
            self::GroupTopicReplyNewPost => new NotificationKindDefinition(
                category: NotificationCategory::GroupTopic,
                op3Name: 'communityTopicReplyNewPost',
                caption: 'Comments on %topics% you created',
                isWired: true,
            ),
            self::GroupTopicRelatedNewPost => new NotificationKindDefinition(
                category: NotificationCategory::GroupTopic,
                op3Name: 'communityTopicRelatedNewPost',
                caption: 'Comments on %topics% you commented on',
                isWired: true,
            ),
            self::GroupEventNewPost => new NotificationKindDefinition(
                category: NotificationCategory::GroupEvent,
                op3Name: 'communityEventNewPost',
                caption: 'New events in your %communities%',
                isWired: true,
            ),
            self::GroupEventCommentNewPost => new NotificationKindDefinition(
                category: NotificationCategory::GroupEvent,
                op3Name: 'communityEventCommentNewPost',
                caption: 'New comments on events in your %communities%',
                isWired: true,
            ),
            self::GroupEventReplyNewPost => new NotificationKindDefinition(
                category: NotificationCategory::GroupEvent,
                op3Name: 'communityEventReplyNewPost',
                caption: 'Comments on events you created',
                isWired: true,
            ),
            self::GroupEventRelatedNewPost => new NotificationKindDefinition(
                category: NotificationCategory::GroupEvent,
                op3Name: 'communityEventRelatedNewPost',
                caption: 'Comments on events you commented on',
                isWired: true,
            ),
            // OpenPNE 3 had no group chat, so there is no stored preference to import.
            self::GroupTalkMention => new NotificationKindDefinition(
                category: NotificationCategory::GroupTalk,
                caption: 'When you are mentioned in a %community% talk message (delivered even while the %community% is muted)',
                isWired: true,
            ),
            self::GroupTalkNewMessage => new NotificationKindDefinition(
                category: NotificationCategory::GroupTalk,
                caption: 'New messages in %community% talk',
                isWired: true,
            ),
            self::FriendLinkConfirm => new NotificationKindDefinition(
                category: NotificationCategory::FriendLink,
                op3Name: 'friendLinkConfirm',
                caption: 'When you receive a %friend% request',
                isWired: true,
            ),
            self::FriendLinkComplete => new NotificationKindDefinition(
                category: NotificationCategory::FriendLink,
                op3Name: 'friendLinkComplete',
                caption: 'When your %friend% request is accepted',
                isWired: true,
            ),
            self::DirectMessageNew => new NotificationKindDefinition(
                category: NotificationCategory::DirectMessage,
                op3Name: 'messageNew',
                caption: 'New messages',
                isWired: true,
            ),
            self::DirectMessageNewOnlyFriends => new NotificationKindDefinition(
                category: NotificationCategory::DirectMessage,
                op3Name: 'messageNewOnlyFriends',
                caption: 'New messages (%friends% only)',
                dependOnNot: self::DirectMessageNew,
                isWired: true,
            ),
        };
    }

    public function category(): NotificationCategory
    {
        return $this->definition()->category;
    }

    /** Translated, but its %term% placeholders are resolved downstream. */
    public function caption(): string
    {
        return __($this->definition()->caption);
    }

    public function dependOnNot(): ?self
    {
        return $this->definition()->dependOnNot;
    }

    public function isWired(): bool
    {
        return $this->definition()->isWired;
    }

    /**
     * Must stay true on both channels for an imported kind: an absent source key meant enabled, and the
     * import writes no row for it. A kind whose default can be false is read in both polarities by every
     * fan-out (docs/internals/notifications.md, "Key invariants").
     */
    public function defaultEnabled(NotificationChannel $channel): bool
    {
        return match ($this) {
            self::GroupTalkNewMessage => $channel === NotificationChannel::Web
                && app(GroupTalkNotifyDefault::class)->mode() === GroupTalkNotifyMode::All,
            default => true,
        };
    }

    /**
     * Such a channel stores a row only as an override — a value equal to the current default is not
     * written (Member::setNotificationSetting; docs/internals/notifications.md, Key invariants).
     */
    public function hasSiteDefault(NotificationChannel $channel): bool
    {
        return $this === self::GroupTalkNewMessage && $channel === NotificationChannel::Web;
    }

    /**
     * The member_config key in the exact format the OpenPNE 3 notification extension's settings form
     * stored.
     *
     * @throws LogicException for a native kind — callers select their input with importableCases()
     */
    public function op3ConfigName(NotificationChannel $channel): string
    {
        $name = $this->definition()->op3Name;

        if ($name === null) {
            throw new LogicException("{$this->value} is an OpenPNE 4 native kind and has no OpenPNE 3 config name.");
        }

        return match ($channel) {
            NotificationChannel::Web => "is_send_{$name}_web",
            NotificationChannel::Mail => "is_send_pc_{$name}_mail",
        };
    }

    /** @return list<self> */
    public static function wiredCases(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => $kind->isWired()));
    }

    /** @return list<self> */
    public static function importableCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $kind): bool => $kind->definition()->op3Name !== null,
        ));
    }

    /**
     * Read by the i18n:check term-literal gate: a string reaching __() through a variable never enters
     * the code scanner.
     *
     * @return list<string>
     */
    public static function sourceStrings(): array
    {
        return array_map(static fn (self $kind): string => $kind->definition()->caption, self::cases());
    }

    /**
     * Wired kinds only, so a caption needs its ja translation exactly when the kind becomes wired.
     *
     * @return list<string>
     */
    public static function coverageStrings(): array
    {
        return array_map(static fn (self $kind): string => $kind->definition()->caption, self::wiredCases());
    }
}
