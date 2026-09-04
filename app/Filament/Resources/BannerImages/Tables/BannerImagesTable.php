<?php

namespace App\Filament\Resources\BannerImages\Tables;

use App\Features\Banner\Actions\DeleteBannerImage;
use App\Filament\Resources\BannerImages\BannerImageResource;
use App\Models\BannerImage;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BannerImagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Fixed height with auto width, not square, so long or wide banners keep their shape
                // instead of being cropped square.
                ImageColumn::make('image')
                    ->label(__('Image'))
                    ->getStateUsing(fn (BannerImage $record): ?string => $record->file !== null
                        ? route('banner.image', $record->file->name)
                        : null)
                    ->extraImgAttributes(fn (BannerImage $record): array => BannerImageResource::lightboxImageAttributes($record) + [
                        'style' => 'height:40px;width:auto;max-width:200px;object-fit:contain;cursor:zoom-in',
                    ]),

                TextColumn::make('name')
                    ->label(__('Label')),

                TextColumn::make('dimensions')
                    ->label(__('Dimensions'))
                    ->getStateUsing(fn (BannerImage $record): string => $record->dimensionsLabel() ?? '—')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('url')
                    ->label(__('Link'))
                    ->limit(40),

                TextColumn::make('placements')
                    ->label(__('Banner placements'))
                    ->badge()
                    ->getStateUsing(fn (BannerImage $record): array => $record->banners
                        ->map(fn ($banner): string => BannerImageResource::placementLabel($banner->name))
                        ->all()),
            ])
            ->recordActions([
                EditAction::make(),
                // Delete through the action so the File (and its bytes) are purged, not just the row.
                DeleteAction::make()
                    ->action(fn (BannerImage $record) => app(DeleteBannerImage::class)($record)),
            ])
            ->defaultSort('id');
    }
}
