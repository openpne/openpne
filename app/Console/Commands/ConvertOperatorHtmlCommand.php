<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\OperatorContentConversion;
use Illuminate\Console\Command;

class ConvertOperatorHtmlCommand extends Command
{
    protected $signature = 'openpne:convert-operator-html
        {--force : Rewrite Markdown that is already stored}
        {--dry-run : List what would be converted and what each value would lose, without writing}';

    protected $description = 'Write Markdown versions of the stored operator HTML (page inserts, footer, banners, gadgets)';

    public function handle(OperatorContentConversion $conversion): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $items = $conversion->run(force: (bool) $this->option('force'), dryRun: $dryRun);

        foreach ($items as $item) {
            $this->line(sprintf('%s  %s', $item->written ? 'written' : ($dryRun ? 'would write' : 'kept'), $item->location));

            foreach (['removed' => $item->converted->droppedElements, 'attributes dropped' => $item->converted->droppedAttributes] as $label => $counts) {
                if ($counts !== []) {
                    $this->line("    {$label}: ".implode(', ', array_map(static fn (string $name, int $count): string => "{$name} x{$count}", array_keys($counts), $counts)));
                }
            }
        }

        $written = count(array_filter($items, static fn ($item): bool => $item->written));
        $this->info($dryRun ? count($items).' operator HTML values found; nothing written.' : "{$written} of ".count($items).' operator HTML values written as Markdown.');

        return self::SUCCESS;
    }
}
