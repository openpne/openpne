<?php

declare(strict_types=1);

namespace App\Support;

use App\Features\GroupTalk\GroupTalkNotifyMode;

/**
 * The closed registry of site-wide settings in `sns_settings`; the stored row is the single source of
 * truth and {@see default} answers only while no row exists.
 */
enum SnsSettingKey: string
{
    case SnsName = 'sns_name';

    case SnsTitle = 'sns_title';

    case AdminMailAddress = 'admin_mail_address';

    case SurfaceMode = 'surface_mode';

    /** Stores an App\Features\Auth\RegistrationMode value. */
    case RegistrationMode = 'registration_mode';

    case CaptchaEnabled = 'captcha_enabled';

    case AllowWebPublicAge = 'allow_web_public_age';

    case TimelineAllowWebPublic = 'timeline_allow_web_public';

    /** Off refuses new posts and replies; what is already posted stays readable. */
    case TimelinePostingEnabled = 'timeline_posting_enabled';

    /** Off also closes the guest-reachable diary screens, as OpenPNE 3 did. */
    case DiaryAllowWebPublic = 'diary_allow_web_public';

    /** The site-wide search screen only; a member archive's keyword filter is not this. */
    case DiarySearchEnabled = 'diary_search_enabled';

    case DiarySearchPeriodEnabled = 'diary_search_period_enabled';

    /** Days back from today's midnight that the site-wide search covers while the period switch is on; 0 is today alone, as OpenPNE 3 read it. */
    case DiarySearchPeriodDays = 'diary_search_period_days';

    /** An App\Gadgets\GadgetLayout id (layoutA/B/C). */
    case GadgetHomeLayout = 'gadget_home_layout';

    case GadgetProfileLayout = 'gadget_profile_layout';

    case GadgetLoginLayout = 'gadget_login_layout';

    case CustomCss = 'customizing_css';

    /** Operator HTML, emitted raw in the Classic shell. */
    case PcHtmlHead = 'pc_html_head';

    case PcHtmlTop2 = 'pc_html_top2';

    case PcHtmlTop = 'pc_html_top';

    case PcHtmlBottom2 = 'pc_html_bottom2';

    case PcHtmlBottom = 'pc_html_bottom';

    /** Operator HTML, emitted raw; chosen by page security (OpenPNE 3 isSecurePage), not the viewer's login state. */
    case FooterBefore = 'footer_before';

    case FooterAfter = 'footer_after';

    /** An absent row means enabled (OpenPNE 3's lazy `plugin` rows). */
    case FeatureDiaryEnabled = 'feature_diary_enabled';

    case FeatureDirectMessageEnabled = 'feature_direct_message_enabled';

    case FeatureTimelineEnabled = 'feature_timeline_enabled';

    case FeatureGroupEnabled = 'feature_group_enabled';

    case FeatureGroupTopicEnabled = 'feature_group_topic_enabled';

    case FeatureGroupEventEnabled = 'feature_group_event_enabled';

    case FeatureGroupTalkEnabled = 'feature_group_talk_enabled';

    /** Upgrades from sns_config `enable_friend_link` through its own step, which writes only a disabled row. */
    case FeatureFriendEnabled = 'feature_friend_enabled';

    /**
     * A kill switch, not the boundary: the bearer token and its abilities keep callers out, so this is
     * fail-open like every other unit (docs/internals/mcp.md).
     */
    case FeatureMcpEnabled = 'feature_mcp_enabled';

    /**
     * Off by default: the only setting that makes the site issue outbound requests, for URLs in private
     * bodies as well as open ones (docs/internals/link-cards.md).
     */
    case LinkCardEnabled = 'link_card_enabled';

    /**
     * A creation gate only, off by default: managing, deleting and revoking tokens for an existing AI
     * account stay available with this off, so switching it off strands nothing.
     */
    case AiAccountsEnabled = 'ai_accounts_enabled';

    case AiAccountLimit = 'ai_account_limit';

    case BrandColor = 'brand_color';

    /** Logo and favicon are `files.name` tokens, not paths. */
    case BrandLogoFile = 'brand_logo_file';

    case BrandFaviconFile = 'brand_favicon_file';

    /** Markdown rendered through the member-body sanitizer, never emitted as operator HTML. */
    case LoginMessage = 'login_message';

    /**
     * Markdown rendered through the member-body sanitizer; the upgrade rewrites OpenPNE 3's raw HTML
     * into Markdown first.
     */
    case UserAgreement = 'user_agreement';

    case PrivacyPolicy = 'privacy_policy';

    case DefaultLook = 'default_look';

    /** A CSV of look ids, the registry's one list-valued key; the effective set is this plus the default look. */
    case SelectableLooks = 'selectable_looks';

    /**
     * A GroupTalkNotifyMode value: the web default of the `group_talk_new_message` kind, which a
     * member's own row overrides.
     */
    case GroupTalkNotifyDefault = 'group_talk_notify_default';

    case GroupTopicCommentReply = 'group_topic_comment_reply';

    case GroupEventCommentReply = 'group_event_comment_reply';

    /** OpenPNE 3's footer seed. */
    private const FOOTER_DEFAULT = 'Powered by <a href="https://www.openpne.jp/" target="_blank" rel="noopener">OpenPNE</a>';

    /** A hundred years: past it `subDays()` overflows into the future and the search window empties. */
    public const DIARY_SEARCH_PERIOD_MAX_DAYS = 36500;

    public function group(): SettingGroup
    {
        return match ($this) {
            self::SnsName, self::SnsTitle, self::AdminMailAddress => SettingGroup::Base,
            self::SurfaceMode => SettingGroup::Surface,
            self::RegistrationMode, self::CaptchaEnabled => SettingGroup::Auth,
            self::AllowWebPublicAge => SettingGroup::Privacy,
            self::TimelineAllowWebPublic, self::TimelinePostingEnabled => SettingGroup::Timeline,
            self::DiaryAllowWebPublic, self::DiarySearchEnabled, self::DiarySearchPeriodEnabled,
            self::DiarySearchPeriodDays => SettingGroup::Diary,
            self::GadgetHomeLayout, self::GadgetProfileLayout, self::GadgetLoginLayout => SettingGroup::GadgetLayout,
            self::CustomCss, self::PcHtmlHead, self::PcHtmlTop2, self::PcHtmlTop, self::PcHtmlBottom2,
            self::PcHtmlBottom, self::FooterBefore, self::FooterAfter => SettingGroup::Design,
            self::FeatureDiaryEnabled, self::FeatureDirectMessageEnabled, self::FeatureTimelineEnabled,
            self::FeatureGroupEnabled, self::FeatureGroupTopicEnabled, self::FeatureGroupEventEnabled,
            self::FeatureGroupTalkEnabled, self::FeatureFriendEnabled,
            self::FeatureMcpEnabled => SettingGroup::Features,
            self::LinkCardEnabled => SettingGroup::LinkCard,
            self::AiAccountsEnabled, self::AiAccountLimit => SettingGroup::Ai,
            self::BrandColor, self::BrandLogoFile, self::BrandFaviconFile => SettingGroup::Branding,
            self::LoginMessage => SettingGroup::LoginScreen,
            self::UserAgreement, self::PrivacyPolicy => SettingGroup::SitePolicy,
            self::DefaultLook, self::SelectableLooks => SettingGroup::Look,
            self::GroupTalkNotifyDefault => SettingGroup::GroupTalk,
            self::GroupTopicCommentReply, self::GroupEventCommentReply => SettingGroup::GroupBoard,
        };
    }

    /**
     * The OpenPNE 3 `sns_config.name` this setting upgrades from, or null when there is no single
     * source column. RegistrationMode has none: OpenPNE 3 composed it from `invite_mode` (auth.yml)
     * and `enable_registration` (sns_config) — `enable_registration=0` the global suspend,
     * `invite_mode` open vs invite — and no upgrade step reads either; a security key keeps its
     * fail-closed default until the operator sets it.
     */
    public function op3SourceName(): ?string
    {
        return match ($this) {
            self::SnsName => 'sns_name',
            self::SnsTitle => 'sns_title',
            self::AdminMailAddress => 'admin_mail_address',
            // The upgrade establishes classic_default out of band, not as a copied sns_config value.
            self::SurfaceMode => null,
            // OpenPNE 3's enable_cmd is not an ancestor of this: it embedded three named services in the
            // reader's browser, where this fetches arbitrary hosts from the server.
            self::LinkCardEnabled => null,
            self::RegistrationMode => null,
            self::CaptchaEnabled => 'is_use_captcha',
            self::AllowWebPublicAge => 'is_allow_web_public_flag_age',
            // OpenPNE 3's op_activity_is_open is an sfConfig (app.yml) value, not an sns_config row, so
            // there is nothing to copy; upgraded sites fall back to the same off default.
            self::TimelineAllowWebPublic => null,
            self::TimelinePostingEnabled => 'is_allow_post_activity',
            self::DiaryAllowWebPublic => 'op_diary_plugin_use_open_diary',
            self::DiarySearchEnabled => 'op_diary_plugin_search_enable',
            self::DiarySearchPeriodEnabled => 'op_diary_plugin_search_period_enable',
            self::DiarySearchPeriodDays => 'op_diary_plugin_search_period',
            // OpenPNE 3 stored the gadget layout as `{type}_layout` in sns_config (the home context
            // is keyed "home", not "gadget").
            self::GadgetHomeLayout => 'home_layout',
            self::GadgetProfileLayout => 'profile_layout',
            self::GadgetLoginLayout => 'login_layout',
            // Design keys keep the OpenPNE 3 sns_config name verbatim (1:1 copy).
            self::CustomCss, self::PcHtmlHead, self::PcHtmlTop2, self::PcHtmlTop, self::PcHtmlBottom2,
            self::PcHtmlBottom, self::FooterBefore, self::FooterAfter => $this->value,
            // Not a copied value: the feature flags upgrade through App\Upgrade\Steps\FeatureFlagUpgrade
            // steps, which write a row only for a unit OpenPNE 3 had switched off (see above).
            self::FeatureDiaryEnabled, self::FeatureDirectMessageEnabled, self::FeatureTimelineEnabled,
            self::FeatureGroupEnabled, self::FeatureGroupTopicEnabled, self::FeatureGroupEventEnabled,
            self::FeatureGroupTalkEnabled, self::FeatureFriendEnabled,
            self::FeatureMcpEnabled => null,
            self::AiAccountsEnabled, self::AiAccountLimit => null,
            self::BrandColor, self::BrandLogoFile, self::BrandFaviconFile => null,
            // OpenPNE 4-native: OpenPNE 3 put this kind of copy on the login page through the login
            // gadgets (freeArea), which upgrade as gadgets — there is no sns_config column behind it.
            self::LoginMessage => null,
            // The policy bodies keep the OpenPNE 3 sns_config name verbatim; only their markup is
            // rewritten, by the post-walk pass (the walk copies the value as-is).
            self::UserAgreement, self::PrivacyPolicy => $this->value,
            self::DefaultLook, self::SelectableLooks => null,
            self::GroupTalkNotifyDefault => null,
            self::GroupTopicCommentReply => 'op_community_topic_plugin_community_topic_comment_reply',
            self::GroupEventCommentReply => 'op_community_topic_plugin_community_event_comment_reply',
        };
    }

    /**
     * The Auth group is never copied from OpenPNE 3, so an OpenPNE 3 value cannot silently override a
     * security key's fail-closed default. The match is exhaustive so a new group must decide for itself.
     */
    public function isMigratedFromOp3(): bool
    {
        return match ($this->group()) {
            SettingGroup::Base, SettingGroup::GadgetLayout, SettingGroup::Design, SettingGroup::Privacy,
            SettingGroup::Diary, SettingGroup::SitePolicy, SettingGroup::GroupBoard,
            SettingGroup::Timeline => $this->op3SourceName() !== null,
            SettingGroup::Auth, SettingGroup::Surface, SettingGroup::Features,
            SettingGroup::Branding, SettingGroup::LoginScreen, SettingGroup::LinkCard,
            SettingGroup::Ai, SettingGroup::Look,
            SettingGroup::GroupTalk => false,
        };
    }

    /**
     * A security key (SettingGroup::Auth) returns a fixed fail-closed constant, never a config or env
     * value, so a missing row cannot open registration or drop a check.
     */
    public function default(): mixed
    {
        return match ($this) {
            self::SnsName => (string) config('app.name'),
            self::SnsTitle => '',
            self::AdminMailAddress => (string) config('mail.from.address'),
            self::SurfaceMode => SurfaceMode::tryFrom((string) config('openpne.surface_mode')) ?? SurfaceMode::ModernOnly,
            // Fail-closed, hardcoded (no env tier): a missing row must never open registration or
            // disable the bot challenge.
            self::RegistrationMode => 'invite',
            // Off until an operator decides this deployment should make outbound requests.
            self::LinkCardEnabled => false,
            // Off until an operator decides members may create AI accounts here.
            self::AiAccountsEnabled => false,
            // A cap an operator can raise, not a ceiling the code assumes; three is enough for the
            // one-assistant-per-tool case without a single member filling the member directory.
            self::AiAccountLimit => 3,
            self::CaptchaEnabled => true,
            // Off, matching OpenPNE 3's is_allow_web_public_flag_age default — members may not make
            // their age web-public until an admin opts in.
            self::AllowWebPublicAge => false,
            // Off, matching OpenPNE 3's op_activity_is_open default — posts may not be web-public
            // until an admin opts in.
            self::TimelineAllowWebPublic => false,
            // On, as OpenPNE 3 shipped it: the switch offers members an audience rather than publishing anything itself.
            self::DiaryAllowWebPublic => true,
            // On, as OpenPNE 3 shipped them: posting and the search screen are open until an admin closes them.
            self::TimelinePostingEnabled, self::DiarySearchEnabled => true,
            self::DiarySearchPeriodEnabled => false,
            self::DiarySearchPeriodDays => 30,
            self::GadgetHomeLayout, self::GadgetProfileLayout, self::GadgetLoginLayout => 'layoutA',
            // No custom CSS / HTML insertion until an operator sets it; the footer shows OpenPNE 3's bar.
            self::CustomCss, self::PcHtmlHead, self::PcHtmlTop2, self::PcHtmlTop, self::PcHtmlBottom2,
            self::PcHtmlBottom => '',
            self::FooterBefore, self::FooterAfter => self::FOOTER_DEFAULT,
            // On, so an absent row runs the feature until an administrator says otherwise.
            self::FeatureDiaryEnabled, self::FeatureDirectMessageEnabled, self::FeatureTimelineEnabled,
            self::FeatureGroupEnabled, self::FeatureGroupTopicEnabled, self::FeatureGroupEventEnabled,
            self::FeatureGroupTalkEnabled, self::FeatureFriendEnabled,
            self::FeatureMcpEnabled => true,
            // Unbranded until an administrator sets it: the Modern shell keeps its built-in color and
            // both surfaces keep the shipped favicon.
            self::BrandColor, self::BrandLogoFile, self::BrandFaviconFile => '',
            // No message until an administrator writes one; the login screen then shows nothing extra.
            self::LoginMessage => '',
            // Unwritten until an administrator writes them; the pages stay reachable and say so
            // (OpenPNE 3 shipped an "under construction" default in the same spot).
            self::UserAgreement, self::PrivacyPolicy => '',
            // The layout the site has always shipped; the unified one is an experiment an operator
            // opts into.
            self::DefaultLook => Look::Standard,
            // Nothing on offer until an operator ticks a look: the site runs on its default alone,
            // and the member config page shows no layout section.
            self::SelectableLooks => [],
            // The quiet end: an OSS site notifies about being named and nothing else until an
            // operator asks for more.
            self::GroupTalkNotifyDefault => GroupTalkNotifyMode::Mentions->value,
            self::GroupTopicCommentReply, self::GroupEventCommentReply => false,
        };
    }

    /** Validate and coerce an incoming (form) value to this key's typed value. */
    public function coerce(mixed $value): mixed
    {
        // Stored verbatim, no trim: a stylesheet's @charset is only honored at the very start, and a
        // Markdown body may open with an indented code block.
        if (in_array($this->group(), [SettingGroup::Design, SettingGroup::LoginScreen, SettingGroup::SitePolicy], true)) {
            return is_string($value) ? $value : (string) $value;
        }

        return match ($this) {
            self::CaptchaEnabled, self::AllowWebPublicAge, self::TimelineAllowWebPublic,
            self::DiaryAllowWebPublic, self::GroupTopicCommentReply, self::GroupEventCommentReply,
            self::FeatureDiaryEnabled, self::FeatureDirectMessageEnabled, self::FeatureTimelineEnabled,
            self::FeatureGroupEnabled, self::FeatureGroupTopicEnabled, self::FeatureGroupEventEnabled,
            self::FeatureGroupTalkEnabled, self::FeatureFriendEnabled, self::FeatureMcpEnabled,
            self::LinkCardEnabled, self::TimelinePostingEnabled, self::DiarySearchEnabled, self::DiarySearchPeriodEnabled,
            self::AiAccountsEnabled => (bool) $value,
            // A non-numeric submission lands on 0, the safe side of a cap.
            self::AiAccountLimit => (int) (is_string($value) ? trim($value) : $value),
            self::DiarySearchPeriodDays => self::clampDays((int) (is_string($value) ? trim($value) : $value)),
            // Normalize to the typed enum; an unknown value fails safe to the install default.
            self::SurfaceMode => $value instanceof SurfaceMode ? $value : (SurfaceMode::tryFrom(is_string($value) ? trim($value) : (string) $value) ?? $this->default()),
            self::DefaultLook => $value instanceof Look ? $value : (Look::tryFrom(trim((string) $value)) ?? $this->default()),
            // The one key whose value is a list: an explicit arm because the default one casts to
            // string, which an array cannot be.
            self::SelectableLooks => self::lookSet($value),
            default => is_string($value) ? trim($value) : (string) $value,
        };
    }

    /** Encode a typed value to the stored string `value`. */
    public function encode(mixed $value): string
    {
        return match ($this) {
            self::CaptchaEnabled, self::AllowWebPublicAge, self::TimelineAllowWebPublic,
            self::DiaryAllowWebPublic, self::GroupTopicCommentReply, self::GroupEventCommentReply,
            self::FeatureDiaryEnabled, self::FeatureDirectMessageEnabled, self::FeatureTimelineEnabled,
            self::FeatureGroupEnabled, self::FeatureGroupTopicEnabled, self::FeatureGroupEventEnabled,
            self::FeatureGroupTalkEnabled, self::FeatureFriendEnabled, self::FeatureMcpEnabled,
            self::LinkCardEnabled, self::TimelinePostingEnabled, self::DiarySearchEnabled, self::DiarySearchPeriodEnabled,
            self::AiAccountsEnabled => $value ? '1' : '0',
            self::AiAccountLimit, self::DiarySearchPeriodDays => (string) (int) $value,
            // A backed enum cannot be cast with (string); store its backing value.
            self::SurfaceMode => $value instanceof SurfaceMode ? $value->value : (string) $value,
            self::DefaultLook => $value instanceof Look ? $value->value : (string) $value,
            // Same reason as coerce()'s arm: the list is stored as CSV, and (string) on an array fatals.
            self::SelectableLooks => implode(',', array_column(self::lookSet($value), 'value')),
            default => (string) $value,
        };
    }

    /** Decode the stored string `value` to the typed value; an absent value is the default. */
    public function decode(?string $value): mixed
    {
        if ($value === null) {
            return $this->default();
        }

        return match ($this) {
            // Stored string → typed SurfaceMode; an unknown value fails safe to the install default.
            self::SurfaceMode => SurfaceMode::tryFrom($value) ?? $this->default(),
            // Same for the look: a value no registered look answers to is corruption, and lands on
            // the layout the site shipped with rather than on an experiment.
            self::DefaultLook => Look::tryFrom($value) ?? $this->default(),
            // Stored CSV → typed looks; an id no registered look answers to drops out of the set
            // rather than taking the whole row down with it.
            self::SelectableLooks => self::lookSet($value),
            // Fail-closed: only an explicit '0' disables the challenge; any other stored value keeps
            // it on, mirroring RegistrationMode::current()'s restrictive fallback on a bad value.
            self::CaptchaEnabled => $value !== '0',
            // Only an explicit '1' widens exposure; a malformed stored value is corruption, not consent,
            // and an absent row already returned the default above.
            self::AllowWebPublicAge, self::TimelineAllowWebPublic, self::DiaryAllowWebPublic,
            // Fail closed, like the other opt-in switches: only an explicit '1' turns it on.
            self::LinkCardEnabled, self::AiAccountsEnabled,
            self::GroupTopicCommentReply, self::GroupEventCommentReply => $value === '1',
            // A non-numeric stored value is corruption and reads as the shipped cap; a negative one
            // clamps to 0 rather than inverting the comparison it feeds.
            self::AiAccountLimit => is_numeric($value) ? max(0, (int) $value) : $this->default(),
            self::DiarySearchPeriodDays => is_numeric($value) ? self::clampDays((int) $value) : $this->default(),
            // Fail-open like a feature toggle: off is a policy, not an exposure, and a malformed value
            // must not silence every member.
            self::TimelinePostingEnabled, self::DiarySearchEnabled => $value !== '0',
            // OpenPNE 3 read this one as PHP truthy, so any stored value but '' and '0' narrows the window.
            self::DiarySearchPeriodEnabled => ! in_array($value, ['', '0'], true),
            // Fail-open, the one place that direction is right: only an explicit '0' takes a feature
            // down, so a malformed value cannot black out a module and strand its content.
            self::FeatureDiaryEnabled, self::FeatureDirectMessageEnabled, self::FeatureTimelineEnabled,
            self::FeatureGroupEnabled, self::FeatureGroupTopicEnabled, self::FeatureGroupEventEnabled,
            self::FeatureGroupTalkEnabled, self::FeatureFriendEnabled,
            self::FeatureMcpEnabled => $value !== '0',
            default => $value,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SnsName => __('SNS name'),
            self::SnsTitle => __('SNS title'),
            self::AdminMailAddress => __('Administrator email address'),
            self::SurfaceMode => __('Surface mode'),
            self::LinkCardEnabled => __('Fetch link previews from other sites'),
            self::AiAccountsEnabled => __('Allow members to create AI accounts'),
            self::AiAccountLimit => __('AI accounts per member'),
            self::RegistrationMode => __('Registration mode'),
            self::CaptchaEnabled => __('Require CAPTCHA'),
            self::AllowWebPublicAge => __('Allow members to make their age public to the web'),
            self::TimelineAllowWebPublic => __('Allow members to make %activity% posts public to the web'),
            self::TimelinePostingEnabled => __('Allow members to post %activity%'),
            self::DiaryAllowWebPublic => __('Allow members to make %diary% entries public to the web'),
            self::DiarySearchEnabled => __('Offer %diary% search'),
            self::DiarySearchPeriodEnabled => __('Limit %diary% search to recent entries'),
            self::DiarySearchPeriodDays => __('Days that %diary% search covers'),
            self::GadgetHomeLayout => __('Home layout'),
            self::GadgetProfileLayout => __('Profile layout'),
            self::GadgetLoginLayout => __('Login layout'),
            self::CustomCss => __('Custom CSS'),
            self::PcHtmlHead => __('HTML insertion: <head>'),
            self::PcHtmlTop2 => __('HTML insertion: page top (before content)'),
            self::PcHtmlTop => __('HTML insertion: content top'),
            self::PcHtmlBottom2 => __('HTML insertion: page bottom'),
            self::PcHtmlBottom => __('HTML insertion: content bottom'),
            // Chosen by the page's secure_page/insecure_page class (OpenPNE 3 isSecurePage), not the
            // viewer's login state.
            self::FooterBefore => __('Footer (insecure pages)'),
            self::FooterAfter => __('Footer (secure pages)'),
            // A feature toggle is labelled with the feature's own name (App\Support\Feature::label()).
            self::FeatureDiaryEnabled => __('%Diary%'),
            self::FeatureDirectMessageEnabled => __('Message'),
            self::FeatureTimelineEnabled => __('%Activity%'),
            self::FeatureGroupEnabled => __('%Community%'),
            self::FeatureGroupTopicEnabled => __('%Topic%'),
            self::FeatureGroupEventEnabled => __('Event'),
            self::FeatureGroupTalkEnabled => __('Talk'),
            self::FeatureFriendEnabled => __('%Friend%'),
            self::FeatureMcpEnabled => __('MCP server'),
            self::BrandColor => __('Brand color'),
            self::BrandLogoFile => __('Logo'),
            self::BrandFaviconFile => __('Favicon'),
            self::LoginMessage => __('Login screen message'),
            self::UserAgreement => __('Terms of service'),
            self::PrivacyPolicy => __('Privacy policy'),
            self::GroupTalkNotifyDefault => __('Talk notification default'),
            self::GroupTopicCommentReply => __('Reply link on %topic% comments'),
            self::GroupEventCommentReply => __('Reply link on event comments'),
            self::DefaultLook => __('Default UI layout'),
            self::SelectableLooks => __('Selectable UI layouts'),
        };
    }

    public function isRequired(): bool
    {
        return match ($this) {
            self::SnsName, self::AdminMailAddress, self::DefaultLook => true,
            self::SnsTitle, self::SurfaceMode, self::RegistrationMode, self::CaptchaEnabled, self::AllowWebPublicAge,
            self::TimelineAllowWebPublic, self::TimelinePostingEnabled, self::DiaryAllowWebPublic, self::DiarySearchEnabled,
            self::DiarySearchPeriodEnabled, self::DiarySearchPeriodDays, self::LinkCardEnabled,
            self::AiAccountsEnabled, self::AiAccountLimit,
            self::GadgetHomeLayout, self::GadgetProfileLayout, self::GadgetLoginLayout,
            self::CustomCss, self::PcHtmlHead, self::PcHtmlTop2, self::PcHtmlTop, self::PcHtmlBottom2,
            self::PcHtmlBottom, self::FooterBefore, self::FooterAfter,
            self::FeatureDiaryEnabled, self::FeatureDirectMessageEnabled, self::FeatureTimelineEnabled,
            self::FeatureGroupEnabled, self::FeatureGroupTopicEnabled, self::FeatureGroupEventEnabled,
            self::FeatureGroupTalkEnabled, self::FeatureFriendEnabled, self::FeatureMcpEnabled,
            self::BrandColor, self::BrandLogoFile, self::BrandFaviconFile,
            self::LoginMessage, self::UserAgreement, self::PrivacyPolicy, self::SelectableLooks,
            self::GroupTalkNotifyDefault, self::GroupTopicCommentReply, self::GroupEventCommentReply => false,
        };
    }

    public function isEmail(): bool
    {
        return $this === self::AdminMailAddress;
    }

    public function maxLength(): int
    {
        return 255;
    }

    /**
     * Maximum stored size in BYTES. The Design CSS/HTML, the login screen message and the policy
     * bodies are multi-line free text bounded only by the `sns_settings.value` TEXT column (65535
     * bytes, matching OpenPNE 3's sns_config.value), so they are validated by byte length, not the
     * char-count maxLength() the short identity fields use.
     */
    public function maxBytes(): int
    {
        return match ($this->group()) {
            SettingGroup::Design, SettingGroup::LoginScreen, SettingGroup::SitePolicy => 65535,
            default => $this->maxLength(),
        };
    }

    /**
     * Keys belonging to a group, in declaration order — what each admin page renders.
     *
     * @return list<self>
     */
    public static function inGroup(SettingGroup $group): array
    {
        return array_values(array_filter(self::cases(), fn (self $key): bool => $key->group() === $group));
    }

    private static function clampDays(int $days): int
    {
        return max(0, min(self::DIARY_SEARCH_PERIOD_MAX_DAYS, $days));
    }

    /** Resolve a key by its OpenPNE 3 source name, or null if none maps from it. */
    public static function fromOp3SourceName(string $name): ?self
    {
        foreach (self::cases() as $key) {
            if ($key->op3SourceName() === $name) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Normalize a look set — an array of Look|id (a checkbox post) or a CSV string (a stored row) —
     * to typed looks. Unknown ids drop, duplicates collapse, and the result is in registry order, so
     * one set has exactly one representation however it arrived.
     *
     * @return list<Look>
     */
    private static function lookSet(mixed $value): array
    {
        $chosen = [];
        foreach (is_array($value) ? $value : explode(',', (string) $value) as $id) {
            // A crafted post can nest an array where an id belongs; casting that would fatal.
            $look = $id instanceof Look ? $id : (is_scalar($id) ? Look::tryFrom(trim((string) $id)) : null);
            if ($look !== null) {
                $chosen[$look->value] = true;
            }
        }

        return array_values(array_filter(Look::cases(), static fn (Look $look): bool => isset($chosen[$look->value])));
    }
}
