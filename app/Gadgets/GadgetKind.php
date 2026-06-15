<?php

declare(strict_types=1);

namespace App\Gadgets;

/**
 * A gadget kind — what a `gadgets.name` value renders as. Mirrors an OpenPNE 3 gadget definition
 * (caption, component, config schema, viewable_privilege), registered in GadgetKindRegistry.
 *
 * `viewablePrivilege()` is per (context, kind): the same kind can be members-only on the home page
 * but public on a profile/login page (OpenPNE 3's per-context viewable_privilege), so a guest must
 * not be shown a members-only home gadget nor have a public profile gadget hidden.
 *
 * `isAvailable()` reports whether the kind's dependency feature is present; an unavailable (or
 * unregistered) kind is hidden at render, the same way the navigation renderer hides unresolved uris.
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

    /**
     * Config parameters for a context, in form order.
     *
     * @return list<GadgetConfigField>
     */
    public function configFields(string $context): array
    {
        return [];
    }

    public function viewablePrivilege(string $context): int
    {
        return self::MEMBERS;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * The OpenPNE 3-compatible DOM id of this kind's rendered block, so a site's custom CSS keeps
     * matching. OpenPNE 3 used a kind-specific prefix + gadget id (e.g. `information_{id}`,
     * `friendList_{id}`), so each kind overrides this; null when OpenPNE 3 emitted no id (a kind that
     * renders a bare form, e.g. the login / language selector).
     */
    public function partId(int $gadgetId): ?string
    {
        return null;
    }
}
