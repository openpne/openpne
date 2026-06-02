<?php

namespace App\Filament\Resources\Profiles\Tables;

use App\Models\Profile;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProfilesTable
{
    /** Laravel UI locale → profile_translations.lang code. */
    private const LANG_MAP = ['ja' => 'ja_JP', 'en' => 'en'];

    public static function configure(Table $table): Table
    {
        $lang = self::LANG_MAP[app()->getLocale()] ?? 'ja_JP';

        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['translations' => fn ($q) => $q->where('lang', $lang)]))
            ->columns([
                TextColumn::make('sort_order')
                    ->label(__('Order'))
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('Field Name'))
                    ->searchable(),

                TextColumn::make('caption')
                    ->label(__('Caption'))
                    ->getStateUsing(fn (Profile $record): string => $record->getCaption($lang))
                    ->searchable(query: fn ($query, $search) => $query->whereHas(
                        'translations',
                        fn ($q) => $q->where('lang', $lang)->where('caption', 'like', "%{$search}%")
                    )),

                TextColumn::make('form_type')
                    ->label(__('Form Type'))
                    ->badge(),

                TextColumn::make('preset_badge')
                    ->label(__('Type'))
                    ->getStateUsing(fn (Profile $record): string => $record->isPreset() ? __('Preset') : __('Custom'))
                    ->badge()
                    ->color(fn (string $state): string => $state === __('Preset') ? 'info' : 'gray'),

                IconColumn::make('is_required')
                    ->label(__('Required'))
                    ->boolean(),

                IconColumn::make('is_disp_regist')
                    ->label(__('Show on Registration'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_disp_config')
                    ->label(__('Show on Edit'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_disp_search')
                    ->label(__('Show on Search'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_public_web')
                    ->label(__('Public to Web'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_unique')
                    ->label(__('Unique'))
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_required')->label(__('Required')),
                TernaryFilter::make('is_disp_regist')->label(__('Show on Registration')),
                TernaryFilter::make('is_disp_config')->label(__('Show on Edit')),
                TernaryFilter::make('is_disp_search')->label(__('Show on Search')),
            ])
            ->recordActions([
                EditAction::make(),
                // A preset is deletable too; deleting it returns it to the "register preset" picker.
                DeleteAction::make(),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order');
    }
}
