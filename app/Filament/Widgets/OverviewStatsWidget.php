<?php

namespace App\Filament\Widgets;

use App\Features\AiAccount\AiAccountSettings;
use App\Features\Auth\CapacityState;
use App\Features\Auth\MemberCapacity;
use App\Filament\Resources\Diaries\DiaryResource;
use App\Filament\Resources\Groups\GroupResource;
use App\Filament\Resources\Members\MemberResource;
use App\Models\Diary;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupTopic;
use App\Models\Member;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Message volume and any 1:1 communication metric are deliberately omitted (OpenPNE's privacy stance).
 * Members and AI accounts are counted apart, as the host's two caps are (docs/internals/capacity.md, "Dashboard").
 */
class OverviewStatsWidget extends StatsOverviewWidget
{
    protected ?string $heading = null;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $monthStart = now()->startOfMonth();
        $lastMonthStart = $monthStart->copy()->subMonthNoOverflow();
        $activeSince = now()->subDays(30);

        $newMembers = $this->countSince(self::humans(), $monthStart);
        $newMembersDelta = $newMembers - $this->countSince(self::humans(), $lastMonthStart, $monthStart);

        $diaries = $this->countSince(Diary::query(), $monthStart);
        $diariesDelta = $diaries - $this->countSince(Diary::query(), $lastMonthStart, $monthStart);

        return [
            $this->memberStat(),
            ...$this->aiAccountStat(),

            Stat::make(__('New members this month'), number_format($newMembers))
                ->description($this->delta($newMembersDelta))
                ->descriptionColor($newMembersDelta >= 0 ? 'success' : 'gray')
                ->url(MemberResource::getUrl('index')),

            Stat::make(__('%Diaries% this month'), number_format($diaries))
                ->description($this->delta($diariesDelta))
                ->descriptionColor($diariesDelta >= 0 ? 'success' : 'gray')
                ->url(DiaryResource::getUrl('index')),

            Stat::make(__('%Communities%'), number_format(Group::query()->count()))
                ->url(GroupResource::getUrl('index')),

            Stat::make(__('Active %communities% (last 30 days)'), number_format(self::activeGroupCount($activeSince)))
                ->description(__('%Topics%, events, or comments in the last 30 days'))
                ->color('success'),
        ];
    }

    /** @return Builder<Member> */
    private static function humans(): Builder
    {
        return Member::query()->whereNull('owner_member_id');
    }

    private function memberStat(): Stat
    {
        $capacity = app(MemberCapacity::class);
        $limit = $capacity->limit();
        $stat = Stat::make(__('Member count'), number_format($capacity->members()).($limit === null ? '' : ' / '.number_format($limit)))
            ->url(MemberResource::getUrl('index'));
        if ($limit === null) {
            return $stat;
        }

        $state = $capacity->state();
        $pending = $capacity->pending() === 1 ? __('1 invited') : __(':count invited', ['count' => $capacity->pending()]);
        $description = match ($state) {
            CapacityState::Over => __('Over capacity'),
            CapacityState::Full => __('At capacity'),
            default => self::left((int) $capacity->remaining()),
        };
        $color = match ($state) {
            CapacityState::Over, CapacityState::Full => 'danger',
            CapacityState::Low => 'warning',
            default => 'success',
        };

        return $stat->description($description.' · '.$pending)->descriptionColor($color)->color($color);
    }

    /** @return list<Stat> */
    private function aiAccountStat(): array
    {
        $ai = app(AiAccountSettings::class);
        $inUse = $ai->inUse();
        $capacity = $ai->capacity();
        if (! $ai->enabled() && $inUse === 0 && $capacity === null) {
            return [];
        }

        $stat = Stat::make(__('AI accounts'), number_format($inUse).($capacity === null ? '' : ' / '.number_format($capacity)));
        if ($capacity === null) {
            return [$stat];
        }
        $full = $inUse >= $capacity;

        return [$stat->description($full ? __('At capacity') : self::left($capacity - $inUse))
            ->descriptionColor($full ? 'danger' : 'success')
            ->color($full ? 'danger' : 'success')];
    }

    private static function left(int $count): string
    {
        return $count === 1 ? __('1 left') : __(':count left', ['count' => $count]);
    }

    /** @param  Builder<Model>  $query */
    private function countSince(Builder $query, CarbonInterface $from, ?CarbonInterface $to = null): int
    {
        $query = $query->where('created_at', '>=', $from);
        if ($to !== null) {
            $query->where('created_at', '<', $to);
        }

        return $query->count();
    }

    /**
     * Keyed on bumped_at, so a fresh comment on an old thread counts as activity and an edit does not.
     * Public and static so it is assertable without rendering the widget.
     */
    public static function activeGroupCount(CarbonInterface $since): int
    {
        return GroupTopic::query()->where('bumped_at', '>=', $since)->distinct()->pluck('group_id')
            ->merge(GroupEvent::query()->where('bumped_at', '>=', $since)->distinct()->pluck('group_id'))
            ->unique()
            ->count();
    }

    private function delta(int $diff): string
    {
        $formatted = ($diff > 0 ? '+' : '').number_format($diff);

        return $formatted.' '.__('vs last month');
    }
}
