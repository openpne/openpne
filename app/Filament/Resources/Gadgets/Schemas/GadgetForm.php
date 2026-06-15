<?php

namespace App\Filament\Resources\Gadgets\Schemas;

use App\Filament\Resources\Gadgets\GadgetResource;
use App\Gadgets\GadgetConfigField;
use App\Gadgets\GadgetKindRegistry;
use App\Models\Gadget;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Gadget placement + per-gadget config. Context and kind are fixed after creation (changing the kind
 * would orphan its config). The config inputs are generated from the kinds' own GadgetConfigField
 * definitions and each shown only for the selected kind, so the form never restates a kind's schema.
 * Config inputs are prefixed `config_`; the Create/Edit pages persist them to gadget_configs.
 */
class GadgetForm
{
    public const CONFIG_PREFIX = 'config_';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('context')
                    ->label(__('Placement'))
                    ->options(GadgetResource::contextOptions())
                    ->required()
                    ->live()
                    ->disabled(fn (string $operation): bool => $operation === 'edit'),

                Select::make('name')
                    ->label(__('Gadget'))
                    ->options(fn (Get $get): array => GadgetResource::kindOptions((string) $get('context')))
                    ->required()
                    ->live()
                    ->disabled(fn (string $operation): bool => $operation === 'edit'),

                Select::make('zone')
                    ->label(__('Zone'))
                    ->options(fn (Get $get): array => GadgetResource::zoneOptions((string) $get('context')))
                    ->required(),

                TextInput::make('sort_order')
                    ->label(__('Sort Order'))
                    ->numeric()
                    ->default(fn (string $operation): ?int => $operation === 'create'
                        ? (int) (Gadget::max('sort_order') ?? 0) + 10
                        : null)
                    ->nullable(),

                Section::make(__('Settings'))
                    ->visible(fn (Get $get): bool => self::selectedFields((string) $get('context'), (string) $get('name')) !== [])
                    ->components(self::configComponents()),
            ]);
    }

    /** One input per distinct config field across all kinds, each shown only for the selected kind. */
    private static function configComponents(): array
    {
        $components = [];
        foreach (self::superset() as $field) {
            $components[] = self::componentFor($field)
                ->visible(fn (Get $get): bool => in_array(
                    $field->name,
                    self::selectedFields((string) $get('context'), (string) $get('name')),
                    true,
                ));
        }

        return $components;
    }

    /**
     * The union of config field definitions across every kind/context, keyed by field name. A field
     * name has one definition everywhere it appears (e.g. `value` is always rich_textarea), so the
     * first wins.
     *
     * @return array<string, GadgetConfigField>
     */
    private static function superset(): array
    {
        $fields = [];
        foreach (GadgetKindRegistry::all() as $kind) {
            foreach ($kind->contexts() as $context) {
                foreach ($kind->configFields($context) as $field) {
                    $fields[$field->name] ??= $field;
                }
            }
        }

        return $fields;
    }

    /**
     * Config field names the selected (context, kind) defines.
     *
     * @return list<string>
     */
    private static function selectedFields(string $context, string $name): array
    {
        $kind = GadgetKindRegistry::find($name);
        if ($kind === null || $context === '') {
            return [];
        }

        return array_map(fn (GadgetConfigField $field): string => $field->name, $kind->configFields($context));
    }

    private static function componentFor(GadgetConfigField $field): Field
    {
        $key = self::CONFIG_PREFIX.$field->name;

        $component = match ($field->formType) {
            'select' => Select::make($key)->options($field->choices)->selectablePlaceholder(false),
            'radio' => Radio::make($key)->options($field->choices),
            'rich_textarea' => Textarea::make($key)->rows(6),
            default => TextInput::make($key),
        };

        $component->label($field->caption[app()->getLocale()] ?? $field->caption['en'] ?? $field->name);

        if ($field->required) {
            $component->required();
        }
        if ($field->default !== null) {
            $component->default($field->default);
        }

        return $component;
    }
}
