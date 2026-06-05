<?php

namespace App\Filament\Resources\CommunityCategories\Schemas;

use App\Models\CommunityCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CommunityCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(64),

                Toggle::make('is_allow_member_community')
                    ->label(__('Members can create communities in this category'))
                    ->default(true),

                Select::make('parent_id')
                    ->label(__('Parent category'))
                    ->relationship('parent', 'name')
                    ->searchable()
                    ->nullable(),

                TextInput::make('sort_order')
                    ->label(__('Sort Order'))
                    ->numeric()
                    ->default(fn (string $operation): ?int => $operation === 'create'
                        ? (int) (CommunityCategory::max('sort_order') ?? 0) + 10
                        : null)
                    ->nullable(),
            ]);
    }
}
