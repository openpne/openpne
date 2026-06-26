<?php

namespace App\Filament\Resources\Gadgets;

use App\Filament\Resources\Gadgets\Pages\CreateGadget;
use App\Filament\Resources\Gadgets\Pages\EditGadget;
use App\Filament\Resources\Gadgets\Pages\ListGadgets;
use App\Filament\Resources\Gadgets\Schemas\GadgetForm;
use App\Filament\Resources\Gadgets\Tables\GadgetsTable;
use App\Gadgets\GadgetKindRegistry;
use App\Gadgets\GadgetLayout;
use App\Models\Gadget;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GadgetResource extends Resource
{
    protected static ?string $model = Gadget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('Gadget');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Gadgets');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Appearance (Classic)');
    }

    /** @return array<string, string> context => label, in display order. */
    public static function contextOptions(): array
    {
        return [
            'home' => __('Home page'),
            'profile' => __('Profile page'),
            'login' => __('Login page'),
            'sidebanner' => __('Side banner'),
        ];
    }

    /** @return array<string, string> zone => label, for the zones a context can hold. */
    public static function zoneOptions(string $context): array
    {
        $labels = [
            'top' => __('Top'),
            'sideMenu' => __('Side menu'),
            'contents' => __('Contents'),
            'bottom' => __('Bottom'),
        ];

        $options = [];
        foreach (GadgetLayout::contextZones($context) as $zone) {
            $options[$zone] = $labels[$zone] ?? $zone;
        }

        return $options;
    }

    /** @return array<string, string> kind name => label, for the kinds offered in a context. */
    public static function kindOptions(string $context): array
    {
        $options = [];
        foreach (GadgetKindRegistry::forContext($context) as $kind) {
            $options[$kind->name()] = $kind->label();
        }

        return $options;
    }

    public static function form(Schema $schema): Schema
    {
        return GadgetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return GadgetsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGadgets::route('/'),
            'create' => CreateGadget::route('/create'),
            'edit' => EditGadget::route('/{record}/edit'),
        ];
    }
}
