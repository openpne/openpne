<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Models\LinkCard;
use App\Models\Member;
use App\Support\ViewerRelations;
use Illuminate\Database\Eloquent\Model;

/**
 * One query per kind: every row announces its pointer as it is hydrated ({@see LinkCard::booted()}),
 * so the first card needing a record reads every one the page will ask for. Scoped to the request
 * or job, never static; whether a reader may see it is asked every time, and the reader is passed
 * only so {@see ViewerRelations} can be warmed for the batch.
 */
final class InternalCardResolver
{
    /** @var array<string, Model|null> record by "kind:id", null for one that is gone */
    private array $records = [];

    /** @var array<string, list<int>> ids announced but not yet read, by kind */
    private array $pending = [];

    /**
     * Costs nothing on its own: a page whose cards are all refused by a switched-off unit reads no
     * record at all. An `internal_context` this app no longer has names no kind and is dropped here.
     */
    public function note(LinkCard $card): void
    {
        $target = $card->internal_context === null ? null : InternalCardTarget::tryFrom($card->internal_context);
        $id = $card->internal_record_id;

        if ($target === null || $id === null || array_key_exists($target->value.':'.$id, $this->records)) {
            return;
        }

        $this->pending[$target->value][] = $id;
    }

    /** The record $target names, or null when it is gone. */
    public function find(InternalCardTarget $target, int $id, ?Member $viewer): ?Model
    {
        $key = $target->value.':'.$id;

        // array_key_exists, not ??=: a record that is genuinely gone must be remembered as gone,
        // rather than re-read by every row that links to it.
        if (! array_key_exists($key, $this->records)) {
            $this->read($target, $id, $viewer);
        }

        return $this->records[$key];
    }

    /** Read $id together with everything else announced for its kind. */
    private function read(InternalCardTarget $target, int $id, ?Member $viewer): void
    {
        $ids = array_values(array_unique([...$this->pending[$target->value] ?? [], $id]));
        unset($this->pending[$target->value]);

        $records = $target->findMany($ids);

        foreach ($records as $record) {
            $this->records[$target->value.':'.$record->getKey()] = $record;
        }

        // Once the batch is known, and only for a signed-in reader: a guest has no relations to
        // anyone, and every rule that would ask about them refuses them first.
        if ($viewer !== null) {
            $target->warmRelations($records, $viewer);
        }

        // Whatever the read did not answer for is gone, and is remembered as gone.
        foreach ($ids as $asked) {
            $this->records[$target->value.':'.$asked] ??= null;
        }
    }
}
