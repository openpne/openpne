<?php

namespace App\Filament\Resources\BannerImages;

use App\Filament\Resources\BannerImages\Pages\CreateBannerImage;
use App\Filament\Resources\BannerImages\Pages\EditBannerImage;
use App\Filament\Resources\BannerImages\Pages\ListBannerImages;
use App\Filament\Resources\BannerImages\Schemas\BannerImageForm;
use App\Filament\Resources\BannerImages\Tables\BannerImagesTable;
use App\Models\BannerImage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BannerImageResource extends Resource
{
    protected static ?string $model = BannerImage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return __('Banner image');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Banner images');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Appearance (Classic)');
    }

    public static function placementLabel(string $name): string
    {
        return match ($name) {
            'top_before' => __('Top banner (before login)'),
            'top_after' => __('Top banner (after login)'),
            default => $name,
        };
    }

    /**
     * The shared image pool for the Banner page picker grid.
     *
     * @return list<array{id: int, title: string, thumb: string, dims: string, linkUrl: string}>
     */
    public static function pickerOptions(): array
    {
        return BannerImage::with('file')
            ->orderBy('id')
            ->get()
            ->map(fn (BannerImage $image): array => [
                'id' => $image->getKey(),
                'title' => (string) ($image->name ?? ''),
                'thumb' => $image->file !== null ? route('banner.image', $image->file->name) : '',
                'dims' => $image->dimensionsLabel() !== null ? $image->dimensionsLabel().' px' : '',
                'linkUrl' => (string) ($image->url ?? ''),
            ])
            ->all();
    }

    /**
     * The Alpine click expression that opens the shared lightbox from an element carrying the data-lb-*
     * attributes below. Kept free of double quotes (data lives in data-* attributes, not a JSON literal)
     * so it survives Filament/Livewire attribute escaping intact.
     */
    public const LIGHTBOX_CLICK = '$dispatch(\'open-image-lightbox\', { src: $el.dataset.lbSrc, title: $el.dataset.lbTitle, dims: $el.dataset.lbDims, linkUrl: $el.dataset.lbUrl })';

    /**
     * HTML attributes that make an <img> open the shared lightbox on click (used on the list thumbnail
     * and the edit preview). The caller supplies sizing; this adds the behaviour. Empty when there are
     * no bytes to show.
     *
     * @return array<string, string>
     */
    public static function lightboxImageAttributes(BannerImage $image): array
    {
        if ($image->file === null) {
            return [];
        }

        return [
            'x-data' => '{}',
            'data-lb-src' => route('banner.image', $image->file->name),
            'data-lb-title' => (string) ($image->name ?? ''),
            'data-lb-dims' => $image->dimensionsLabel() !== null ? $image->dimensionsLabel().' px' : '',
            'data-lb-url' => (string) ($image->url ?? ''),
            // .stop.prevent: the list row links to the edit page, so don't also navigate on a thumbnail click.
            'x-on:click.stop.prevent' => self::LIGHTBOX_CLICK,
            'title' => __('Enlarge'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return BannerImageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BannerImagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBannerImages::route('/'),
            'create' => CreateBannerImage::route('/create'),
            'edit' => EditBannerImage::route('/{record}/edit'),
        ];
    }
}
