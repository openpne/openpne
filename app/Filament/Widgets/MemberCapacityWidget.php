<?php

namespace App\Filament\Widgets;

use App\Features\AiAccount\AiAccountSettings;
use App\Features\Auth\CapacityState;
use App\Features\Auth\MemberCapacity;
use App\Support\SnsSettingKey;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Shown only on a site the host has capped; there is no admin control behind it, by design. */
class MemberCapacityWidget extends StatsOverviewWidget
{
    protected ?string $heading = null;

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return app(MemberCapacity::class)->limit() !== null || app(AiAccountSettings::class)->capacity() !== null;
    }

    protected function getStats(): array
    {
        $stats = [];

        $capacity = app(MemberCapacity::class);
        $limit = $capacity->limit();
        if ($limit !== null) {
            $state = $capacity->state();
            $stats[] = Stat::make(SnsSettingKey::MemberCapacity->label(), $capacity->members().' / '.$limit)
                ->description($this->description($state, $capacity))
                ->descriptionColor($this->color($state))
                ->color($this->color($state));
        }

        $ai = app(AiAccountSettings::class);
        $aiCapacity = $ai->capacity();
        if ($aiCapacity !== null) {
            $full = $ai->inUse() >= $aiCapacity;
            $stats[] = Stat::make(SnsSettingKey::AiAccountCapacity->label(), $ai->inUse().' / '.$aiCapacity)
                ->description($full ? __('At capacity') : self::left($aiCapacity - $ai->inUse()))
                ->color($full ? 'danger' : 'success');
        }

        return $stats;
    }

    private function description(CapacityState $state, MemberCapacity $capacity): string
    {
        $pending = $capacity->pending() === 1 ? __('1 invited') : __(':count invited', ['count' => $capacity->pending()]);

        return match ($state) {
            CapacityState::Over => __('Over capacity').' · '.$pending,
            CapacityState::Full => __('At capacity').' · '.$pending,
            default => self::left((int) $capacity->remaining()).' · '.$pending,
        };
    }

    private static function left(int $count): string
    {
        return $count === 1 ? __('1 left') : __(':count left', ['count' => $count]);
    }

    private function color(CapacityState $state): string
    {
        return match ($state) {
            CapacityState::Over, CapacityState::Full => 'danger',
            CapacityState::Low => 'warning',
            default => 'success',
        };
    }
}
