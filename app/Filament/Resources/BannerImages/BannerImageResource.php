<?php

namespace App\Filament\Resources\BannerImages;

use App\Filament\Concerns\HiddenWhenModernOnly;
use App\Filament\Resources\BannerImages\Pages\CreateBannerImage;
use App\Filament\Resources\BannerImages\Pages\EditBannerImage;
use App\Filament\Resources\BannerImages\Pages\ListBannerImages;
use App\Filament\Resources\BannerImages\Schemas\BannerImageForm;
use App\Filament\Resources\BannerImages\Tables\BannerImagesTable;
use App\Models\Banner;
use App\Models\BannerImage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class BannerImageResource extends Resource
{
    use HiddenWhenModernOnly;

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

    /** @return array<int, string> banner id => human label, in placement order. */
    public static function placementOptions(): array
    {
        return Banner::query()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Banner $banner): array => [$banner->getKey() => self::placementLabel($banner->name)])
            ->all();
    }

    public static function placementLabel(string $name): string
    {
        return match ($name) {
            'top_before' => __('Top banner (before login)'),
            'top_after' => __('Top banner (after login)'),
            default => $name,
        };
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
