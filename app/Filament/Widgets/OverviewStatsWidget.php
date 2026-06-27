<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Communities\CommunityResource;
use App\Filament\Resources\Diaries\DiaryResource;
use App\Filament\Resources\Members\MemberResource;
use App\Models\Community;
use App\Models\CommunityEvent;
use App\Models\CommunityTopic;
use App\Models\Diary;
use App\Models\Member;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

/**
 * SNS scale and activity at a glance. Message volume and any 1:1 communication metric are
 * deliberately omitted (OpenPNE's privacy stance). New registrations are shown modestly — one
 * monthly card with a prior-month delta — rather than framed as a target to grow.
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

        $newMembers = $this->countSince(Member::class, $monthStart);
        $newMembersDelta = $newMembers - $this->countSince(Member::class, $lastMonthStart, $monthStart);

        $diaries = $this->countSince(Diary::class, $monthStart);
        $diariesDelta = $diaries - $this->countSince(Diary::class, $lastMonthStart, $monthStart);

        return [
            Stat::make(__('Members'), number_format(Member::query()->count()))
                ->url(MemberResource::getUrl('index')),

            Stat::make(__('New members this month'), number_format($newMembers))
                ->description($this->delta($newMembersDelta))
                ->descriptionColor($newMembersDelta >= 0 ? 'success' : 'gray')
                ->url(MemberResource::getUrl('index')),

            Stat::make(__('Diaries this month'), number_format($diaries))
                ->description($this->delta($diariesDelta))
                ->descriptionColor($diariesDelta >= 0 ? 'success' : 'gray')
                ->url(DiaryResource::getUrl('index')),

            Stat::make(__('%Communities%'), number_format(Community::query()->count()))
                ->url(CommunityResource::getUrl('index')),

            Stat::make(__('Active %communities% (last 30 days)'), number_format($this->activeCommunities($activeSince)))
                ->description(__('Topics or events in the last 30 days'))
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

    /** Distinct communities with a topic or event posted since $since. */
    private function activeCommunities(CarbonInterface $since): int
    {
        return CommunityTopic::query()->where('created_at', '>=', $since)->distinct()->pluck('community_id')
            ->merge(CommunityEvent::query()->where('created_at', '>=', $since)->distinct()->pluck('community_id'))
            ->unique()
            ->count();
    }

    private function delta(int $diff): string
    {
        $formatted = ($diff > 0 ? '+' : '').number_format($diff);

        return $formatted.' '.__('vs last month');
    }
}
