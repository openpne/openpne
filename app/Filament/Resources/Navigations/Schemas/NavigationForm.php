<?php

namespace App\Filament\Resources\Navigations\Schemas;

use App\Filament\Resources\Navigations\NavigationResource;
use App\Models\Navigation;
use App\Support\NavigationUri;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Navigation item form. `uri` is validated against the same allow-list the renderer uses
 * (App\Support\NavigationUri): a single-slash internal path or an http(s) URL. The `:id`
 * placeholder is render-time substitution and is only meaningful in the friend/community contexts,
 * so it is rejected elsewhere. Captions are virtual fields persisted to navigation_translations by
 * the Create/Edit pages (source_uri is not exposed — it is an upgrade/DOM-id concern).
 */
class NavigationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label(__('Type'))
                    ->options(NavigationResource::typeOptions())
                    ->required()
                    ->live(),

                TextInput::make('uri')
                    ->label(__('Link URL'))
                    ->required()
                    ->maxLength(2048)
                    ->helperText(__('An internal path starting with / (e.g. /member/search) or an http(s):// URL. In member/community items, :id is replaced with the member or community id.'))
                    ->rules([
                        fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                            $uri = (string) $value;
                            if (! NavigationUri::isRenderable($uri)) {
                                $fail(__('Enter an internal path starting with / or an http(s):// URL.'));

                                return;
                            }
                            if (str_contains($uri, ':id') && ! in_array($get('type'), ['friend', 'community'], true)) {
                                $fail(__('The :id placeholder is only allowed in member and community navigation.'));
                            }
                        },
                    ]),

                TextInput::make('caption_ja')
                    ->label(__('Caption (Japanese)'))
                    ->required()
                    ->maxLength(255),

                TextInput::make('caption_en')
                    ->label(__('Caption (English)'))
                    ->maxLength(255),

                TextInput::make('sort_order')
                    ->label(__('Sort Order'))
                    ->numeric()
                    ->default(fn (string $operation): ?int => $operation === 'create'
                        ? (int) (Navigation::max('sort_order') ?? 0) + 10
                        : null)
                    ->nullable(),
            ]);
    }
}
