<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

/**
 * Grid picker for the images a banner placement shows: a card per pool image (thumbnail + checkbox +
 * label + dimensions), with the thumbnail/info opening the shared lightbox. State is the list of
 * selected banner_image ids — binding and validation behave like any multi-value Field. The view reads
 * the pool from BannerImageResource::pickerOptions(), so the field class itself stays thin.
 */
class BannerImagePicker extends Field
{
    protected string $view = 'filament.forms.components.banner-image-picker';
}
