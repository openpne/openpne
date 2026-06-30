<?php

namespace App\Upgrade;

use App\Upgrade\Steps\BannerImageUpgrade;
use App\Upgrade\Steps\BannerUpgrade;
use App\Upgrade\Steps\BannerUseImageUpgrade;
use App\Upgrade\Steps\CommunityCategoryUpgrade;
use App\Upgrade\Steps\CommunityEventCommentImageUpgrade;
use App\Upgrade\Steps\CommunityEventCommentUpgrade;
use App\Upgrade\Steps\CommunityEventImageUpgrade;
use App\Upgrade\Steps\CommunityEventMemberUpgrade;
use App\Upgrade\Steps\CommunityEventUpgrade;
use App\Upgrade\Steps\CommunityJoinRequestUpgrade;
use App\Upgrade\Steps\CommunityMemberUpgrade;
use App\Upgrade\Steps\CommunityTopicCommentImageUpgrade;
use App\Upgrade\Steps\CommunityTopicCommentUpgrade;
use App\Upgrade\Steps\CommunityTopicImageUpgrade;
use App\Upgrade\Steps\CommunityTopicUpgrade;
use App\Upgrade\Steps\CommunityUpgrade;
use App\Upgrade\Steps\DiaryCommentImageUpgrade;
use App\Upgrade\Steps\DiaryCommentUpgrade;
use App\Upgrade\Steps\DiaryImageUpgrade;
use App\Upgrade\Steps\DiaryUpgrade;
use App\Upgrade\Steps\FileUpgrade;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\Steps\GadgetConfigUpgrade;
use App\Upgrade\Steps\GadgetUpgrade;
use App\Upgrade\Steps\MailTemplateTranslationUpgrade;
use App\Upgrade\Steps\MailTemplateUpgrade;
use App\Upgrade\Steps\MemberBlockUpgrade;
use App\Upgrade\Steps\MemberImageUpgrade;
use App\Upgrade\Steps\MemberPreferenceUpgrade;
use App\Upgrade\Steps\MemberProfileUpgrade;
use App\Upgrade\Steps\MemberUpgrade;
use App\Upgrade\Steps\MessageFileUpgrade;
use App\Upgrade\Steps\MessageRecipientUpgrade;
use App\Upgrade\Steps\MessageUpgrade;
use App\Upgrade\Steps\NavigationTranslationUpgrade;
use App\Upgrade\Steps\NavigationUpgrade;
use App\Upgrade\Steps\ProfileOptionTranslationUpgrade;
use App\Upgrade\Steps\ProfileOptionUpgrade;
use App\Upgrade\Steps\ProfileTranslationUpgrade;
use App\Upgrade\Steps\ProfileUpgrade;
use App\Upgrade\Steps\SnsSettingUpgrade;

/** The upgrade steps in run order. Adding a feature = adding its step here. */
final class StepRegistry
{
    /** @return list<class-string<UpgradeStep>> */
    public static function classes(): array
    {
        return [
            // files have no FK dependency and are referenced by communities.file_id and every owning
            // image/attachment table, so the file step runs before anything that points at a file.
            FileUpgrade::class,
            MemberUpgrade::class,
            // member_preferences references members; only the member step must precede it.
            MemberPreferenceUpgrade::class,
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
            // FK order: communities reference community_categories; community_members and
            // community_join_requests reference communities (and members, already migrated).
            CommunityCategoryUpgrade::class,
            CommunityUpgrade::class,
            CommunityMemberUpgrade::class,
            CommunityJoinRequestUpgrade::class,
            // community_topics reference communities; their comments reference the topics.
            CommunityTopicUpgrade::class,
            CommunityTopicCommentUpgrade::class,
            // community_events reference communities; their comments and RSVP pivot reference the events.
            CommunityEventUpgrade::class,
            CommunityEventCommentUpgrade::class,
            CommunityEventMemberUpgrade::class,
            // navigation_translations.id references navigations.id, so translations run after.
            NavigationUpgrade::class,
            NavigationTranslationUpgrade::class,
            // gadget_configs.gadget_id references gadgets.id, so configs run after gadgets.
            GadgetUpgrade::class,
            GadgetConfigUpgrade::class,
            // sns_settings is independent (no FK); migrates the display + gadget-layout sns_config keys.
            SnsSettingUpgrade::class,
            // mail_templates is independent (no FK); mail_template_translations references it, so the
            // parent runs first.
            MailTemplateUpgrade::class,
            MailTemplateTranslationUpgrade::class,
            // messages reference members; message_recipients reference the messages, so messages run first.
            MessageUpgrade::class,
            MessageRecipientUpgrade::class,
            // Image join rows: each references a file (FileUpgrade, first) plus its owning member or
            // post (all migrated above), so they run last.
            MemberImageUpgrade::class,
            DiaryImageUpgrade::class,
            DiaryCommentImageUpgrade::class,
            CommunityTopicImageUpgrade::class,
            CommunityTopicCommentImageUpgrade::class,
            CommunityEventImageUpgrade::class,
            CommunityEventCommentImageUpgrade::class,
            // banner_images reference files; banner_use_images reference banners and banner_images.
            BannerUpgrade::class,
            BannerImageUpgrade::class,
            BannerUseImageUpgrade::class,
            // message_files reference the messages (above) and the files (FileUpgrade, first).
            MessageFileUpgrade::class,
        ];
    }

    /** @return list<UpgradeStep> */
    public static function all(): array
    {
        return array_map(static fn (string $class): UpgradeStep => new $class, self::classes());
    }

    /**
     * OpenPNE 3 source tables not driven by a standalone source→target step, each with the
     * reason — either deferred (a successor table exists but no step yet) or flattened into
     * another table via correlated subquery (no successor table of its own). Recorded so the
     * data shows up in the matrix instead of being an invisible omission (the per-step audit
     * only sees source tables a step reads as its FROM table).
     *
     * @return array<string, string> source table => reason
     */
    public static function deferredSourceTables(): array
    {
        return [
            'file_bin' => 'OpenPNE 3 file bytes. Not a copy step: the runner migrates it by an in-place ALTER that re-points the file_id FK from `file` onto `files` (the file_bin schema is frozen, and FileUpgrade keeps file.id, for exactly that), so the gigabytes of BLOBs are never rewritten.',
            'banner_translation' => 'OpenPNE 3 banner caption (I18n). Not migrated: the caption was an admin-only label, never rendered, and OpenPNE 4 labels the fixed placements in the UI.',
            'community_member_position' => 'OpenPNE 3 community role rows. Not a standalone source→target step: CommunityMemberUpgrade flattens admin/sub_admin onto community_members.role and CommunityUpgrade reads admin_confirm into communities.pending_admin_member_id, both via correlated subquery. The sub_admin_confirm / nomination-handshake rows are dropped (Phase A is approval-only).',
            'deleted_message' => 'OpenPNE 3 message trash index. Not a standalone source→target step: MessageUpgrade / MessageRecipientUpgrade fold its is_deleted (trash) and per-pointer purge into the messages.sender_* / message_recipients.recipient_* soft-delete columns via correlated subquery.',
            'message_type' => 'OpenPNE 3 message-type registry. Read by subquery to select the personal-message type (type_name = `message`); not migrated as a table — OpenPNE 4 has no message-type concept (the friend/community types were a notification mechanism, carried by the notification system).',
            'message_type_translation' => 'OpenPNE 3 message-type I18n labels (the default subject/body templates per type). Not migrated: only the personal-message type is carried over and its labels are not used in OpenPNE 4.',
            // File-owning tables with no OpenPNE 4 successor surface. FileUpgrade still migrates their
            // binaries (every `file` row is kept) with a null owner; an owner is assigned if and when
            // the corresponding feature lands.
            'activity_image' => 'OpenPNE 3 activity (timeline) images. The timeline is not built; the binaries are kept with a null owner for when it lands.',
            'oauth_consumer' => 'OpenPNE 3 OAuth consumer registry (incl. a consumer logo file_id). OpenPNE 4 has no OAuth provider, so the table is not migrated; the logo binary is kept with a null owner.',
        ];
    }

    /**
     * file_id columns that sit on an otherwise-migrated table but are intentionally left without a
     * file owner, with the reason. Distinct from deferredSourceTables() (whole tables with no step):
     * the table migrates, but FileUpgrade assigns its file no related_entity yet. The matrix coverage
     * audit treats these as accounted-for so the column is not read as a silent drop.
     *
     * Currently empty: every migrated table's file column is owned by FileUpgrade (the community top
     * image now is too). Kept as the registered home for the next such case.
     *
     * @return array<string, string> "table.column" => reason
     */
    public static function unownedFileColumns(): array
    {
        return [];
    }

    /**
     * Disposition of each known OpenPNE 3 `member_config` name. member_config is a KV table read
     * by several steps via subquery (not one source→target step), so the per-step column audit
     * cannot show which names migrate and which are dropped; this is that per-name coverage.
     * A name absent from this map is an unrecognised custom config (third-party plugin or source
     * customisation) the upgrade does not migrate — a data-driven count of such names at run time
     * is a follow-up once the upgrade has a source-connected runner.
     *
     * @return array<string, string> member_config name => where it goes / why it is dropped
     */
    public static function memberConfigDispositions(): array
    {
        return [
            // Migrated to typed members columns / the preference store.
            'pc_address' => 'members.email (PC address preferred), MemberUpgrade.',
            'mobile_address' => 'members.email fallback when no PC address, MemberUpgrade.',
            'password' => 'members.password (legacy hash, rehashed on first login), MemberUpgrade.',
            'profile_page_public_flag' => 'members.profile_visibility, MemberUpgrade.',
            'language' => 'members.locale (ja_JP→ja, …), MemberUpgrade.',
            'diary_public_flag' => 'member_preferences[diary_default_visibility], MemberPreferenceUpgrade.',
            'age_public_flag' => 'member_preferences[age_visibility], MemberPreferenceUpgrade.',
            // Owned by another OpenPNE 4 surface, migrated with that feature (not here).
            'is_send_*_mail / is_send_*_web' => 'Per-member notification opt-in/out — the notification centre store, not the scalar preference store.',
            'op_screen_name' => 'members.screen_name (unique handle) — lands with the timeline feature.',
            // Intentionally dropped: no OpenPNE 4 consumer.
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
     * Disposition of each known OpenPNE 3 `community_config` name. Like member_config, this is a KV
     * table read by subquery (CommunityUpgrade), not a source→target step, so the per-step column
     * audit cannot show which names migrate; this is that per-name coverage. A name absent from this
     * map is an unrecognised custom/plugin config the upgrade does not migrate.
     *
     * @return array<string, string> community_config name => where it goes / why it is dropped
     */
    public static function communityConfigDispositions(): array
    {
        return [
            // Flattened onto typed communities columns.
            'register_policy' => 'communities.register_policy (open→Open, close→Approval; missing→Open), CommunityUpgrade.',
            'description' => 'communities.description, CommunityUpgrade.',
            'public_flag' => 'communities.topic_read_access (public→Everyone, auth_commu_member→MembersOnly; missing→Everyone), CommunityUpgrade. Shared read gate for both the topic board and events (OpenPNE 3 reads the same config for both).',
            'topic_authority' => 'communities.topic_post_authority (public→Members, admin_only→AdminsOnly; missing→Members), CommunityUpgrade. Shared post gate for both the topic board and events.',
            'is_default' => 'communities.is_default (KV "1"→true, else false), CommunityUpgrade.',
            // Owned by a later feature.
            'is_send_pc_joinCommunity_mail' => 'Per-community join-notification opt-in — lands with the notification feature.',
            'is_send_mobile_joinCommunity_mail' => 'Mobile join-notification opt-in — the mobile frontend is out of scope.',
        ];
    }

    /**
     * Disposition of each OpenPNE 3 `notification_mail` name. MailTemplateUpgrade copies the table but its
     * `name IN (…)` filter only carries the templates OpenPNE 4 sends, so the per-step column audit cannot
     * show why the other names are dropped; this is that per-name coverage. The migrated entries are
     * hand-written (each carries its own is_enabled / signature reason); a registry-consistency test pins
     * them to MailTemplate::importable() so adding an import origin cannot leave this map behind.
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
            'pc_signature' => 'mail_templates[signature]. Appended to every sendable body; not itself toggleable.',
            // Dropped: no OpenPNE 4 sender yet — the wording and a sender land together as a follow-up.
            'pc_registerEnd' => 'Dropped: OpenPNE 4 has no registration-complete mail yet (follow-up adds wording + sender together).',
            'pc_joinCommunity' => 'Dropped: OpenPNE 4 has no community-join mail yet (follow-up).',
            'pc_leave' => 'Dropped: OpenPNE 4 has no withdrawal mail yet (follow-up).',
            // Dropped: deliberately not carried.
            'pc_reissuedPassword' => 'Dropped: OpenPNE 3 mailed a new plaintext password; OpenPNE 4 sends a reset link (password-reset) instead — a different mail with no OpenPNE 3 wording to carry.',
            'pc_birthday' => 'Dropped: the birthday digest is a Phase 3 feature (needs the loop/filter renderer extensions).',
            'pc_dailyNews' => 'Dropped: the daily-news digest is not in scope.',
            // Dropped: the feature-phone frontend is out of scope; every mobile_ row is excluded by the name filter.
            'mobile_*' => 'Dropped: the mobile (feature-phone) frontend is not in scope.',
        ];
    }
}
