<?php

declare(strict_types=1);

namespace App\LinkCard;

use App\Models\LinkCard;
use App\Models\Member;
use App\Support\ViewerRelations;
use Illuminate\Database\Eloquent\Model;

/**
 * The records this request's internal cards are built from, loaded once and in one query per kind.
 *
 * A card of one of this site's own pages is read from the record it names on every render, and a
 * page draws many: a conversation shows sixty rows and the poll behind it asks again every few
 * seconds. Read one at a time that is three queries per row, forever, on the app's most frequent
 * request.
 *
 * **The batch assembles itself, rather than being declared at each list.** Every page that draws
 * cards eager-loads them in one go, before anything is serialized, and each row passing through
 * hydration announces its pointer here ({@see LinkCard::booted()}). The first card that actually
 * needs a record therefore already knows every record the page will ask for, and fetches them
 * together. A list added later gets that without opting in — which is the point, since an opt-in
 * would be silently missing from exactly the list nobody remembered.
 *
 * Scoped to the request or the job (AppServiceProvider), never static: a worker serves many of both,
 * and a record cached across them would go on describing what it looked like at the start. Only the
 * record is held. Whether a *reader* may see it is asked every time — that answer is not the
 * record's, and the reader is not this object's to know.
 *
 * The reader is passed in for one instant, and only so that the relations the rules are about to ask
 * for can be read for the whole batch rather than per card ({@see ViewerRelations}). Nothing about
 * them is kept here, and no decision is taken with them.
 */
final class InternalCardResolver
{
    /** @var array<string, Model|null> record by "kind:id", null for one that is gone */
    private array $records = [];

    /** @var array<string, list<int>> ids announced but not yet read, by kind */
    private array $pending = [];

    /**
     * Announce that $card's target is likely to be asked for.
     *
     * Costs nothing on its own — a page whose cards are all refused by a switched-off unit reads no
     * record at all. An `internal_context` this app no longer has is dropped here rather than
     * carried: it names no kind, and the render refuses it anyway.
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
