<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Gadgets\GadgetLayout;
use Filament\Forms\Components\Field;

/**
 * Radio-card picker for a Classic gadget layout: each selectable layout (A/B/C) is a card with a zone
 * wireframe instead of a dropdown row. State binding and validation behave like any Field — the value
 * is the layout name (e.g. "layoutA").
 */
class GadgetLayoutPicker extends Field
{
    protected string $view = 'filament.forms.components.gadget-layout-picker';

    /** The layouts an admin may pick; sidebanner's layoutD is fixed, so it is not offered. */
    public const SELECTABLE = ['layoutA', 'layoutB', 'layoutC'];

    /** @return list<array{value: string, name: string, zones: string}> */
    public function getLayoutOptions(): array
    {
        return array_map(static fn (string $layout): array => [
            'value' => $layout,
            'name' => 'Layout '.GadgetLayout::letter($layout),
            'zones' => implode(', ', GadgetLayout::zones($layout)),
        ], self::SELECTABLE);
    }
}
