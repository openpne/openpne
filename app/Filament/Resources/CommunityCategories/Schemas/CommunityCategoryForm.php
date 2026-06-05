<?php

namespace App\Filament\Resources\CommunityCategories\Schemas;

use App\Models\CommunityCategory;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CommunityCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        // Categories are a flat master in this phase. The parent_id column exists for a possible
        // future shallow hierarchy but is intentionally not exposed yet — an unrestricted parent
        // select would let an admin create self-parent / cyclic master data nothing reads.
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(64),

                Toggle::make('is_allow_member_community')
                    ->label(__('Members can create communities in this category'))
                    ->default(true),

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
