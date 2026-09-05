<?php

declare(strict_types=1);

namespace App\Features\Notifications\Queries;

use App\Features\Notifications\NotificationCenterRow;
use App\Features\Notifications\NotificationCenterWindow;
use App\Features\Notifications\Serializers\NotificationFeedSerializer;
use App\Models\Member;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read and unread both appear: the panel is a log, and the skin greys the read ones out. */
class ListNotificationCenterRows
{
    public function __construct(private readonly NotificationCenterWindow $window) {}

    /** @return Collection<int, NotificationCenterRow> */
    public function __invoke(Member $viewer): Collection
    {
        $rows = $this->window->for($viewer);

        return NotificationFeedSerializer::centerRows($rows, $this->awaitingDecision($viewer, $rows));
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $rows
     * @return array<int, bool>
     */
    private function awaitingDecision(Member $viewer, Collection $rows): array
    {
        $requesters = $rows
            ->filter(fn (DatabaseNotification $row): bool => ($row->data['kind'] ?? null) === 'friend_requested')
            ->map(fn (DatabaseNotification $row): ?int => self::requesterId($row))
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

    /**
     * Normalized rather than trusted: this id decides who a decision is taken against, so an unexpected
     * payload shape reads as "no requester" rather than reaching a lookup.
     */
    public static function requesterId(DatabaseNotification $row): ?int
    {
        $id = filter_var($row->data['requester_id'] ?? null, FILTER_VALIDATE_INT);

        return is_int($id) && $id > 0 ? $id : null;
    }
}
