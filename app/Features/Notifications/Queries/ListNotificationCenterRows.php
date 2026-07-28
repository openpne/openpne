<?php

declare(strict_types=1);

namespace App\Features\Notifications\Queries;

use App\Features\Notifications\NotificationCenterRow;
use App\Features\Notifications\Serializers\NotificationFeedSerializer;
use App\Models\Member;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The panel's rows: the member's most recent events, capped where OpenPNE 3 capped them. Read and
 * unread both appear — the panel is a log, and the skin greys the read ones out.
 */
class ListNotificationCenterRows
{
    public const LIMIT = 20;

    /** @return Collection<int, NotificationCenterRow> */
    public function __invoke(Member $viewer): Collection
    {
        /** @var Collection<int, DatabaseNotification> $rows */
        $rows = $viewer->notifications()->latest()->limit(self::LIMIT)->get();

        return NotificationFeedSerializer::centerRows($rows, $this->awaitingDecision($viewer, $rows));
    }

    /**
     * Which of the listed requesters are still waiting on this member, in one query.
     *
     * @param  Collection<int, DatabaseNotification>  $rows
     * @return array<int, bool>
     */
    private function awaitingDecision(Member $viewer, Collection $rows): array
    {
        $requesters = $rows
            ->filter(fn (DatabaseNotification $row): bool => ($row->data['kind'] ?? null) === 'friend_requested')
            ->map(fn (DatabaseNotification $row): ?int => $row->data['requester_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($requesters->isEmpty()) {
            return [];
        }

        return DB::table('friend_requests')
            ->where('target_id', $viewer->getKey())
            ->whereIn('requester_id', $requesters)
            ->pluck('requester_id')
            ->mapWithKeys(fn (int $id): array => [$id => true])
            ->all();
    }
}
