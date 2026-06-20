<?php

namespace App\Filament\Resources\BannerImages\Schemas;

use App\Filament\Resources\BannerImages\BannerImageResource;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * A banner image: the uploaded picture, its optional link, a label (the <img> alt), and which
 * placements show it. The upload is kept as a temporary file (storeFiles(false)) and stored through
 * App\Files\FileUploader by the Create/Edit pages, not on a Filament disk; `placements` is a plain
 * field the pages sync to banner_use_images.
 */
class BannerImageForm
{
    public static function configure(Schema $schema): Schema
    {
        $maxDimension = (int) config('openpne.images.max_upload_dimension', 5000);

        return $schema->components([
            FileUpload::make('image')
                ->label(__('Image'))
                ->image()
                // Raster types only (no SVG), matching the avatar upload rules.
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                ->maxSize(5120)
                ->rules(["dimensions:max_width={$maxDimension},max_height={$maxDimension}"])
                ->storeFiles(false)
                ->required(fn (string $operation): bool => $operation === 'create')
                ->helperText(fn (string $operation): ?string => $operation === 'edit'
                    ? __('Upload a new image to replace the current one.')
                    : null),

            TextInput::make('url')
                ->label(__('Link URL'))
                ->url()
                ->maxLength(255),

            TextInput::make('name')
                ->label(__('Label'))
                ->maxLength(64),

            CheckboxList::make('placements')
                ->label(__('Placements'))
                ->options(BannerImageResource::placementOptions()),
        ]);
    }
}
