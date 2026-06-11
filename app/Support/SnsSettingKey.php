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
 *   - is_use_captcha — becomes an Auth-group key with a fail-closed default, not a config read;
 *   - enable_friend_link / enable_cmd / enable_language — always-on or handled by other mechanisms.
 *
 * The OpenPNE 3 sns_config -> sns_settings data upgrade is out of scope here; `op3SourceName()` is
 * the seam that later upgrade step maps from.
 */
enum SnsSettingKey: string
{
    /** SNS name shown in the header/logo, page titles, and outgoing mail (OpenPNE 3 general.sns_name). */
    case SnsName = 'sns_name';

    /** Site title shown in the document <title> on both surfaces, falling back to the SNS name (general.sns_title). */
    case SnsTitle = 'sns_title';

    /** From-address for system mail / administrator contact (general.admin_mail_address). */
    case AdminMailAddress = 'admin_mail_address';

    /** Which admin page edits this setting. */
    public function group(): SettingGroup
    {
        return match ($this) {
            self::SnsName, self::SnsTitle, self::AdminMailAddress => SettingGroup::Base,
        };
    }

    /** The OpenPNE 3 `sns_config.name` this setting upgrades from. */
    public function op3SourceName(): string
    {
        return match ($this) {
            self::SnsName => 'sns_name',
            self::SnsTitle => 'sns_title',
            self::AdminMailAddress => 'admin_mail_address',
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
        };
    }

    /** Validate and coerce an incoming (form) value to this key's typed value. */
    public function coerce(mixed $value): mixed
    {
        // Stage 1 keys are free-text strings; trim so " " is treated as empty by the caller.
        return is_string($value) ? trim($value) : (string) $value;
    }

    /** Encode a typed value to the stored string `value`. */
    public function encode(mixed $value): string
    {
        return (string) $value;
    }

    /** Decode the stored string `value` to the typed value; an absent value is the default. */
    public function decode(?string $value): mixed
    {
        return $value ?? $this->default();
    }

    public function label(): string
    {
        return match ($this) {
            self::SnsName => __('SNS name'),
            self::SnsTitle => __('SNS title'),
            self::AdminMailAddress => __('Administrator email address'),
        };
    }

    public function isRequired(): bool
    {
        return match ($this) {
            self::SnsName, self::AdminMailAddress => true,
            self::SnsTitle => false,
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
