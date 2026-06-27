<?php

namespace App\Filament\Resources\Files\Tables;

use App\Models\File;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Number;

class FilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label(__('Thumbnail'))
                    ->getStateUsing(fn (File $record): ?string => self::thumbnailUrl($record))
                    ->square()
                    ->imageSize(64),

                TextColumn::make('name')
                    ->label(__('File Name'))
                    ->searchable()
                    ->sortable()
                    ->limit(40),

                TextColumn::make('owner')
                    ->label(__('Owner'))
                    ->badge()
                    ->getStateUsing(fn (File $record): string => self::ownerLabel($record))
                    ->color(fn (File $record): string => $record->related_entity_type === null ? 'gray' : 'primary'),

                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->color(fn (?string $state): string => str_starts_with((string) $state, 'image/') ? 'success' : 'gray')
                    ->toggleable(),

                TextColumn::make('byte_size')
                    ->label(__('Filesize'))
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state))
                    ->sortable()
                    ->alignment('right'),

                TextColumn::make('original_filename')
                    ->label(__('Original Filename'))
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('related_entity_type')
                    ->label(__('Owner type'))
                    ->options(self::ownerTypeOptions()),

                TernaryFilter::make('image_only')
                    ->label(__('Image only'))
                    ->placeholder(__('All'))
                    ->trueLabel(__('Images'))
                    ->falseLabel(__('Non-images'))
                    ->queries(
                        true: fn (Builder $q) => $q->where('type', 'LIKE', 'image/%'),
                        false: fn (Builder $q) => $q->where(fn (Builder $q2) => $q2->whereNull('type')->orWhere('type', 'NOT LIKE', 'image/%')),
                        blank: fn (Builder $q) => $q,
                    ),
            ])
            ->recordActions([
                // Bytes are served by the admin-gated AdminFileController (download=1 forces an
                // attachment even for raster types). Opening in a new tab avoids leaving the list.
                Action::make('download')
                    ->label(__('Download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (File $record): string => route('admin.file.raw', ['file' => $record->name, 'download' => 1]))
                    ->openUrlInNewTab(),
                // Default DeleteAction: $record->delete() fires the FileObserver, which purges the
                // stored bytes; referencing *_image rows cascade and communities.file_id null on FK.
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** Inline byte URL for raster images that actually have stored bytes; null otherwise (no thumbnail). */
    private static function thumbnailUrl(File $record): ?string
    {
        if (! str_starts_with((string) $record->type, 'image/') || $record->byte_size <= 0) {
            return null;
        }

        return route('admin.file.raw', ['file' => $record->name]);
    }

    /** Human label for a file's owning entity (morph alias + id), or em dash when unlinked. */
    private static function ownerLabel(File $record): string
    {
        if ($record->related_entity_type === null) {
            return '—';
        }

        return $record->related_entity_type.' #'.$record->related_entity_id;
    }

    /** @return array<string, string> morph alias => alias, for the owner-type filter. */
    private static function ownerTypeOptions(): array
    {
        return collect(array_keys(Relation::morphMap()))
            ->mapWithKeys(fn (string $alias): array => [$alias => $alias])
            ->all();
    }
}
