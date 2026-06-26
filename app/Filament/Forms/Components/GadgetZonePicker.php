<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

/**
 * Clickable page-diagram picker for a gadget's zone: the operator clicks the area of the (selected
 * context's) page to place into, and each area shows the gadgets already there. It is a radio group (one
 * option per zone) rendered as the page diagram — state binding and validation behave like any Field; the
 * value is the zone key (e.g. "contents"). The view reads the sibling `context` via `$get` and the static
 * helpers on GadgetResource / GadgetService, so the field class itself stays thin.
 */
class GadgetZonePicker extends Field
{
    protected string $view = 'filament.forms.components.gadget-zone-picker';
}
