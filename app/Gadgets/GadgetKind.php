<?php

declare(strict_types=1);

namespace App\Gadgets;

use App\Support\Feature;

/**
 * A gadget kind — what a `gadgets.name` value renders as (component, config schema,
 * viewable_privilege), registered in GadgetKindRegistry.
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

    /** Human label for the admin (add-gadget list, table): a `__()` key, so it translates. */
    abstract public function label(): string;

    /** A one-line "what this shows" for the admin Gadget picker; each kind overrides, empty by default. */
    public function description(): string
    {
        return '';
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

    /**
     * The feature unit whose toggle hides this kind at render; null when the kind depends on no
     * unit (it renders whatever the admin has switched off).
     */
    public function feature(): ?Feature
    {
        return null;
    }

    /**
     * A second unit this kind's content needs in a context, beyond the one that owns it: a kind
     * whose whole purpose is a lens another unit owns (a friends-only list) goes when that unit
     * goes. Per context, because a kind can be that lens in one context and not in another.
     */
    public function dependsOn(string $context): ?Feature
    {
        return null;
    }

    /** Whether every unit this kind needs here is on; an unavailable kind is hidden at render. */
    public function isAvailable(string $context): bool
    {
        foreach ([$this->feature(), $this->dependsOn($context)] as $required) {
            if ($required !== null && ! $required->enabled()) {
                return false;
            }
        }

        return true;
    }

    /** This kind's OpenPNE 3-compatible DOM id (the custom-CSS seam); null when OpenPNE 3 emitted none. */
    public function partId(int $gadgetId): ?string
    {
        return null;
    }
}
