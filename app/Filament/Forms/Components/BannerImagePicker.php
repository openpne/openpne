<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

/** State is the list of selected banner_image ids, bound and validated like any multi-value Field. */
class BannerImagePicker extends Field
{
    protected string $view = 'filament.forms.components.banner-image-picker';
}
