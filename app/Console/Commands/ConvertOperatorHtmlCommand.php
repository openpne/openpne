<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\OperatorContentConversion;
use Illuminate\Console\Command;

class ConvertOperatorHtmlCommand extends Command
{
    protected $signature = 'openpne:convert-operator-html
        {--force : Rewrite Markdown that is already stored, including Markdown an administrator edited}
        {--dry-run : List what would be converted and what each value would lose, without writing}';

    protected $description = 'Write Markdown versions of the stored operator HTML (page inserts, footer, banners, gadgets)';

    public function handle(OperatorContentConversion $conversion): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $items = $conversion->run(force: (bool) $this->option('force'), dryRun: $dryRun);

        foreach ($items as $item) {
            $this->line(sprintf('%s  %s', match (true) {
                $item->written => 'written',
                $item->selected => 'would write',
                default => 'kept',
            }, $item->location));

            foreach (['removed' => $item->converted->droppedElements, 'attributes dropped' => $item->converted->droppedAttributes] as $label => $counts) {
                if ($counts !== []) {
                    $this->line("    {$label}: ".implode(', ', array_map(static fn (string $name, int $count): string => "{$name} x{$count}", array_keys($counts), $counts)));
                }
            }
        }

        $written = count(array_filter($items, static fn ($item): bool => $item->written));
        $selected = count(array_filter($items, static fn ($item): bool => $item->selected));
        $this->info($dryRun ? "{$selected} of ".count($items).' operator HTML values would be written as Markdown; nothing written.' : "{$written} of ".count($items).' operator HTML values written as Markdown.');

        return self::SUCCESS;
    }
}
