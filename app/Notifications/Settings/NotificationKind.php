<?php

declare(strict_types=1);

namespace App\Notifications\Settings;

/**
 * The closed registry of member-configurable notification kinds (the notification catalog; its
 * OpenPNE 3 notification-extension lineage is described in docs/internals/notifications.md).
 * The case value is the stored `member_notification_settings.kind`; each case's registry entry
 * lives in definition().
 *
 * Every importable catalog item is registered, wired or not, so the one-shot upgrade can
 * preserve every member's stored choice; only wired kinds (those with an OpenPNE 4 sender)
 * surface in the settings UI.
 */
enum NotificationKind: string
{
    case TimelineNewPost = 'timeline_new_post';
    case TimelineNewPostOnlyFriends = 'timeline_new_post_only_friends';
    case TimelineNewPostCommunity = 'timeline_new_post_community';
    case TimelineReplyPost = 'timeline_reply_post';
    case TimelineRelatedPost = 'timeline_related_post';

    case DiaryNewPost = 'diary_new_post';
    case DiaryNewPostOnlyFriends = 'diary_new_post_only_friends';
    case DiaryReplyPost = 'diary_reply_post';
    case DiaryRelatedPost = 'diary_related_post';

    case CommunityTopicNewPost = 'community_topic_new_post';
    case CommunityTopicCommentNewPost = 'community_topic_comment_new_post';
    case CommunityTopicReplyNewPost = 'community_topic_reply_new_post';
    case CommunityTopicRelatedNewPost = 'community_topic_related_new_post';

    case CommunityEventNewPost = 'community_event_new_post';
    case CommunityEventCommentNewPost = 'community_event_comment_new_post';
    case CommunityEventReplyNewPost = 'community_event_reply_new_post';
    case CommunityEventRelatedNewPost = 'community_event_related_new_post';

    case FriendLinkConfirm = 'friend_link_confirm';
    case FriendLinkComplete = 'friend_link_complete';

    case MessageNew = 'message_new';
    case MessageNewOnlyFriends = 'message_new_only_friends';

    /**
     * The full registry entry, colocated so adding/changing a kind is one arm here (same pattern
     * as MailTemplate::definition()).
     */
    public function definition(): NotificationKindDefinition
    {
        return match ($this) {
            self::TimelineNewPost => new NotificationKindDefinition(
                category: NotificationCategory::Timeline,
                op3Name: 'timelineNewPost',
                caption: 'New %activity% posts (everyone)',
            ),
            self::TimelineNewPostOnlyFriends => new NotificationKindDefinition(
                category: NotificationCategory::Timeline,
                op3Name: 'timelineNewPostOnlyFriends',
                caption: 'New %activity% posts (%friends% only)',
                dependOnNot: self::TimelineNewPost,
            ),
            self::TimelineNewPostCommunity => new NotificationKindDefinition(
                category: NotificationCategory::Timeline,
                op3Name: 'timelineNewPostCommunity',
                caption: 'New %community% %activity% posts',
            ),
            self::TimelineReplyPost => new NotificationKindDefinition(
                category: NotificationCategory::Timeline,
                op3Name: 'timelineReplyPost',
                caption: 'Comments on your %activity% posts',
            ),
            self::TimelineRelatedPost => new NotificationKindDefinition(
                category: NotificationCategory::Timeline,
                op3Name: 'timelineRelatedPost',
                caption: 'Comments on %activity% posts you commented on',
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
            self::CommunityTopicNewPost => new NotificationKindDefinition(
                category: NotificationCategory::CommunityTopic,
                op3Name: 'communityTopicNewPost',
                caption: 'New %topics% in your %communities%',
                isWired: true,
            ),
            self::CommunityTopicCommentNewPost => new NotificationKindDefinition(
                category: NotificationCategory::CommunityTopic,
                op3Name: 'communityTopicCommentNewPost',
                caption: 'New comments on %topics% in your %communities%',
                isWired: true,
            ),
            self::CommunityTopicReplyNewPost => new NotificationKindDefinition(
                category: NotificationCategory::CommunityTopic,
                op3Name: 'communityTopicReplyNewPost',
                caption: 'Comments on %topics% you created',
                isWired: true,
            ),
            self::CommunityTopicRelatedNewPost => new NotificationKindDefinition(
                category: NotificationCategory::CommunityTopic,
                op3Name: 'communityTopicRelatedNewPost',
                caption: 'Comments on %topics% you commented on',
                isWired: true,
            ),
            self::CommunityEventNewPost => new NotificationKindDefinition(
                category: NotificationCategory::CommunityEvent,
                op3Name: 'communityEventNewPost',
                caption: 'New events in your %communities%',
                isWired: true,
            ),
            self::CommunityEventCommentNewPost => new NotificationKindDefinition(
                category: NotificationCategory::CommunityEvent,
                op3Name: 'communityEventCommentNewPost',
                caption: 'New comments on events in your %communities%',
                isWired: true,
            ),
            self::CommunityEventReplyNewPost => new NotificationKindDefinition(
                category: NotificationCategory::CommunityEvent,
                op3Name: 'communityEventReplyNewPost',
                caption: 'Comments on events you created',
                isWired: true,
            ),
            self::CommunityEventRelatedNewPost => new NotificationKindDefinition(
                category: NotificationCategory::CommunityEvent,
                op3Name: 'communityEventRelatedNewPost',
                caption: 'Comments on events you commented on',
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
            self::MessageNew => new NotificationKindDefinition(
                category: NotificationCategory::Message,
                op3Name: 'messageNew',
                caption: 'New messages',
                isWired: true,
            ),
            self::MessageNewOnlyFriends => new NotificationKindDefinition(
                category: NotificationCategory::Message,
                op3Name: 'messageNewOnlyFriends',
                caption: 'New messages (%friends% only)',
                dependOnNot: self::MessageNew,
                isWired: true,
            ),
        };
    }

    public function category(): NotificationCategory
    {
        return $this->definition()->category;
    }

    /** Member-facing toggle label (translated; %term% placeholders resolve downstream). */
    public function caption(): string
    {
        return __($this->definition()->caption);
    }

    public function dependOnNot(): ?self
    {
        return $this->definition()->dependOnNot;
    }

    /** Whether OpenPNE 4 has a sender for this kind (unwired kinds are hidden from the settings UI). */
    public function isWired(): bool
    {
        return $this->definition()->isWired;
    }

    /**
     * Whether an absent settings row means enabled. Must stay true for imported kinds (an
     * absent source key meant enabled, and the import writes no row for it); kept per-kind so
     * a default can be flipped in one arm later.
     */
    public function defaultEnabled(): bool
    {
        return true;
    }

    /**
     * The member_config key for this kind on $channel, in the exact format the OpenPNE 3
     * notification extension's settings form stored. The upgrade derives its imported name set
     * from this, so there is no second list to keep in sync.
     */
    public function op3ConfigName(NotificationChannel $channel): string
    {
        $name = $this->definition()->op3Name;

        return match ($channel) {
            NotificationChannel::Web => "is_send_{$name}_web",
            NotificationChannel::Mail => "is_send_pc_{$name}_mail",
        };
    }

    /** @return list<self> kinds with an OpenPNE 4 sender (the settings UI's inventory). */
    public static function wiredCases(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $kind): bool => $kind->isWired()));
    }

    /**
     * Raw caption source strings (pre-__()). Exposed so the i18n:check term-literal gate can
     * scan captions that reach __() via a variable and never enter the code scanner.
     *
     * @return list<string>
     */
    public static function sourceStrings(): array
    {
        return array_map(static fn (self $kind): string => $kind->definition()->caption, self::cases());
    }

    /**
     * Raw captions (pre-__()) of kinds that surface in the settings UI (wired only), fed to the
     * i18n:check coverage gate — so a kind's ja translation is required exactly when it becomes wired.
     *
     * @return list<string>
     */
    public static function coverageStrings(): array
    {
        return array_map(static fn (self $kind): string => $kind->definition()->caption, self::wiredCases());
    }
}
