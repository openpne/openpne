<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

/**
 * Single-value field whose state is the zone key. The view reads the sibling `context` field through
 * `$get`, so the schema must carry one.
 */
class GadgetZonePicker extends Field
{
    protected string $view = 'filament.forms.components.gadget-zone-picker';
}
