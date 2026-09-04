<?php

namespace App\Filament\Widgets;

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
use Illuminate\Database\Eloquent\Model;

/** Message volume and any 1:1 communication metric are deliberately omitted (OpenPNE's privacy stance). */
class OverviewStatsWidget extends StatsOverviewWidget
{
    protected ?string $heading = null;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $monthStart = now()->startOfMonth();
        $lastMonthStart = $monthStart->copy()->subMonthNoOverflow();
        $activeSince = now()->subDays(30);

        $newMembers = $this->countSince(Member::class, $monthStart);
        $newMembersDelta = $newMembers - $this->countSince(Member::class, $lastMonthStart, $monthStart);

        $diaries = $this->countSince(Diary::class, $monthStart);
        $diariesDelta = $diaries - $this->countSince(Diary::class, $lastMonthStart, $monthStart);

        return [
            Stat::make(__('Member count'), number_format(Member::query()->count()))
                ->url(MemberResource::getUrl('index')),

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

    /** @param  class-string<Model>  $model */
    private function countSince(string $model, CarbonInterface $from, ?CarbonInterface $to = null): int
    {
        $query = $model::query()->where('created_at', '>=', $from);
        if ($to !== null) {
            $query->where('created_at', '<', $to);
        }

        return $query->count();
    }

    /**
     * Keyed on updated_at, which a new comment bumps on its parent topic or event, so a fresh comment
     * on an old thread counts as activity. Public and static so it is assertable without rendering the
     * widget.
     */
    public static function activeGroupCount(CarbonInterface $since): int
    {
        return GroupTopic::query()->where('updated_at', '>=', $since)->distinct()->pluck('group_id')
            ->merge(GroupEvent::query()->where('updated_at', '>=', $since)->distinct()->pluck('group_id'))
            ->unique()
            ->count();
    }

    private function delta(int $diff): string
    {
        $formatted = ($diff > 0 ? '+' : '').number_format($diff);

        return $formatted.' '.__('vs last month');
    }
}
