<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The closed registry of global, site-wide SNS settings kept in `sns_settings`.
 *
 * This enum is the single source of truth for which SNS settings exist: the case value is the
 * stored `key`, and each case declares its OpenPNE 3 origin, default, codec, and admin-page group.
 *
 * `sns_settings` is the single source of truth: the admin page stores every key verbatim. `default()`
 * is the fallback used only while no row exists yet (fresh install / before first save) — not a
 * second, env-driven tier that competes with the stored value. A display key's default may borrow an
 * application config value (`config('app.name')`); a security key (SettingGroup::Auth) must instead
 * return a fixed fail-closed constant, so a missing row can never open registration or drop a check.
 *
 * Deliberately NOT ported from OpenPNE 3's sns_config (obsolete or superseded in OpenPNE 4):
 *   - enable_pc / enable_mobile — single responsive surface (App\Support\SurfaceResolver), no
 *     PC-vs-mobile split;
 *   - enable_cmd / enable_language — always-on or handled by other mechanisms.
 *
 * App\Upgrade\Steps\SnsSettingUpgrade copies the OpenPNE 3 sns_config values via `op3SourceName()`,
 * for the keys `isMigratedFromOp3()` allows (the security keys are excluded so an OpenPNE 3 value
 * cannot silently override their fail-closed default).
 */
enum SnsSettingKey: string
{
    /** SNS name shown in the header/logo, page titles, and outgoing mail (OpenPNE 3 general.sns_name). */
    case SnsName = 'sns_name';

    /** Site title shown in the document <title> on both surfaces, falling back to the SNS name (general.sns_title). */
    case SnsTitle = 'sns_title';

    /** From-address for system mail / administrator contact (general.admin_mail_address). */
    case AdminMailAddress = 'admin_mail_address';

    /** How the install serves the Classic/Modern surfaces (App\Support\SurfaceMode): modern_only|classic_default|modern_default. */
    case SurfaceMode = 'surface_mode';

    /** Who may create an account (App\Features\Auth\RegistrationMode value): open|invite|admin_only|closed. */
    case RegistrationMode = 'registration_mode';

    /** Whether the bot challenge is enforced on the auth entries. */
    case CaptchaEnabled = 'captcha_enabled';

    /** Whether members may make their age visible to web guests (OpenPNE 3 is_allow_web_public_flag_age). */
    case AllowWebPublicAge = 'allow_web_public_age';

    /** Whether members may make a timeline post visible to web guests (OpenPNE 3 op_activity_is_open). */
    case TimelineAllowWebPublic = 'timeline_allow_web_public';

    /**
     * Whether members may make a diary entry visible to web guests (OpenPNE 3
     * op_diary_plugin_use_open_diary). Off also closes the guest-reachable diary screens
     * (App\Http\Middleware\EnsureWebPublicDiaryEnabled), as OpenPNE 3 did.
     */
    case DiaryAllowWebPublic = 'diary_allow_web_public';

    /** The Classic gadget layout (layoutA/B/C) for the home / profile / login pages (App\Gadgets\GadgetLayout). */
    case GadgetHomeLayout = 'gadget_home_layout';

    case GadgetProfileLayout = 'gadget_profile_layout';

    case GadgetLoginLayout = 'gadget_login_layout';

    /** OpenPNE 3 admin custom CSS, served to Classic as a `text/css` document (design.customizing_css). */
    case CustomCss = 'customizing_css';

    /** OpenPNE 3 PC HTML insertion slots, emitted raw at fixed positions in the Classic shell (design.*). */
    case PcHtmlHead = 'pc_html_head';

    case PcHtmlTop2 = 'pc_html_top2';

    case PcHtmlTop = 'pc_html_top';

    case PcHtmlBottom2 = 'pc_html_bottom2';

    case PcHtmlBottom = 'pc_html_bottom';

    /** Classic footer HTML, by page security (insecure / secure pages, OpenPNE 3 footer_before/after — not the viewer's login state). */
    case FooterBefore = 'footer_before';

    case FooterAfter = 'footer_after';

    /**
     * Feature availability toggles (App\Support\Feature). An absent row means enabled, so an install
     * that never opened the page runs every feature — OpenPNE 3's lazy `plugin` rows.
     */
    case FeatureDiaryEnabled = 'feature_diary_enabled';

    case FeatureMessageEnabled = 'feature_message_enabled';

    case FeatureTimelineEnabled = 'feature_timeline_enabled';

    case FeatureCommunityEnabled = 'feature_community_enabled';

    case FeatureCommunityTopicEnabled = 'feature_community_topic_enabled';

    case FeatureCommunityEventEnabled = 'feature_community_event_enabled';

    /**
     * OpenPNE 3 kept this one in sns_config (`enable_friend_link`), not in `plugin`. It still
     * upgrades through App\Upgrade\Steps\FriendFeatureUpgrade, which writes only a disabled row, so
     * absent = enabled holds on both sides.
     */
    case FeatureFriendEnabled = 'feature_friend_enabled';

    /** Per-site brand color as `#rrggbb`, or '' for none (App\Support\BrandColor). */
    case BrandColor = 'brand_color';

    /**
     * The `files.name` token of the uploaded logo mark, or '' for none. An opaque token, not a path:
     * the bytes are served by App\Http\Controllers\PublicFileController, so no storage:link is needed.
     */
    case BrandLogoFile = 'brand_logo_file';

    /** The `files.name` token of the uploaded favicon (PNG), or '' for none. */
    case BrandFaviconFile = 'brand_favicon_file';

    /**
     * Markdown shown above the sign-in form on the Modern login screen, or '' for none. Rendered
     * through the member-body sanitizer (App\Support\MarkdownText), not emitted as operator HTML.
     */
    case LoginMessage = 'login_message';

    /**
     * The site's terms of service and privacy policy bodies, as Markdown (OpenPNE 3 sns_config
     * user_agreement / privacy_policy). OpenPNE 3 emitted the stored value as raw HTML wrapped in
     * nl2br; here it renders through the member-body sanitizer, so the upgrade rewrites the
     * OpenPNE 3 text into Markdown first (App\Upgrade\Runner\Op3PolicyMarkdown).
     */
    case UserAgreement = 'user_agreement';

    case PrivacyPolicy = 'privacy_policy';

    /**
     * OpenPNE 3's default footer (its sns_config footer_before/after seed), the install default for the
     * footer keys so a fresh site shows the same bar it always did.
     */
    private const FOOTER_DEFAULT = 'Powered by <a href="https://www.openpne.jp/" target="_blank" rel="noopener">OpenPNE</a>';

    /** Which admin page edits this setting. */
    public function group(): SettingGroup
    {
        return match ($this) {
            self::SnsName, self::SnsTitle, self::AdminMailAddress => SettingGroup::Base,
            self::SurfaceMode => SettingGroup::Surface,
            self::RegistrationMode, self::CaptchaEnabled => SettingGroup::Auth,
            self::AllowWebPublicAge => SettingGroup::Privacy,
            self::TimelineAllowWebPublic => SettingGroup::Timeline,
            self::DiaryAllowWebPublic => SettingGroup::Diary,
            self::GadgetHomeLayout, self::GadgetProfileLayout, self::GadgetLoginLayout => SettingGroup::GadgetLayout,
            self::CustomCss, self::PcHtmlHead, self::PcHtmlTop2, self::PcHtmlTop, self::PcHtmlBottom2,
            self::PcHtmlBottom, self::FooterBefore, self::FooterAfter => SettingGroup::Design,
            self::FeatureDiaryEnabled, self::FeatureMessageEnabled, self::FeatureTimelineEnabled,
            self::FeatureCommunityEnabled, self::FeatureCommunityTopicEnabled,
            self::FeatureCommunityEventEnabled, self::FeatureFriendEnabled => SettingGroup::Features,
            self::BrandColor, self::BrandLogoFile, self::BrandFaviconFile => SettingGroup::Branding,
            self::LoginMessage => SettingGroup::LoginScreen,
            self::UserAgreement, self::PrivacyPolicy => SettingGroup::SitePolicy,
        };
    }

    /**
     * The OpenPNE 3 `sns_config.name` this setting upgrades from, or null when there is no single
     * source column. RegistrationMode is composed from OpenPNE 3's `invite_mode` (auth.yml) and
     * `enable_registration` (sns_config) together — `enable_registration=0` is the global suspend
     * while `invite_mode` picks open vs invite — so its upgrade is a dedicated composite step, not a
     * 1:1 column copy.
     */
    public function op3SourceName(): ?string
    {
        return match ($this) {
            self::SnsName => 'sns_name',
            self::SnsTitle => 'sns_title',
            self::AdminMailAddress => 'admin_mail_address',
            // OpenPNE 4-native: no OpenPNE 3 sns_config column. The upgrade establishes classic_default
            // out of band (App\Upgrade\Runner\UpgradeRunner), not as a copied setting.
            self::SurfaceMode => null,
            self::RegistrationMode => null,
            self::CaptchaEnabled => 'is_use_captcha',
            self::AllowWebPublicAge => 'is_allow_web_public_flag_age',
            // OpenPNE 3's op_activity_is_open is an sfConfig (app.yml) value, not an sns_config row, so
            // there is nothing to copy; upgraded sites fall back to the same off default.
            self::TimelineAllowWebPublic => null,
            self::DiaryAllowWebPublic => 'op_diary_plugin_use_open_diary',
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
            self::FeatureDiaryEnabled, self::FeatureMessageEnabled, self::FeatureTimelineEnabled,
            self::FeatureCommunityEnabled, self::FeatureCommunityTopicEnabled,
            self::FeatureCommunityEventEnabled, self::FeatureFriendEnabled => null,
            // OpenPNE 4-native: OpenPNE 3 had no per-site logo/color/favicon settings to copy.
            self::BrandColor, self::BrandLogoFile, self::BrandFaviconFile => null,
            // OpenPNE 4-native: OpenPNE 3 put this kind of copy on the login page through the login
            // gadgets (freeArea), which upgrade as gadgets — there is no sns_config column behind it.
            self::LoginMessage => null,
            // The policy bodies keep the OpenPNE 3 sns_config name verbatim; only their markup is
            // rewritten, by the post-walk pass (the walk copies the value as-is).
            self::UserAgreement, self::PrivacyPolicy => $this->value,
        };
    }

    /**
     * Whether SnsSettingUpgrade copies this key from OpenPNE 3 sns_config: a key with an
     * op3SourceName() does, except in the Auth group, where copying an OpenPNE 3 value could silently
     * override a security key's fail-closed default (an OpenPNE 3 site with the CAPTCHA off would turn
     * it off here) — carrying those over is a separate, security-reviewed decision. The match is
     * exhaustive so that adding a group forces the same decision rather than inheriting one.
     */
    public function isMigratedFromOp3(): bool
    {
        return match ($this->group()) {
            SettingGroup::Base, SettingGroup::GadgetLayout, SettingGroup::Design, SettingGroup::Privacy,
            SettingGroup::Diary, SettingGroup::SitePolicy => $this->op3SourceName() !== null,
            SettingGroup::Auth, SettingGroup::Timeline, SettingGroup::Surface, SettingGroup::Features,
            SettingGroup::Branding, SettingGroup::LoginScreen => false,
        };
    }

    /**
     * Fallback used only while no row exists (fresh install / before first save), never as a runtime
     * tier above a stored value. A display key may borrow an application config value; a security key
     * must return a fixed fail-closed constant. Returns `mixed` because keys decode to string/bool/enum.
     */
    public function default(): mixed
    {
        return match ($this) {
            self::SnsName => (string) config('app.name'),
            self::SnsTitle => '',
            self::AdminMailAddress => (string) config('mail.from.address'),
            // Install fallback (no row): the fresh-site default set in config/openpne.php. The upgrade
            // writes a classic_default row, so SnsSettingService is the authoritative tier.
            self::SurfaceMode => SurfaceMode::tryFrom((string) config('openpne.surface_mode')) ?? SurfaceMode::ModernOnly,
            // Fail-closed, hardcoded (no env tier): a missing row must never open registration or
            // disable the bot challenge.
            self::RegistrationMode => 'invite',
            self::CaptchaEnabled => true,
            // Off, matching OpenPNE 3's is_allow_web_public_flag_age default — members may not make
            // their age web-public until an admin opts in.
            self::AllowWebPublicAge => false,
            // Off, matching OpenPNE 3's op_activity_is_open default — posts may not be web-public
            // until an admin opts in.
            self::TimelineAllowWebPublic => false,
            // On, matching OpenPNE 3's op_diary_plugin_use_open_diary default — the one web-public
            // switch OpenPNE 3 shipped enabled. It offers members the audience rather than publishing
            // anything itself, and a site that turned it off upgrades an explicit '0'.
            self::DiaryAllowWebPublic => true,
            self::GadgetHomeLayout, self::GadgetProfileLayout, self::GadgetLoginLayout => 'layoutA',
            // No custom CSS / HTML insertion until an operator sets it; the footer shows OpenPNE 3's bar.
            self::CustomCss, self::PcHtmlHead, self::PcHtmlTop2, self::PcHtmlTop, self::PcHtmlBottom2,
            self::PcHtmlBottom => '',
            self::FooterBefore, self::FooterAfter => self::FOOTER_DEFAULT,
            // On, so a fresh or upgraded install runs every feature until an administrator says otherwise.
            self::FeatureDiaryEnabled, self::FeatureMessageEnabled, self::FeatureTimelineEnabled,
            self::FeatureCommunityEnabled, self::FeatureCommunityTopicEnabled,
            self::FeatureCommunityEventEnabled, self::FeatureFriendEnabled => true,
            // Unbranded until an administrator sets it: the Modern shell keeps its built-in color and
            // both surfaces keep the shipped favicon.
            self::BrandColor, self::BrandLogoFile, self::BrandFaviconFile => '',
            // No message until an administrator writes one; the login screen then shows nothing extra.
            self::LoginMessage => '',
            // Unwritten until an administrator writes them; the pages stay reachable and say so
            // (OpenPNE 3 shipped an "under construction" default in the same spot).
            self::UserAgreement, self::PrivacyPolicy => '',
        };
    }

    /** Validate and coerce an incoming (form) value to this key's typed value. */
    public function coerce(mixed $value): mixed
    {
        // Multi-line free text is stored verbatim: trimming would drop a leading newline or space,
        // and both bodies give the first character meaning — a stylesheet's @charset / @import is
        // only honored at the very start (OpenPNE 3 stored the design keys with trim disabled), and
        // a Markdown body that opens with an indented code block would lose it.
        if (in_array($this->group(), [SettingGroup::Design, SettingGroup::LoginScreen, SettingGroup::SitePolicy], true)) {
            return is_string($value) ? $value : (string) $value;
        }

        return match ($this) {
            self::CaptchaEnabled, self::AllowWebPublicAge, self::TimelineAllowWebPublic,
            self::DiaryAllowWebPublic,
            self::FeatureDiaryEnabled, self::FeatureMessageEnabled, self::FeatureTimelineEnabled,
            self::FeatureCommunityEnabled, self::FeatureCommunityTopicEnabled,
            self::FeatureCommunityEventEnabled, self::FeatureFriendEnabled => (bool) $value, // PHP treats the stored '0' as false, '1' as true.
            // Normalize to the typed enum; an unknown value fails safe to the install default.
            self::SurfaceMode => $value instanceof SurfaceMode ? $value : (SurfaceMode::tryFrom(is_string($value) ? trim($value) : (string) $value) ?? $this->default()),
            default => is_string($value) ? trim($value) : (string) $value,
        };
    }

    /** Encode a typed value to the stored string `value`. */
    public function encode(mixed $value): string
    {
        return match ($this) {
            self::CaptchaEnabled, self::AllowWebPublicAge, self::TimelineAllowWebPublic,
            self::DiaryAllowWebPublic,
            self::FeatureDiaryEnabled, self::FeatureMessageEnabled, self::FeatureTimelineEnabled,
            self::FeatureCommunityEnabled, self::FeatureCommunityTopicEnabled,
            self::FeatureCommunityEventEnabled, self::FeatureFriendEnabled => $value ? '1' : '0',
            // A backed enum cannot be cast with (string); store its backing value.
            self::SurfaceMode => $value instanceof SurfaceMode ? $value->value : (string) $value,
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
            // Fail-closed: only an explicit '0' disables the challenge; any other stored value keeps
            // it on, mirroring RegistrationMode::current()'s restrictive fallback on a bad value.
            self::CaptchaEnabled => $value !== '0',
            // Fail-closed the OTHER way: here `true` widens exposure, so only an explicit '1' enables
            // it; a malformed/empty value must not open a web-public audience (the opposite of
            // CaptchaEnabled). This is about a STORED value: an absent row returned above, so diary's
            // on-by-default install fallback is untouched — unreadable is corruption, not consent.
            self::AllowWebPublicAge, self::TimelineAllowWebPublic, self::DiaryAllowWebPublic => $value === '1',
            // Fail-OPEN, the one place that direction is right: an availability switch, so only an
            // explicit '0' takes a feature down. A malformed value must not black out a module and
            // strand its content — the opposite trade-off from the security keys above.
            self::FeatureDiaryEnabled, self::FeatureMessageEnabled, self::FeatureTimelineEnabled,
            self::FeatureCommunityEnabled, self::FeatureCommunityTopicEnabled,
            self::FeatureCommunityEventEnabled, self::FeatureFriendEnabled => $value !== '0',
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
            self::RegistrationMode => __('Registration mode'),
            self::CaptchaEnabled => __('Require CAPTCHA'),
            self::AllowWebPublicAge => __('Allow members to make their age public to the web'),
            self::TimelineAllowWebPublic => __('Allow members to make %activity% posts public to the web'),
            self::DiaryAllowWebPublic => __('Allow members to make %diary% entries public to the web'),
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
            self::FeatureMessageEnabled => __('Message'),
            self::FeatureTimelineEnabled => __('%Activity%'),
            self::FeatureCommunityEnabled => __('%Community%'),
            self::FeatureCommunityTopicEnabled => __('%Topic%'),
            self::FeatureCommunityEventEnabled => __('Event'),
            self::FeatureFriendEnabled => __('%Friend%'),
            self::BrandColor => __('Brand color'),
            self::BrandLogoFile => __('Logo'),
            self::BrandFaviconFile => __('Favicon'),
            self::LoginMessage => __('Login screen message'),
            self::UserAgreement => __('Terms of service'),
            self::PrivacyPolicy => __('Privacy policy'),
        };
    }

    public function isRequired(): bool
    {
        return match ($this) {
            self::SnsName, self::AdminMailAddress => true,
            self::SnsTitle, self::SurfaceMode, self::RegistrationMode, self::CaptchaEnabled, self::AllowWebPublicAge,
            self::TimelineAllowWebPublic, self::DiaryAllowWebPublic,
            self::GadgetHomeLayout, self::GadgetProfileLayout, self::GadgetLoginLayout,
            self::CustomCss, self::PcHtmlHead, self::PcHtmlTop2, self::PcHtmlTop, self::PcHtmlBottom2,
            self::PcHtmlBottom, self::FooterBefore, self::FooterAfter,
            self::FeatureDiaryEnabled, self::FeatureMessageEnabled, self::FeatureTimelineEnabled,
            self::FeatureCommunityEnabled, self::FeatureCommunityTopicEnabled,
            self::FeatureCommunityEventEnabled, self::FeatureFriendEnabled,
            self::BrandColor, self::BrandLogoFile, self::BrandFaviconFile,
            self::LoginMessage, self::UserAgreement, self::PrivacyPolicy => false,
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
}
