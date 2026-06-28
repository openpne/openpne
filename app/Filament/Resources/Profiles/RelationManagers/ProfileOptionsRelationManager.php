<?php

namespace App\Filament\Resources\Profiles\RelationManagers;

use App\Filament\Resources\Profiles\Schemas\ProfileForm;
use App\Models\ProfileOption;
use App\Services\PresetProfileService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Manages the selectable options of a custom select/radio/checkbox field. A preset's choices come
 * from config/preset_profile.php (usesValueColumnForChoice), not this table, so options are
 * editable only for a custom option-type field.
 */
class ProfileOptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'options';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Options');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('caption_ja')
                    ->label(__('Label (Japanese)'))
                    ->required()
                    ->maxLength(255),

                TextInput::make('caption_en')
                    ->label(__('Label (English)'))
                    ->maxLength(255),

                TextInput::make('sort_order')
                    ->label(__('Sort Order'))
                    ->numeric()
                    ->nullable(),
            ]);
    }

    public function table(Table $table): Table
    {
        $editable = $this->optionsAreEditable();

        return $table
            // When the field is no longer a custom option type, hide any leftover option rows so
            // the "not applicable" message shows cleanly. The rows stay in the table (the member
            // side ignores them for non-option types) and reappear if it becomes a custom option
            // field again.
            ->modifyQueryUsing(fn ($query) => $editable ? $query->with('translations') : $query->whereRaw('1 = 0'))
            ->columns([
                TextColumn::make('caption_ja')
                    ->label(__('Label (Japanese)'))
                    ->getStateUsing(fn (ProfileOption $record): string => $record->getLabel('ja_JP')),

                TextColumn::make('caption_en')
                    ->label(__('Label (English)'))
                    ->getStateUsing(fn (ProfileOption $record): string => $record->getLabel('en')),

                TextColumn::make('sort_order')
                    ->label(__('Sort Order'))
                    ->sortable(),
            ])
            ->headerActions($editable ? [
                CreateAction::make()
                    ->after(fn (ProfileOption $record, array $data) => $this->writeLabels($record, $data)),
            ] : [])
            ->recordActions($editable ? [
                EditAction::make()
                    ->fillForm(fn (ProfileOption $record): array => [
                        'caption_ja' => $record->getLabel('ja_JP'),
                        'caption_en' => $record->getLabel('en'),
                        'sort_order' => $record->sort_order,
                    ])
                    ->after(fn (ProfileOption $record, array $data) => $this->writeLabels($record, $data)),
                DeleteAction::make(),
            ] : [])
            ->emptyStateHeading($editable ? __('No options yet') : __('Options not applicable'))
            ->emptyStateDescription($this->emptyStateDescription($editable))
            ->reorderable('sort_order')
            ->defaultSort('sort_order');
    }

    /** @param array<string, mixed> $data */
    private function writeLabels(ProfileOption $record, array $data): void
    {
        if (($data['caption_ja'] ?? '') !== '') {
            $record->setLabel('ja_JP', $data['caption_ja']);
        }

        // English is optional; a blank value must remove any existing row, not leave a stale label.
        if (($data['caption_en'] ?? '') !== '') {
            $record->setLabel('en', $data['caption_en']);
        } else {
            $record->translations()->where('lang', 'en')->delete();
        }
    }

    private function optionsAreEditable(): bool
    {
        return in_array($this->ownerRecord->form_type, ProfileForm::OPTION_TYPES, true)
            && ! app(PresetProfileService::class)->usesValueColumnForChoice($this->ownerRecord);
    }

    private function emptyStateDescription(bool $editable): string
    {
        if ($editable) {
            return __('Add options for this select/radio/checkbox field.');
        }

        if (in_array($this->ownerRecord->form_type, ProfileForm::OPTION_TYPES, true)) {
            return __('This preset field takes its choices from the preset configuration.');
        }

        return __('Options are only used for select, radio, and checkbox field types.');
    }
}
