<?php

namespace App\Filament\Resources\BannerImages\Tables;

use App\Features\Banner\Actions\DeleteBannerImage;
use App\Filament\Resources\BannerImages\BannerImageResource;
use App\Models\BannerImage;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BannerImagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Label')),

                TextColumn::make('url')
                    ->label(__('Link'))
                    ->limit(40),

                TextColumn::make('placements')
                    ->label(__('Placements'))
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
