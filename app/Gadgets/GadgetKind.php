<?php

declare(strict_types=1);

namespace App\Gadgets;

use Illuminate\Support\Str;

/**
 * A gadget kind — what a `gadgets.name` value renders as (OpenPNE 3 gadget definition: component,
 * config schema, viewable_privilege), registered in GadgetKindRegistry.
 */
abstract class GadgetKind
{
    /** viewable_privilege: members only (OpenPNE 3 value 1). */
    public const MEMBERS = 1;

    /** viewable_privilege: anyone, including guests (OpenPNE 3 value 4). */
    public const ANYONE = 4;

    /** The OpenPNE 3 gadget name; matches the stored `gadgets.name`. */
    abstract public function name(): string;

    /**
     * Contexts this kind may be placed in (admin "add gadget" choices).
     *
     * @return list<string>
     */
    abstract public function contexts(): array;

    /** The Blade dynamic-component name the renderer resolves to this kind's view. */
    abstract public function component(): string;

    /** Human label for the admin (add-gadget dropdown, table). */
    public function label(): string
    {
        return Str::headline($this->name());
    }

    /**
     * A one-line "what this shows" for the admin Gadget picker. Centralised here (a closed registry of
     * presentational copy, easy to review/translate in one place) rather than scattered per kind; empty
     * for kinds without one, which fall back to a generic line.
     */
    public function description(): string
    {
        return match ($this->name()) {
            'freeArea' => __('A free area for a custom title and HTML/text.'),
            'informationBox' => __('Announcements from the site.'),
            'memberImageBox' => __("The member's profile image."),
            'friendListBox' => __("A list of the member's friends."),
            'communityJoinListBox' => __('A list of the communities the member belongs to.'),
            'loginForm' => __('The login form, shown to logged-out visitors.'),
            'profileListBox' => __("The member's profile fields."),
            'languageSelecterBox' => __('A language switcher.'),
            'linkListBox' => __('A list of links.'),
            'searchBox' => __('A member search box.'),
            default => '',
        };
    }

    /**
     * Config parameters for a context, in form order.
     *
     * @return list<GadgetConfigField>
     */
    public function configFields(string $context): array
    {
        return [];
    }

    /** Per (context, kind): a kind can be members-only in one context and public in another. */
    public function viewablePrivilege(string $context): int
    {
        return self::MEMBERS;
    }

    /** Whether the kind's dependency feature is present; an unavailable kind is hidden at render. */
    public function isAvailable(): bool
    {
        return true;
    }

    /** This kind's OpenPNE 3-compatible DOM id (the custom-CSS seam); null when OpenPNE 3 emitted none. */
    public function partId(int $gadgetId): ?string
    {
        return null;
    }
}
