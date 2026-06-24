<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The closed registry of per-member preferences kept in `member_preferences`.
 *
 * This enum is the single source of truth for which preferences exist: the case value is the
 * stored `key`, and each case declares its OpenPNE 3 origin, default, and value codec. The
 * upgrade derives its migrated name set from upgradableCases() (App\Upgrade\Steps\MemberPreferenceUpgrade),
 * so registering a key with an op3SourceName() is what makes it migrate — there is no second list to keep in sync.
 *
 * Most preferences are on the App\Support\Visibility scale (feature-scoped visibility defaults),
 * but the codec is value-type aware per case: PreferredSurface stores an App\Support\Surface and is
 * tri-state (an absent row means "no member choice", deferring to the SurfaceResolver fallback).
 * Identity-bearing / hot-path member attributes (locale, screen name) live as typed `members`
 * columns instead, not here.
 */
enum PreferenceKey: string
{
    /** Default audience pre-selected on the new-diary form (OpenPNE 3 MemberConfigDiaryForm). */
    case DiaryDefaultVisibility = 'diary_default_visibility';

    /** Who may see the age derived from the birthday profile field (independent of that field). */
    case AgeVisibility = 'age_visibility';

    /** The member's durable Classic/Modern surface choice; absent = follow the tenant/session default. */
    case PreferredSurface = 'preferred_surface';

    /** The OpenPNE 3 `member_config.name` this preference upgrades from, or null if it is OpenPNE 4-native. */
    public function op3SourceName(): ?string
    {
        return match ($this) {
            self::DiaryDefaultVisibility => 'diary_public_flag',
            self::AgeVisibility => 'age_public_flag',
            self::PreferredSurface => null,
        };
    }

    /**
     * Cases that migrate from `member_config` (those with an OpenPNE 3 source name). The single
     * derivation point for the upgrade's name set, so an OpenPNE 4-native key never leaks an empty
     * name into the upgrade SQL.
     *
     * @return list<self>
     */
    public static function upgradableCases(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $key): bool => $key->op3SourceName() !== null));
    }

    /**
     * Value used when the member has no stored row (an absent row means "follow this default").
     * Visibility keys carry a concrete fallback; PreferredSurface is tri-state, so its default is
     * null — "no member choice, defer to SurfaceResolver's session/tenant fallback".
     */
    public function default(): Visibility|Surface|null
    {
        return match ($this) {
            self::DiaryDefaultVisibility => Visibility::Members,
            // Fail-closed, matching OpenPNE 3 Member::getAge() (PUBLIC_FLAG_PRIVATE fallback).
            self::AgeVisibility => Visibility::Private,
            self::PreferredSurface => null,
        };
    }

    /** Decode the stored string `value` to the typed value; an absent/invalid value is the default. */
    public function decode(?string $value): Visibility|Surface|null
    {
        return match ($this) {
            self::DiaryDefaultVisibility, self::AgeVisibility => $this->decodeVisibility($value),
            self::PreferredSurface => $value === null ? null : Surface::tryFrom($value),
        };
    }

    /** Encode a typed value to the stored string `value`. */
    public function encode(Visibility|Surface $value): string
    {
        return (string) $value->value;
    }

    /** Resolve a key by its OpenPNE 3 source name, or null if no preference maps from it. */
    public static function fromOp3SourceName(string $name): ?self
    {
        foreach (self::cases() as $key) {
            if ($key->op3SourceName() === $name) {
                return $key;
            }
        }

        return null;
    }

    /** Validate and coerce an incoming Visibility value (e.g. from a form) to this key's typed value. */
    public function coerce(Visibility|int|string $value): Visibility
    {
        if ($value instanceof Visibility) {
            return $value;
        }

        // Reject non-digit strings rather than letting `(int) 'foo' === 0` slip through as Open.
        $int = is_string($value) ? (ctype_digit($value) ? (int) $value : null) : $value;
        $visibility = $int === null ? null : Visibility::tryFrom($int);
        if ($visibility === null) {
            throw new InvalidArgumentException("Invalid value for preference {$this->value}: ".var_export($value, true));
        }

        return $visibility;
    }

    private function decodeVisibility(?string $value): Visibility
    {
        $default = $this->default();
        assert($default instanceof Visibility);

        // ctype_digit guards against PHP's fail-open `(int) 'foo' === 0 === Open`: a non-digit
        // (corrupted row, '') must fall back to the default, never to the least-restrictive value.
        if ($value === null || ! ctype_digit($value)) {
            return $default;
        }

        return Visibility::tryFrom((int) $value) ?? $default;
    }
}
