<?php

namespace App\Filament\Resources\Communities\Schemas;

use App\Features\Community\JoinPolicy;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Models\CommunityCategory;
use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CommunityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(64)
                    ->unique(table: 'communities', column: 'name', ignoreRecord: true),

                Textarea::make('description')
                    ->label(__('Description'))
                    ->rows(4),

                // Categories are a flat master in OpenPNE 4 (no parent/child), so every category is
                // assignable — list them all (the prototype's child-only filter does not apply here).
                Select::make('community_category_id')
                    ->label(__('Category'))
                    ->options(fn (): array => CommunityCategory::query()
                        ->orderBy('sort_order')
                        ->orderBy('id')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable(),

                Radio::make('register_policy')
                    ->label(__('Join policy'))
                    ->options(self::enumOptions(JoinPolicy::cases()))
                    ->formatStateUsing(self::enumValue(...))
                    ->required(),

                Radio::make('topic_read_access')
                    ->label(__('Topic read access'))
                    ->options(self::enumOptions(TopicReadAccess::cases()))
                    ->formatStateUsing(self::enumValue(...))
                    ->required(),

                Radio::make('topic_post_authority')
                    ->label(__('Topic post authority'))
                    ->options(self::enumOptions(TopicPostAuthority::cases()))
                    ->formatStateUsing(self::enumValue(...))
                    ->required(),
            ]);
    }

    /**
     * @param  array<int, BackedEnum>  $cases
     * @return array<int|string, string>
     */
    private static function enumOptions(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            // @phpstan-ignore-next-line method.notFound — each content enum defines label()
            $options[$case->value] = __($case->label());
        }

        return $options;
    }

    /** Normalize the model's cast enum to its scalar value so the Radio matches an option key. */
    private static function enumValue(mixed $state): mixed
    {
        return $state instanceof BackedEnum ? $state->value : $state;
    }
}
