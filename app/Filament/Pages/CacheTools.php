<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\GadgetService;
use App\Services\NavigationService;
use App\Services\SnsSettingService;
use App\Services\TermService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Artisan;

/**
 * One-click clear of the admin-managed caches (SNS settings, %term% overrides, navigation, gadgets)
 * plus compiled Blade views, for when an admin change isn't showing on the site. Deliberately narrow:
 * it never runs a blanket `cache:clear` (the app may share its cache store with sessions) and leaves
 * framework config/route caches to deploys.
 */
class CacheTools extends Page
{
    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedArrowPath;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('Cache');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Cache');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('clear')
                ->label(__('Clear caches'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->action(fn () => $this->clear()),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Clear caches'))
                ->description(__('Use this if an admin change is not showing on the site.'))
                ->schema([
                    Text::make(__('Clears cached SNS settings, terms, navigation, gadgets, and compiled views.')),
                ]),
        ]);
    }

    public function clear(): void
    {
        app(SnsSettingService::class)->clearCache();
        app(TermService::class)->clearCache();
        app(NavigationService::class)->clearCache();
        app(GadgetService::class)->clearCache();
        Artisan::call('view:clear');

        Notification::make()
            ->success()
            ->title(__('Caches cleared'))
            ->send();
    }
}
