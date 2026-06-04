<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The closed registry of per-member preferences kept in `member_preferences`.
 *
 * This enum is the single source of truth for which preferences exist: the case value is the
 * stored `key`, and each case declares its OpenPNE 3 origin, default, and value codec. The
 * upgrade derives its migrated name set from cases() (App\Upgrade\Steps\MemberPreferenceUpgrade),
 * so registering a key here is what makes it migrate — there is no second list to keep in sync.
 *
 * Every preference today is on the App\Support\Visibility scale (feature-scoped visibility
 * defaults). Identity-bearing / hot-path member attributes (locale, screen name) live as typed
 * `members` columns instead, not here. Generalise the codec when a non-Visibility key appears.
 */
enum PreferenceKey: string
{
    /** Default audience pre-selected on the new-diary form (OpenPNE 3 MemberConfigDiaryForm). */
    case DiaryDefaultVisibility = 'diary_default_visibility';

    /** Who may see the age derived from the birthday profile field (independent of that field). */
    case AgeVisibility = 'age_visibility';

    /** The OpenPNE 3 `member_config.name` this preference upgrades from. */
    public function op3SourceName(): string
    {
        return match ($this) {
            self::DiaryDefaultVisibility => 'diary_public_flag',
            self::AgeVisibility => 'age_public_flag',
        };
    }

    /** Value used when the member has no stored row (an absent row means "follow this default"). */
    public function default(): Visibility
    {
        return match ($this) {
            self::DiaryDefaultVisibility => Visibility::Members,
            // Fail-closed, matching OpenPNE 3 Member::getAge() (PUBLIC_FLAG_PRIVATE fallback).
            self::AgeVisibility => Visibility::Private,
        };
    }

    /** Decode the stored string `value` to the typed value; an absent/invalid value is the default. */
    public function decode(?string $value): Visibility
    {
        if ($value === null) {
            return $this->default();
        }

        return Visibility::tryFrom((int) $value) ?? $this->default();
    }

    /** Encode a typed value to the stored string `value`. Rejects values outside this key's domain. */
    public function encode(Visibility $value): string
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

    /** Validate and coerce an incoming value (e.g. from a form) to this key's typed value. */
    public function coerce(Visibility|int|string $value): Visibility
    {
        if ($value instanceof Visibility) {
            return $value;
        }

        $visibility = Visibility::tryFrom((int) $value);
        if ($visibility === null) {
            throw new InvalidArgumentException("Invalid value for preference {$this->value}: {$value}");
        }

        return $visibility;
    }
}
