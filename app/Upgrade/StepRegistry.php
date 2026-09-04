<?php

namespace App\Upgrade;

use App\Notifications\Settings\NotificationChannel;
use App\Notifications\Settings\NotificationKind;
use App\Support\PreferenceKey;
use App\Upgrade\Steps\AdminUserUpgrade;
use App\Upgrade\Steps\BannerImageUpgrade;
use App\Upgrade\Steps\BannerUpgrade;
use App\Upgrade\Steps\BannerUseImageUpgrade;
use App\Upgrade\Steps\DiaryCommentImageUpgrade;
use App\Upgrade\Steps\DiaryCommentUpgrade;
use App\Upgrade\Steps\DiaryImageUpgrade;
use App\Upgrade\Steps\DiaryUpgrade;
use App\Upgrade\Steps\DirectMessageFileUpgrade;
use App\Upgrade\Steps\DirectMessageRecipientUpgrade;
use App\Upgrade\Steps\DirectMessageUpgrade;
use App\Upgrade\Steps\FileUpgrade;
use App\Upgrade\Steps\FriendFeatureUpgrade;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\GadgetConfigUpgrade;
use App\Upgrade\Steps\GadgetUpgrade;
use App\Upgrade\Steps\GroupCategoryUpgrade;
use App\Upgrade\Steps\GroupEventCommentImageUpgrade;
use App\Upgrade\Steps\GroupEventCommentUpgrade;
use App\Upgrade\Steps\GroupEventImageUpgrade;
use App\Upgrade\Steps\GroupEventMemberUpgrade;
use App\Upgrade\Steps\GroupEventPluginFeatureUpgrade;
use App\Upgrade\Steps\GroupEventUpgrade;
use App\Upgrade\Steps\GroupJoinRequestUpgrade;
use App\Upgrade\Steps\GroupMemberUpgrade;
use App\Upgrade\Steps\GroupTopicCommentImageUpgrade;
use App\Upgrade\Steps\GroupTopicCommentUpgrade;
use App\Upgrade\Steps\GroupTopicImageUpgrade;
use App\Upgrade\Steps\GroupTopicUpgrade;
use App\Upgrade\Steps\GroupUpgrade;
use App\Upgrade\Steps\MailTemplateTranslationUpgrade;
use App\Upgrade\Steps\MailTemplateUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
use App\Upgrade\Steps\MemberImageUpgrade;
use App\Upgrade\Steps\MemberNotificationSettingUpgrade;
use App\Upgrade\Steps\MemberPreferenceUpgrade;
use App\Upgrade\Steps\MemberProfileUpgrade;
use App\Upgrade\Steps\MemberUpgrade;
use App\Upgrade\Steps\NavigationTranslationUpgrade;
use App\Upgrade\Steps\NavigationUpgrade;
use App\Upgrade\Steps\PluginFeatureUpgrade;
use App\Upgrade\Steps\ProfileOptionTranslationUpgrade;
use App\Upgrade\Steps\ProfileOptionUpgrade;
use App\Upgrade\Steps\ProfileTranslationUpgrade;
use App\Upgrade\Steps\ProfileUpgrade;
use App\Upgrade\Steps\SnsSettingUpgrade;

/** The upgrade steps in run order. Adding a feature = adding its step here. */
final class StepRegistry
{
    /**
     * The one memberConfigDispositions() key naming a family rather than a literal config name.
     * Its members are the registered NotificationKind × NotificationChannel keys.
     */
    public const MEMBER_CONFIG_NOTIFICATION_FAMILY = 'is_send_*_mail / is_send_*_web';

    /**
     * The notification_mail names the mobile (feature-phone) frontend owns. Unlike the member_config
     * family they cannot be enumerated — the set is whatever the source installed — so the preflight
     * recognises them by this prefix instead of by name.
     */
    public const NOTIFICATION_MAIL_MOBILE_PREFIX = 'mobile_';

    /** @return list<class-string<UpgradeStep>> */
    public static function classes(): array
    {
        return [
            // files have no FK dependency and are referenced by groups.file_id and every owning
            // image/attachment table, so the file step runs before anything that points at a file.
            FileUpgrade::class,
            MemberUpgrade::class,
            // member_preferences references members; only the member step must precede it.
            MemberPreferenceUpgrade::class,
            // Same shape for the notification opt-in keys; only the member step must precede it.
            MemberNotificationSettingUpgrade::class,
            FriendshipUpgrade::class,
            FriendRequestUpgrade::class,
            MemberBlockUpgrade::class,
            DiaryUpgrade::class,
            // diary_comments.diary_id references diaries.id, so comments run after diaries.
            DiaryCommentUpgrade::class,
            // Profile definitions before member values (FK order: a member_profile row
            // references profiles and profile_options).
            ProfileUpgrade::class,
            ProfileOptionUpgrade::class,
            ProfileTranslationUpgrade::class,
            ProfileOptionTranslationUpgrade::class,
            MemberProfileUpgrade::class,
            // FK order: groups reference group_categories; group_members and
            // group_join_requests reference groups (and members, already migrated).
            GroupCategoryUpgrade::class,
            GroupUpgrade::class,
            GroupMemberUpgrade::class,
            GroupJoinRequestUpgrade::class,
            // group_topics reference groups; their comments reference the topics.
            GroupTopicUpgrade::class,
            GroupTopicCommentUpgrade::class,
            // group_events reference groups; their comments and RSVP pivot reference the events.
            GroupEventUpgrade::class,
            GroupEventCommentUpgrade::class,
            GroupEventMemberUpgrade::class,
            // navigation_translations.id references navigations.id, so translations run after.
            NavigationUpgrade::class,
            NavigationTranslationUpgrade::class,
            // gadget_configs.gadget_id references gadgets.id, so configs run after gadgets.
            GadgetUpgrade::class,
            GadgetConfigUpgrade::class,
            // admin_user and sns_settings are independent (no FK).
            AdminUserUpgrade::class,
            // sns_settings is independent (no FK); migrates the sns_config keys SnsSettingKey opts in.
            SnsSettingUpgrade::class,
            // Same target, also FK-free: OpenPNE 3's feature availability (`plugin`, plus sns_config's
            // enable_friend_link), each writing only the units OpenPNE 3 had switched off.
            PluginFeatureUpgrade::class,
            GroupEventPluginFeatureUpgrade::class,
            FriendFeatureUpgrade::class,
            // mail_templates is independent (no FK); mail_template_translations references it, so the
            // parent runs first.
            MailTemplateUpgrade::class,
            MailTemplateTranslationUpgrade::class,
            // direct_messages reference members; direct_message_recipients reference them, so they run first.
            DirectMessageUpgrade::class,
            DirectMessageRecipientUpgrade::class,
            // Image join rows: each references a file (FileUpgrade, first) plus its owning member or
            // post (all migrated above), so they run last.
            MemberImageUpgrade::class,
            DiaryImageUpgrade::class,
            DiaryCommentImageUpgrade::class,
            GroupTopicImageUpgrade::class,
            GroupTopicCommentImageUpgrade::class,
            GroupEventImageUpgrade::class,
            GroupEventCommentImageUpgrade::class,
            // banner_images reference files; banner_use_images reference banners and banner_images.
            BannerUpgrade::class,
            BannerImageUpgrade::class,
            BannerUseImageUpgrade::class,
            // direct_message_files reference the direct messages (above) and the files (FileUpgrade, first).
            DirectMessageFileUpgrade::class,
        ];
    }

    /** @return list<UpgradeStep> */
    public static function all(): array
    {
        return array_map(static fn (string $class): UpgradeStep => new $class, self::classes());
    }

    /**
     * OpenPNE 3 source tables accounted for without a standalone step, each with its disposition.
     * Not an inventory of every unmigrated table: an entry exists where absence from the step list
     * would read as a silent omission in the matrix or the coverage audits.
     *
     * @return array<string, string> source table => reason
     */
    public static function unsteppedSourceTables(): array
    {
        return [
            'file_bin' => 'OpenPNE 3 file bytes. Not a copy step: the runner migrates it by an in-place ALTER that re-points the file_id FK from `file` onto `files` (the file_bin schema is frozen, and FileUpgrade keeps file.id, for exactly that), so the gigabytes of BLOBs are never rewritten.',
            'banner_translation' => 'OpenPNE 3 banner caption (I18n). Not migrated: the caption was an admin-only label, never rendered, and OpenPNE 4 labels the fixed placements in the UI.',
            'community_member_position' => 'OpenPNE 3 community role rows. Not a standalone source→target step: GroupMemberUpgrade flattens admin/sub_admin onto group_members.role and GroupUpgrade reads admin_confirm into groups.pending_admin_member_id, both via correlated subquery. The sub_admin_confirm / nomination-handshake rows are dropped: OpenPNE 4 has no nomination handshake.',
            'deleted_message' => 'OpenPNE 3 message trash index. Not a standalone source→target step: DirectMessageUpgrade / DirectMessageRecipientUpgrade fold its is_deleted (trash) and per-pointer purge into the direct_messages.sender_* / direct_message_recipients.recipient_* soft-delete columns via correlated subquery.',
            'message_type' => 'OpenPNE 3 message-type registry. Read by subquery to select the personal-message type (type_name = `message`); not migrated as a table — OpenPNE 4 has no message-type concept (the friend/community types were a notification mechanism, carried by the notification system).',
            'message_type_translation' => 'OpenPNE 3 message-type I18n labels (the default subject/body templates per type). Not migrated: only the personal-message type is carried over and its labels are not used in OpenPNE 4.',
            // File-owning tables whose rows are not migrated; their binaries still migrate with a null owner.
            'activity_image' => 'OpenPNE 3 activity (timeline) images. The activity rows themselves are not migrated, so there is no owner to point at; the binaries are kept with a null owner.',
            'oauth_consumer' => 'OpenPNE 3 OAuth consumer registry (incl. a consumer logo file_id). OpenPNE 4 has no OAuth provider, so the table is not migrated; the logo binary is kept with a null owner.',
        ];
    }

    /**
     * OpenPNE 3 plugins whose source tables are legitimately absent when the plugin is not installed,
     * each with the minimum plugin version that has all of them. A fully absent group is created
     * empty so its steps no-op; a partially present group aborts as an old or corrupt plugin, naming
     * the floor.
     *
     * @return array<string, array{floor: string, tables: list<string>}>
     */
    public static function optionalPluginSources(): array
    {
        return [
            'opDiaryPlugin' => [
                'floor' => '1.1.1',
                'tables' => ['diary', 'diary_comment', 'diary_image', 'diary_comment_image'],
            ],
            'opMessagePlugin' => [
                'floor' => '0.8.2',
                'tables' => ['message', 'message_file', 'message_send_list', 'message_type', 'deleted_message'],
            ],
            'opCommunityTopicPlugin' => [
                'floor' => '1.0.0',
                // The *_image tables arrived in opCommunityTopic 1.0.0, so an older plugin is a partial group.
                'tables' => [
                    'community_topic', 'community_topic_comment', 'community_event', 'community_event_comment',
                    'community_event_member', 'community_topic_image', 'community_topic_comment_image',
                    'community_event_image', 'community_event_comment_image',
                ],
            ],
        ];
    }

    /**
     * file_id columns on a migrated table that FileUpgrade deliberately leaves without an owner, with
     * the reason; the coverage audit treats them as accounted for. Empty is a valid state.
     *
     * @return array<string, string> "table.column" => reason
     */
    public static function unownedFileColumns(): array
    {
        return [];
    }

    /**
     * Disposition of each known OpenPNE 3 `member_config` name: the table is read by subquery, so this
     * is the per-name coverage the per-step column audit cannot give (docs/internals/upgrade.md). A
     * name absent here is an unrecognised custom config the upgrade does not migrate.
     *
     * @return array<string, string> member_config name => where it goes / why it is dropped
     */
    public static function memberConfigDispositions(): array
    {
        return [
            // Migrated to typed members columns / the preference store.
            'pc_address' => 'members.email (PC address preferred), MemberUpgrade.',
            'mobile_address' => 'members.email fallback when no PC address, MemberUpgrade.',
            'password' => 'members.password (wrapped as bcrypt(md5) by PasswordWrap, plain bcrypt after first login), MemberUpgrade.',
            'profile_page_public_flag' => 'members.profile_visibility, MemberUpgrade.',
            'language' => 'members.locale (ja_JP→ja, …), MemberUpgrade.',
            'diary_public_flag' => 'member_preferences[diary_default_visibility], MemberPreferenceUpgrade.',
            'age_public_flag' => 'member_preferences[age_visibility], MemberPreferenceUpgrade.',
            // Owned by another OpenPNE 4 surface, migrated with that feature (not here).
            'is_send_*_mail / is_send_*_web' => 'member_notification_settings (kind × channel rows), MemberNotificationSettingUpgrade. Only the registered NotificationKind keys migrate; an is_send_ name outside the registry is an unrecognised custom key the upgrade does not carry.',
            // Intentionally dropped: no OpenPNE 4 consumer.
            'op_screen_name' => 'Dropped: OpenPNE 4 has no screen-name handle; members are shown by their nickname.',
            'time_zone' => 'Dropped: no per-member timezone rendering in OpenPNE 4.',
            'daily_news' => 'Dropped: daily-news digest is not in scope.',
            'secret_question' => 'Dropped: secret-question recovery is not in scope.',
            'secret_answer' => 'Dropped: secret-question recovery is not in scope.',
            'mobile_uid' => 'Dropped: mobile (feature-phone) frontend is not in scope.',
            'mobile_cookie_uid' => 'Dropped: mobile (feature-phone) frontend is not in scope.',
            'lastLogin' => 'Dropped: login state, not a preference; not tracked in member_config.',
            'api_key' => 'Dropped: API authentication is handled by the framework, not member_config.',
            'mail_address_hash' => 'Dropped: derived lookup hash, recomputable when needed.',
            'is_admin_invited' => 'Dropped: registration-flow flag with no OpenPNE 4 successor.',
            'remember_key' => 'Dropped: superseded by the framework remember_token column.',
            'register_token' => 'Dropped: registration/confirmation-flow token, transient.',
            'register_auth_mode' => 'Dropped: registration-flow state, transient.',
            'pc_address_pre' => 'Dropped: pending email-change confirmation, handled by Laravel verification.',
            'pc_address_token' => 'Dropped: pending email-change confirmation token.',
            'mobile_address_pre' => 'Dropped: mobile frontend not in scope.',
            'mobile_address_token' => 'Dropped: mobile frontend not in scope.',
        ];
    }

    /**
     * Every literal `member_config` name the upgrade recognises, for the preflight's unknown-name
     * scan. Unioned from memberConfigDispositions() and the registries the steps build their filters
     * from, so registering a key keeps it recognised even when the disposition map lags.
     *
     * @return list<string>
     */
    public static function knownMemberConfigNames(): array
    {
        $names = array_values(array_filter(
            array_keys(self::memberConfigDispositions()),
            static fn (string $name): bool => $name !== self::MEMBER_CONFIG_NOTIFICATION_FAMILY,
        ));

        foreach (PreferenceKey::upgradableCases() as $key) {
            $names[] = $key->op3SourceName();
        }

        foreach (NotificationKind::importableCases() as $kind) {
            foreach (NotificationChannel::cases() as $channel) {
                $names[] = $kind->op3ConfigName($channel);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Every literal `community_config` name the upgrade recognises, for the preflight's unknown-name
     * scan. GroupUpgrade reads them all by subquery, so the disposition map is the only list.
     *
     * @return list<string>
     */
    public static function knownCommunityConfigNames(): array
    {
        return array_keys(self::communityConfigDispositions());
    }

    /**
     * Disposition of each known OpenPNE 3 `community_config` name, the per-name coverage for a table
     * read only by subquery (GroupUpgrade). A name absent here is an unrecognised custom config the
     * upgrade does not migrate.
     *
     * @return array<string, string> community_config name => where it goes / why it is dropped
     */
    public static function communityConfigDispositions(): array
    {
        return [
            // Flattened onto typed groups columns.
            'register_policy' => 'groups.register_policy (open→Open, close→Approval; missing→Open), GroupUpgrade.',
            'description' => 'groups.description, GroupUpgrade.',
            'public_flag' => 'groups.topic_read_access (public→Everyone, auth_commu_member→MembersOnly; missing→Everyone), GroupUpgrade. Shared read gate for both the topic board and events (OpenPNE 3 reads the same config for both).',
            'topic_authority' => 'groups.topic_post_authority (public→Members, admin_only→AdminsOnly; missing→Members), GroupUpgrade. Shared post gate for both the topic board and events.',
            'is_default' => 'groups.is_default (KV "1"→true, else false), GroupUpgrade.',
            'is_send_pc_joinCommunity_mail' => 'groups.is_join_notification_enabled (KV "0"→false, missing/else→true), GroupUpgrade.',
            // Dropped: the mobile (feature-phone) frontend is out of scope.
            'is_send_mobile_joinCommunity_mail' => 'Dropped: mobile join-notification opt-in — the mobile frontend is out of scope.',
        ];
    }

    /**
     * Disposition of each OpenPNE 3 `notification_mail` name: the step's `name IN (…)` filter carries
     * only the templates OpenPNE 4 sends, so this is the per-name coverage for the rest. The migrated
     * entries must track MailTemplate::importable().
     *
     * @return array<string, string> notification_mail name => where it goes / why it is dropped
     */
    public static function notificationMailDispositions(): array
    {
        return [
            // Migrated to mail_templates (+ mail_template_translations for the per-locale wording).
            'pc_requestRegisterURL' => 'mail_templates[registration-link]. Required mail: is_enabled forced on.',
            'pc_changeMailAddress' => 'mail_templates[email-change-confirm]. Required mail: is_enabled forced on.',
            'pc_friendLinkComplete' => 'mail_templates[friend-accepted]. Configurable: is_enabled carried over.',
            'pc_friendLinkRequest' => 'mail_templates[friend-requested]. Configurable: is_enabled carried over.',
            'pc_notifyNewMessage' => 'mail_templates[direct-message-received]. Configurable: is_enabled carried over.',
            'pc_notifyNewDiaryComment' => 'mail_templates[diary-comment]. Not admin-toggleable (member opt-out lives in member_notification_settings): is_enabled forced on.',
            'pc_notifyNewDiary' => 'mail_templates[diary-posted]. Not admin-toggleable (member opt-out lives in member_notification_settings): is_enabled forced on.',
            'pc_notifyCommunityPosting' => 'mail_templates[group-posting]. Configurable: is_enabled carried over. One template for topic and event comments and the new-post broadcasts.',
            'pc_timelineNewPost' => 'mail_templates[timeline-posting]. Configurable: is_enabled carried over. One template for the new-post broadcast and the reply notifications.',
            'pc_registerEnd' => 'mail_templates[registration-complete]. Not admin-toggleable (transactional): is_enabled forced on.',
            'pc_leave' => 'mail_templates[withdrawal-complete]. Not admin-toggleable (transactional): is_enabled forced on.',
            'pc_joinCommunity' => 'mail_templates[group-join]. Configurable: is_enabled carried over. The per-community opt-in lands on groups.is_join_notification_enabled (GroupUpgrade).',
            'pc_signature' => 'mail_templates[signature]. Appended to every sendable body; not itself toggleable.',
            // Dropped: deliberately not carried.
            'pc_reissuedPassword' => 'Dropped: OpenPNE 3 mailed a new plaintext password; OpenPNE 4 sends a reset link (password-reset) instead — a different mail with no OpenPNE 3 wording to carry.',
            'pc_birthday' => 'Dropped: OpenPNE 4 has no birthday digest (its template needs loop/filter constructs the sandboxed renderer does not support).',
            'pc_dailyNews' => 'Dropped: the daily-news digest is not in scope.',
            // Dropped: the feature-phone frontend is out of scope; every mobile_ row is excluded by the name filter.
            self::NOTIFICATION_MAIL_MOBILE_PREFIX.'*' => 'Dropped: the mobile (feature-phone) frontend is not in scope.',
        ];
    }

    /**
     * Every literal `notification_mail` name the upgrade recognises, for the preflight's unknown-name scan.
     * The mobile family is excluded here and matched by NOTIFICATION_MAIL_MOBILE_PREFIX instead, so a name
     * outside both is a third-party plugin's template the upgrade has no home for.
     *
     * @return list<string>
     */
    public static function knownNotificationMailNames(): array
    {
        return array_values(array_filter(
            array_keys(self::notificationMailDispositions()),
            static fn (string $name): bool => $name !== self::NOTIFICATION_MAIL_MOBILE_PREFIX.'*',
        ));
    }
}
