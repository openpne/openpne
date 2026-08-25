<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\LinkCard\CardContext;
use App\Models\LinkCard;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes link cards no body points at any more.
 *
 * Editing a post away from the URL it used to carry, or deleting the post, leaves its card behind:
 * cards are keyed by URL and shared, so nothing can drop one at the moment a single record stops
 * referencing it without checking every other record first. Doing that check inline would put a
 * count-and-delete race on the posting path — two records dropping the same URL at once could both
 * see zero references, and a record picking that URL up again at the same moment could have its
 * card deleted underneath it. So the sweep is deliberate and out of band.
 *
 * A card that does go takes its image with it — deleted explicitly, not by a cascade: the foreign key
 * runs from the card to the File, so the database would sooner null the reference than remove the
 * row. That deletion is the only way those bytes become collectable at all, since a File referenced
 * by a living card is by definition still in use.
 *
 * On the weekly schedule (routes/console.php) rather than an operator's tool: a deployment already
 * has to drive `schedule:run` for the daily token sweep, so this needs nothing new of it. Still
 * takes `--days` and `--dry-run` for a reclaim an operator wants sooner or wants to see first.
 */
class PruneLinkCardsCommand extends Command
{
    protected $signature = 'openpne:prune-link-cards
        {--days=7 : Only prune cards untouched for at least this many days}
        {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete link preview cards no post refers to any more';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $cutoff = CarbonImmutable::now()->subDays($days);

        $query = LinkCard::query()
            ->where('link_cards.updated_at', '<=', $cutoff)
            ->whereNotExists(fn ($sub) => $this->anyReference($sub));

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No unreferenced link cards to prune.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("{$count} unreferenced link card(s) would be pruned.");

            return self::SUCCESS;
        }

        $deleted = 0;

        $query->chunkById(200, function ($cards) use (&$deleted, $cutoff): void {
            foreach ($cards as $card) {
                $deleted += $this->deleteIfStillUnreferenced($card, $cutoff) ? 1 : 0;
            }
        });

        $this->info("Pruned {$deleted} unreferenced link card(s).");

        return self::SUCCESS;
    }

    /**
     * Delete $card, but only if nothing has claimed it since it was selected as a candidate.
     *
     * The conditions are repeated inside the DELETE rather than trusted from the earlier SELECT,
     * because a card can be adopted between the two: cards are keyed by URL, so a new post of a URL
     * that has been unreferenced for weeks picks up the *existing* row rather than making one. The
     * window between selecting candidates and deleting them is exactly when that can happen, and
     * `cardFor` does not touch `updated_at`, so the grace period does not cover it.
     *
     * Leaving it to the database to order the two writes is what makes both outcomes safe:
     *
     *  - the attach commits first — `NOT EXISTS` sees the reference and this deletes nothing;
     *  - the delete commits first — the attach's UPDATE names an id that no longer exists and fails
     *    on the foreign key, so its marker is never written and the next view retries it.
     *
     * Getting this wrong is not a lost card but a *permanently* lost one: the attach writes
     * `link_card_id` and `link_card_synced_at` together, so a delete landing between them leaves the
     * body marked examined with no card, which the read path reads as "this body has no link" and
     * never revisits.
     */
    private function deleteIfStillUnreferenced(LinkCard $card, CarbonImmutable $cutoff): bool
    {
        $image = $card->image;

        $deleted = LinkCard::query()
            ->whereKey($card->getKey())
            ->where('updated_at', '<=', $cutoff)
            ->whereNotExists(fn ($sub) => $this->anyReference($sub))
            ->delete();

        if ($deleted !== 1) {
            return false;
        }

        // Only now, and only for a row that really went: deleting the card is what makes its image
        // unreachable, and deleting the File takes its bytes and cached thumbnails (FileObserver).
        $image?->delete();

        return true;
    }

    /**
     * A subquery matching any body that still points at the card.
     *
     * Every table a card can be referenced from, taken from CardContext so that adding a kind cannot
     * leave this behind — but the *tables*, never that enum's queries: see CardContext::table().
     *
     * `unionAll`, not `union`: this only ever asks whether a row exists, and de-duplicating the
     * matches costs a temporary B-tree per card for an answer that cannot change.
     */
    private function anyReference($query): void
    {
        $tables = array_map(fn (CardContext $context): string => $context->table(), CardContext::cases());

        $query->select(DB::raw('1'))->from($tables[0])->whereColumn($tables[0].'.link_card_id', 'link_cards.id');

        foreach (array_slice($tables, 1) as $table) {
            $query->unionAll(
                DB::table($table)->select(DB::raw('1'))->whereColumn($table.'.link_card_id', 'link_cards.id'),
            );
        }
    }
}
