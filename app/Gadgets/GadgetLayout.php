<?php

declare(strict_types=1);

namespace App\Gadgets;

use App\Support\SnsSettingKey;

/**
 * The SSoT for gadget placement: the ported PC contexts, the zones each layout exposes, and the
 * OpenPNE 3 `gadget.type` ↔ (context, zone) mapping the upgrade splits on. Selectable contexts
 * (home/profile/login) pick layoutA/B/C; sidebanner is a fixed single-zone global area.
 */
final class GadgetLayout
{
    /** layout name => ordered zones (OpenPNE 3 gadget_layout_config.yml). */
    public const LAYOUTS = [
        'layoutA' => ['top', 'sideMenu', 'contents', 'bottom'],
        'layoutB' => ['sideMenu', 'contents', 'bottom'],
        'layoutC' => ['contents', 'bottom'],
        'layoutD' => ['contents'],
    ];

    /**
     * Ported contexts: the OpenPNE 3 context key (for the type split), whether the admin picks a
     * layout, and the default/fixed layout.
     */
    public const CONTEXTS = [
        'home' => ['op3' => 'gadget', 'selectable' => true, 'default' => 'layoutA'],
        'profile' => ['op3' => 'profile', 'selectable' => true, 'default' => 'layoutA'],
        'login' => ['op3' => 'login', 'selectable' => true, 'default' => 'layoutA'],
        'sidebanner' => ['op3' => 'sideBanner', 'selectable' => false, 'default' => 'layoutD'],
    ];

    /** @return list<string> */
    public static function contexts(): array
    {
        return array_keys(self::CONTEXTS);
    }

    public static function isContext(string $context): bool
    {
        return isset(self::CONTEXTS[$context]);
    }

    public static function isSelectable(string $context): bool
    {
        return self::CONTEXTS[$context]['selectable'] ?? false;
    }

    /** Zones a layout exposes, in render order. Unknown layout falls back to the layoutA set. */
    public static function zones(string $layout): array
    {
        return self::LAYOUTS[$layout] ?? self::LAYOUTS['layoutA'];
    }

    /** The SnsSettingKey holding a selectable context's chosen layout, or null when fixed. */
    public static function layoutSettingKey(string $context): ?SnsSettingKey
    {
        return match ($context) {
            'home' => SnsSettingKey::GadgetHomeLayout,
            'profile' => SnsSettingKey::GadgetProfileLayout,
            'login' => SnsSettingKey::GadgetLoginLayout,
            default => null,
        };
    }

    public static function defaultLayout(string $context): string
    {
        return self::CONTEXTS[$context]['default'] ?? 'layoutA';
    }

    /**
     * OpenPNE 3 `gadget.type` => [context, zone] for every ported type, built by replaying the
     * type-naming rule (over each context's widest layout) so it cannot drift from it.
     *
     * @return array<string, array{context: string, zone: string}>
     */
    public static function op3TypeMap(): array
    {
        $map = [];
        foreach (self::CONTEXTS as $context => $meta) {
            $layout = $meta['selectable'] ? 'layoutA' : $meta['default'];
            foreach (self::zones($layout) as $zone) {
                $map[self::op3Type($context, $zone)] = ['context' => $context, 'zone' => $zone];
            }
        }

        return $map;
    }

    /**
     * The OpenPNE 3 type string for a (context, zone), replicating opUtilHelper::op_get_gadget_type:
     * the home ("gadget") context uses the bare zone, others camelize "{op3Key}_{zone}".
     */
    public static function op3Type(string $context, string $zone): string
    {
        $op3 = self::CONTEXTS[$context]['op3'];
        if ($op3 === 'gadget') {
            return $zone;
        }

        $camel = str_replace(' ', '', ucwords(str_replace('_', ' ', "{$op3}_{$zone}")));

        return lcfirst($camel);
    }
}
