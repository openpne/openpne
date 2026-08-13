<?php

namespace App\Filament\Resources\GroupCategories\Schemas;

use App\Models\GroupCategory;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class GroupCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        // Categories are a flat master. The parent_id column is deliberately not exposed —
        // nothing reads it, and an unrestricted parent select would let an admin create
        // self-parent / cyclic master data.
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(64),

                Toggle::make('is_allow_member_group')
                    ->label(__('Members can create %communities% in this category'))
                    ->default(true),

                TextInput::make('sort_order')
                    ->label(__('Sort Order'))
                    ->numeric()
                    ->default(fn (string $operation): ?int => $operation === 'create'
                        ? (int) (GroupCategory::max('sort_order') ?? 0) + 10
                        : null)
                    ->nullable(),
            ]);
    }
}
