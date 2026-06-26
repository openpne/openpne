<?php

namespace App\Filament\Resources\Profiles\Schemas;

use App\Models\Profile;
use App\Services\CountryListService;
use App\Services\PresetProfileService;
use App\Support\Visibility;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Profile field-definition form. On create the admin either registers a preset (structure
 * locked from config/preset_profile.php) or builds a custom field; on edit a preset's structural
 * fields (name/form_type/value_type/value_regexp/is_unique) are read-only, matching OpenPNE 3's
 * opPresetProfileForm. Captions/info are virtual fields persisted to profile_translations by the
 * Create/Edit pages.
 */
class ProfileForm
{
    /** form_type values that carry a value_type (and so text validation). */
    private const TEXT_TYPES = ['input', 'textarea'];

    /** form_type values that require selectable options. */
    public const OPTION_TYPES = ['select', 'radio', 'checkbox'];

    /** form_type values that support min/max constraints. */
    private const MIN_MAX_TYPES = ['input', 'textarea', 'date'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Setup'))
                    ->visible(fn (string $operation): bool => $operation === 'create')
                    ->columns(2)
                    ->components([
                        Select::make('_creation_mode')
                            ->label(__('Creation mode'))
                            ->options([
                                'preset' => __('From preset'),
                                'custom' => __('Custom field'),
                            ])
                            ->default('preset')
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->live(),

                        Select::make('_preset_key')
                            ->label(__('Preset'))
                            ->options(fn (): array => app(PresetProfileService::class)->unregisteredOptions())
                            ->visible(fn (string $operation, Get $get): bool => $operation === 'create' && $get('_creation_mode') === 'preset')
                            ->required(fn (Get $get): bool => $get('_creation_mode') === 'preset')
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if ($state === null || $state === '') {
                                    return;
                                }
                                $def = app(PresetProfileService::class)->findByKey($state);
                                if ($def === null) {
                                    return;
                                }
                                $captionKey = $def['caption_key'] ?? $state;
                                $set('caption_ja', trans($captionKey, [], 'ja'));
                                $set('caption_en', trans($captionKey, [], 'en'));
                            }),
                    ]),

                Section::make(__('Caption & description'))
                    ->columns(2)
                    ->components([
                        TextInput::make('caption_ja')
                            ->label(__('Caption (Japanese)'))
                            ->required()
                            ->maxLength(255),

                        TextInput::make('caption_en')
                            ->label(__('Caption (English)'))
                            ->required()
                            ->maxLength(255),

                        Textarea::make('info_ja')
                            ->label(__('Description (Japanese)'))
                            ->maxLength(1000)
                            ->rows(3),

                        Textarea::make('info_en')
                            ->label(__('Description (English)'))
                            ->maxLength(1000)
                            ->rows(3),
                    ]),

                Section::make(__('Field structure'))
                    ->visible(fn (string $operation, Get $get): bool => self::structuralVisible($operation, $get))
                    ->columns(2)
                    ->components([
                        TextInput::make('name')
                            ->label(__('Field Name (ASCII)'))
                            ->required()
                            ->maxLength(64)
                            ->unique(table: 'profiles', column: 'name', ignoreRecord: true)
                            // OpenPNE 3 ProfileForm: a custom name is a word with at least one
                            // letter, and "op_preset_" is reserved for presets (it drives
                            // Profile::isPreset()). A preset's own name is locked, so skip it there.
                            ->rules(fn (string $operation, ?Model $record): array => self::isPresetEdit($operation, $record) ? [] : [
                                'regex:/^\w*[a-z]\w*$/i',
                                function (string $attribute, mixed $value, callable $fail): void {
                                    if (str_starts_with((string) $value, 'op_preset_')) {
                                        $fail(__('The field name cannot start with "op_preset_".'));
                                    }
                                },
                            ])
                            ->readOnly(fn (string $operation, ?Model $record): bool => self::isPresetEdit($operation, $record))
                            ->helperText(fn (string $operation, ?Model $record): ?string => self::presetHelperText($operation, $record)),

                        Select::make('form_type')
                            ->label(__('Form Type'))
                            ->required()
                            ->live()
                            ->options([
                                'input' => __('Text (input)'),
                                'textarea' => __('Paragraph text'),
                                'select' => __('Single choice (Dropdown)'),
                                'radio' => __('Single choice (Radio)'),
                                'checkbox' => __('Multiple choices (Checkbox)'),
                                'date' => __('Date'),
                                'country_select' => __('Country Select'),
                                'region_select' => __('Region Select'),
                            ])
                            ->disabled(fn (string $operation, ?Model $record): bool => self::isPresetEdit($operation, $record)),

                        Select::make('value_type')
                            ->label(__('Value Type'))
                            ->options(fn (Get $get): array => self::valueTypeOptions((string) $get('form_type')))
                            ->default('string')
                            ->live()
                            ->disabled(fn (string $operation, ?Model $record): bool => self::isPresetEdit($operation, $record))
                            ->visible(fn (Get $get): bool => in_array($get('form_type'), self::TEXT_TYPES) || $get('form_type') === 'region_select'),

                        TextInput::make('value_regexp')
                            ->label(__('Regexp Pattern'))
                            ->maxLength(255)
                            ->placeholder('/^\d{3}-\d{4}$/')
                            ->disabled(fn (string $operation, ?Model $record): bool => self::isPresetEdit($operation, $record))
                            ->visible(fn (Get $get): bool => $get('value_type') === 'regexp' && in_array($get('form_type'), self::TEXT_TYPES)),

                        TextInput::make('value_min')
                            ->label(fn (Get $get): string => $get('form_type') === 'date' ? __('Min Date') : __('Min Length'))
                            ->placeholder(fn (Get $get): ?string => $get('form_type') === 'date' ? 'YYYY-MM-DD' : null)
                            ->helperText(fn (Get $get): ?string => $get('form_type') === 'date'
                                ? __('Earliest date that can be entered (YYYY-MM-DD).')
                                : __('Minimum number of characters.'))
                            ->rules(fn (Get $get): array => self::minMaxRules($get('form_type')))
                            ->disabled(fn (string $operation, ?Model $record): bool => self::isPresetEdit($operation, $record))
                            ->visible(fn (Get $get): bool => in_array($get('form_type'), self::MIN_MAX_TYPES)),

                        TextInput::make('value_max')
                            ->label(fn (Get $get): string => $get('form_type') === 'date' ? __('Max Date') : __('Max Length'))
                            ->placeholder(fn (Get $get): ?string => $get('form_type') === 'date' ? 'YYYY-MM-DD' : null)
                            ->helperText(fn (Get $get): ?string => $get('form_type') === 'date'
                                ? __('Latest date that can be entered (YYYY-MM-DD).')
                                : __('Maximum number of characters.'))
                            ->rules(fn (Get $get): array => array_merge(
                                self::minMaxRules($get('form_type')),
                                self::minMaxConsistencyRules($get('value_min'), $get('form_type')),
                            ))
                            ->disabled(fn (string $operation, ?Model $record): bool => self::isPresetEdit($operation, $record))
                            ->visible(fn (Get $get): bool => in_array($get('form_type'), self::MIN_MAX_TYPES)),
                    ]),

                Section::make(__('Public range'))
                    ->columns(2)
                    ->components([
                        Toggle::make('is_public_web')
                            ->label(__('Public to Web'))
                            ->default(false),

                        Toggle::make('is_edit_public_flag')
                            ->label(__('Editable Public Flag'))
                            ->default(true),

                        Select::make('default_visibility')
                            ->label(__('Default visibility'))
                            ->helperText(__('Used as the forced visibility when "Editable Public Flag" is OFF; otherwise the initial value the member can change.'))
                            ->options(self::visibilityOptions())
                            ->default(Visibility::Members->value)
                            ->selectablePlaceholder(false)
                            ->required()
                            ->formatStateUsing(fn ($state): int => $state instanceof Visibility ? $state->value : (int) ($state ?? Visibility::Members->value))
                            ->dehydrateStateUsing(fn ($state): int => (int) $state)
                            ->columnSpanFull(),
                    ]),

                Section::make(__('Display targets'))
                    ->columns(3)
                    ->components([
                        Toggle::make('is_disp_regist')
                            ->label(__('Show on Registration'))
                            ->default(true),

                        Toggle::make('is_disp_config')
                            ->label(__('Show on Edit'))
                            ->default(true),

                        Toggle::make('is_disp_search')
                            ->label(__('Show on Search'))
                            ->default(true),
                    ]),

                Section::make(__('Constraints & sort order'))
                    ->columns(3)
                    ->components([
                        Toggle::make('is_required')
                            ->label(__('Required'))
                            ->default(false),

                        Toggle::make('is_unique')
                            ->label(__('Unique'))
                            ->default(false)
                            ->disabled(fn (string $operation, ?Model $record): bool => self::isPresetEdit($operation, $record))
                            ->visible(fn (string $operation, Get $get): bool => self::structuralVisible($operation, $get)),

                        TextInput::make('sort_order')
                            ->label(__('Sort Order'))
                            ->numeric()
                            ->default(fn (string $operation): ?int => $operation === 'create'
                                ? (int) (Profile::max('sort_order') ?? 0) + 10
                                : null)
                            ->nullable(),
                    ]),
            ]);
    }

    /**
     * Whether this is an edit of a preset field. OpenPNE 3 opPresetProfileForm unsets the
     * structural fields (name/form_type/value_type/value_regexp/is_unique/value_min/value_max);
     * here they are disabled instead so a preset's structure cannot be changed after the fact.
     */
    public static function isPresetEdit(string $operation, ?Model $record): bool
    {
        return $operation === 'edit' && $record instanceof Profile && $record->isPreset();
    }

    private static function presetHelperText(string $operation, ?Model $record): ?string
    {
        return self::isPresetEdit($operation, $record)
            ? __('Preset fields define their structure from preset_profile config and cannot be changed here.')
            : null;
    }

    /** @return array<int, string> */
    private static function visibilityOptions(): array
    {
        $options = [];
        foreach (Visibility::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /**
     * value_type options by form_type. region_select keys on a country code (or "string" for the
     * grouped all-countries list); text fields offer only the value types the member-facing edit
     * form actually enforces (plain string, or a regexp via value_regexp).
     *
     * @return array<string, string>
     */
    private static function valueTypeOptions(string $formType): array
    {
        if ($formType === 'region_select') {
            $countries = app(CountryListService::class)->getOptions();
            $regions = (array) config('regions', []);
            $available = array_filter(
                $countries,
                fn (string $name, string $code): bool => ! empty($regions[$code] ?? []),
                ARRAY_FILTER_USE_BOTH,
            );
            asort($available);

            return ['string' => __('All countries (grouped)')] + $available;
        }

        return [
            'string' => __('String'),
            'regexp' => __('Regexp'),
        ];
    }

    /**
     * Structural fields (name, form_type, value types, is_unique) are visible on edit (a preset
     * disables them separately) and, on create, only for a custom field — a preset injects them.
     */
    private static function structuralVisible(string $operation, Get $get): bool
    {
        if ($operation === 'edit') {
            return true;
        }

        return $get('_creation_mode') === 'custom';
    }

    /** @return array<int, string> */
    private static function minMaxRules(?string $formType): array
    {
        return match ($formType) {
            'date' => ['nullable', 'date_format:Y-m-d'],
            'input', 'textarea' => ['nullable', 'integer', 'min:0'],
            default => ['nullable'],
        };
    }

    /** @return array<int, callable> */
    private static function minMaxConsistencyRules(mixed $minValue, ?string $formType): array
    {
        if ($minValue === null || $minValue === '') {
            return [];
        }

        return [
            function (string $attribute, mixed $value, callable $fail) use ($minValue, $formType): void {
                if ($value === null || $value === '') {
                    return;
                }
                $isDate = $formType === 'date';

                $minOk = $isDate ? (bool) \DateTime::createFromFormat('Y-m-d', (string) $minValue) : ctype_digit((string) $minValue);
                $maxOk = $isDate ? (bool) \DateTime::createFromFormat('Y-m-d', (string) $value) : ctype_digit((string) $value);
                if (! $minOk || ! $maxOk) {
                    return;
                }

                $invalid = $isDate ? strcmp((string) $value, (string) $minValue) < 0 : ((int) $value < (int) $minValue);
                if ($invalid) {
                    $fail(__('The :attribute must be greater than or equal to the minimum value.'));
                }
            },
        ];
    }
}
