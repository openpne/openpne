<?php

namespace App\Filament\Resources\BannerImages\Schemas;

use App\Filament\Resources\BannerImages\BannerImageResource;
use App\Models\BannerImage;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

/**
 * A banner image: the uploaded picture, its optional link and a label (the <img> alt). The upload is
 * kept as a temporary file (storeFiles(false)) and stored through App\Files\FileUploader by the
 * Create/Edit pages, not on a Filament disk. Which placements show the image is chosen on the Banner
 * page, not here.
 */
class BannerImageForm
{
    public static function configure(Schema $schema): Schema
    {
        $maxDimension = (int) config('openpne.images.max_upload_dimension', 5000);

        return $schema
            ->columns(1)
            ->components([
                // The FileUpload starts empty on edit (it never reloads the stored bytes), so show the
                // current image here; leaving the upload blank keeps it.
                Section::make(__('Current image'))
                    ->visibleOn('edit')
                    ->schema([
                        Image::make(
                            fn (?BannerImage $record): string => $record?->file !== null
                                ? route('banner.image', $record->file->name)
                                : '',
                            fn (?BannerImage $record): string => (string) ($record?->name ?? ''),
                        )
                            // Cap the preview at the image's own height (never upscale) and at 200px, with
                            // width left auto so banners keep their true (often long or wide) ratio.
                            ->imageHeight(function (?BannerImage $record): string {
                                $height = $record?->dimensions()[1] ?? null;

                                return ($height !== null ? min($height, 200) : 200).'px';
                            })
                            // Click to open the shared lightbox at full size.
                            ->extraAttributes(fn (?BannerImage $record): array => $record !== null
                                ? BannerImageResource::lightboxImageAttributes($record) + ['style' => 'cursor: zoom-in']
                                : []),

                        Text::make(fn (?BannerImage $record): string => $record?->dimensionsLabel() !== null
                            ? $record->dimensionsLabel().' px'
                            : '—'),
                    ]),

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
                    // OpenPNE 3 stored the link in a TEXT column with no length cap; only require a valid
                    // URL (a stored http(s) link, rendered escaped in an <a href>).
                    ->url(),

                TextInput::make('name')
                    ->label(__('Label'))
                    ->maxLength(64),
            ]);
    }
}
