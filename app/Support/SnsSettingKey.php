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
 *   - enable_friend_link / enable_cmd / enable_language — always-on or handled by other mechanisms.
 *
 * App\Upgrade\Steps\SnsSettingUpgrade copies the OpenPNE 3 sns_config values via `op3SourceName()`,
 * for the keys `isMigratedFromOp3()` allows (display + gadget layout + design customization; the
 * security keys are excluded so an OpenPNE 3 value cannot silently override their fail-closed default).
 */
enum SnsSettingKey: string
{
    /** SNS name shown in the header/logo, page titles, and outgoing mail (OpenPNE 3 general.sns_name). */
    case SnsName = 'sns_name';

    /** Site title shown in the document <title> on both surfaces, falling back to the SNS name (general.sns_title). */
    case SnsTitle = 'sns_title';

    /** From-address for system mail / administrator contact (general.admin_mail_address). */
    case AdminMailAddress = 'admin_mail_address';

    /** Who may create an account (App\Features\Auth\RegistrationMode value): open|invite|admin_only|closed. */
    case RegistrationMode = 'registration_mode';

    /** Whether the bot challenge is enforced on the auth entries. */
    case CaptchaEnabled = 'captcha_enabled';

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

    /** Classic footer HTML, by page security: insecure (logged-out) and secure (logged-in) — OpenPNE 3 footer_before/after. */
    case FooterBefore = 'footer_before';

    case FooterAfter = 'footer_after';

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
            self::RegistrationMode, self::CaptchaEnabled => SettingGroup::Auth,
            self::GadgetHomeLayout, self::GadgetProfileLayout, self::GadgetLoginLayout => SettingGroup::GadgetLayout,
            self::CustomCss, self::PcHtmlHead, self::PcHtmlTop2, self::PcHtmlTop, self::PcHtmlBottom2,
            self::PcHtmlBottom, self::FooterBefore, self::FooterAfter => SettingGroup::Design,
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
            self::RegistrationMode => null,
            self::CaptchaEnabled => 'is_use_captcha',
            // OpenPNE 3 stored the gadget layout as `{type}_layout` in sns_config (the home context
            // is keyed "home", not "gadget").
            self::GadgetHomeLayout => 'home_layout',
            self::GadgetProfileLayout => 'profile_layout',
            self::GadgetLoginLayout => 'login_layout',
            // Design keys keep the OpenPNE 3 sns_config name verbatim (1:1 copy).
            self::CustomCss, self::PcHtmlHead, self::PcHtmlTop2, self::PcHtmlTop, self::PcHtmlBottom2,
            self::PcHtmlBottom, self::FooterBefore, self::FooterAfter => $this->value,
        };
    }

    /**
     * Whether SnsSettingUpgrade copies this key from OpenPNE 3 sns_config. Display and gadget-layout
     * keys do; the security keys (registration mode, CAPTCHA) are deliberately excluded — copying an
     * OpenPNE 3 value could silently override their fail-closed default (e.g. an OpenPNE 3 site with
     * the CAPTCHA off would turn it off here), so carrying those over is a separate, security-reviewed
     * decision rather than part of this copy.
     */
    public function isMigratedFromOp3(): bool
    {
        return match ($this->group()) {
            SettingGroup::Base, SettingGroup::GadgetLayout, SettingGroup::Design => $this->op3SourceName() !== null,
            SettingGroup::Auth => false,
        };
    }

    /**
     * Fallback used only while no row exists (fresh install / before first save), never as a runtime
     * tier above a stored value. A display key may borrow an application config value; a security key
     * must return a fixed fail-closed constant. Returns `mixed` because later keys decode to bool/enum.
     */
    public function default(): mixed
    {
        return match ($this) {
            self::SnsName => (string) config('app.name'),
            self::SnsTitle => '',
            self::AdminMailAddress => (string) config('mail.from.address'),
            // Fail-closed, hardcoded (no env tier): a missing row must never open registration or
            // disable the bot challenge.
            self::RegistrationMode => 'invite',
            self::CaptchaEnabled => true,
            self::GadgetHomeLayout, self::GadgetProfileLayout, self::GadgetLoginLayout => 'layoutA',
            // No custom CSS / HTML insertion until an operator sets it; the footer shows OpenPNE 3's bar.
            self::CustomCss, self::PcHtmlHead, self::PcHtmlTop2, self::PcHtmlTop, self::PcHtmlBottom2,
            self::PcHtmlBottom => '',
            self::FooterBefore, self::FooterAfter => self::FOOTER_DEFAULT,
        };
    }

    /** Validate and coerce an incoming (form) value to this key's typed value. */
    public function coerce(mixed $value): mixed
    {
        // Design CSS/HTML is stored verbatim: trimming would drop a leading newline or space, and a
        // stylesheet's @charset / @import is only honored at the very start (OpenPNE 3 stored these
        // with trim disabled).
        if ($this->group() === SettingGroup::Design) {
            return is_string($value) ? $value : (string) $value;
        }

        return match ($this) {
            self::CaptchaEnabled => (bool) $value, // PHP treats the stored '0' as false, '1' as true.
            default => is_string($value) ? trim($value) : (string) $value,
        };
    }

    /** Encode a typed value to the stored string `value`. */
    public function encode(mixed $value): string
    {
        return match ($this) {
            self::CaptchaEnabled => $value ? '1' : '0',
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
            // Fail-closed: only an explicit '0' disables the challenge; any other stored value keeps
            // it on, mirroring RegistrationMode::current()'s restrictive fallback on a bad value.
            self::CaptchaEnabled => $value !== '0',
            default => $value,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SnsName => __('SNS name'),
            self::SnsTitle => __('SNS title'),
            self::AdminMailAddress => __('Administrator email address'),
            self::RegistrationMode => __('Registration mode'),
            self::CaptchaEnabled => __('Require CAPTCHA'),
            self::GadgetHomeLayout => __('Home layout'),
            self::GadgetProfileLayout => __('Profile layout'),
            self::GadgetLoginLayout => __('Login layout'),
            self::CustomCss => __('Custom CSS'),
            self::PcHtmlHead => __('HTML insertion: <head>'),
            self::PcHtmlTop2 => __('HTML insertion: page top (before content)'),
            self::PcHtmlTop => __('HTML insertion: content top'),
            self::PcHtmlBottom2 => __('HTML insertion: page bottom'),
            self::PcHtmlBottom => __('HTML insertion: content bottom'),
            self::FooterBefore => __('Footer (logged out)'),
            self::FooterAfter => __('Footer (logged in)'),
        };
    }

    public function isRequired(): bool
    {
        return match ($this) {
            self::SnsName, self::AdminMailAddress => true,
            self::SnsTitle, self::RegistrationMode, self::CaptchaEnabled,
            self::GadgetHomeLayout, self::GadgetProfileLayout, self::GadgetLoginLayout,
            self::CustomCss, self::PcHtmlHead, self::PcHtmlTop2, self::PcHtmlTop, self::PcHtmlBottom2,
            self::PcHtmlBottom, self::FooterBefore, self::FooterAfter => false,
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
     * Maximum stored size in BYTES. Design CSS/HTML is multi-line free text bounded only by the
     * `sns_settings.value` TEXT column (65535 bytes, matching OpenPNE 3's sns_config.value), so it is
     * validated by byte length, not the char-count maxLength() the short identity fields use.
     */
    public function maxBytes(): int
    {
        return match ($this->group()) {
            SettingGroup::Design => 65535,
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
