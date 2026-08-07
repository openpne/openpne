<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
 * Deleting the row takes its image with it (the File cascade), which is the only way those bytes are
 * reachable for collection: while a card exists, its image is referenced.
 *
 * Not scheduled. A site under the fleet model runs no per-site cron, and an unreferenced card is
 * cache, not garbage that hurts — so this is an operator's tool, run when storage says it is worth
 * running.
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

        // Deleted through the model so each row's image File is deleted with it, taking its stored
        // bytes and cached thumbnails (FileObserver). A mass query delete would leave those behind.
        $deleted = 0;

        $query->chunkById(200, function ($cards) use (&$deleted): void {
            foreach ($cards as $card) {
                $image = $card->image;
                $card->delete();
                $image?->delete();
                $deleted++;
            }
        });

        $this->info("Pruned {$deleted} unreferenced link card(s).");

        return self::SUCCESS;
    }

    /**
     * A subquery matching any body that still points at the card.
     *
     * The grace period above is what makes this safe against the obvious race: a card created a
     * moment ago, whose owning record has not been written yet, is younger than the cutoff and so
     * never considered.
     */
    private function anyReference($query): void
    {
        $tables = ['diaries', 'community_topics', 'community_events', 'timeline_posts'];

        $query->select(DB::raw('1'))->from($tables[0])->whereColumn($tables[0].'.link_card_id', 'link_cards.id');

        foreach (array_slice($tables, 1) as $table) {
            $query->union(
                DB::table($table)->select(DB::raw('1'))->whereColumn($table.'.link_card_id', 'link_cards.id'),
            );
        }
    }
}
