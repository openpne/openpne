<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\LinkCard\CardContext;
use App\Models\LinkCard;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * See docs/internals/link-cards.md, "Cleaning up".
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
     * The conditions are repeated inside the DELETE rather than trusted from the earlier SELECT,
     * because a card can be adopted between the two (docs/internals/link-cards.md, "Cleaning up").
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

        // Deleted explicitly rather than by cascade: the foreign key runs from the card to the File, so
        // the database would null the reference instead of removing the row.
        $image?->delete();

        return true;
    }

    /**
     * The tables come from CardContext and nothing else: a filter meant for serving one record would
     * read a row still holding a card as no reference at all.
     *
     * `unionAll`, not `union`: this only asks whether a row exists, and de-duplicating the matches
     * costs a temporary B-tree per card.
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
