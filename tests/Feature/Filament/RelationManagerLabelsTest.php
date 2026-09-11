<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * A relation manager with neither an empty-state heading nor a model label lets Filament humanise
 * the model class name into an untranslated label, which no i18n gate sees because it never goes
 * through __().
 */
class RelationManagerLabelsTest extends TestCase
{
    public function test_every_relation_manager_names_its_empty_state_or_model(): void
    {
        $finder = (new Finder)
            ->files()
            ->in(app_path('Filament'))
            ->name('*.php')
            ->contains('extends RelationManager');

        $missing = [];
        $seen = 0;

        foreach ($finder as $file) {
            $seen++;
            $source = $file->getContents();

            $explicit = str_contains($source, '->emptyStateHeading(')
                || str_contains($source, '->modelLabel(')
                || str_contains($source, 'function getModelLabel(')
                || preg_match('/static \?string \$modelLabel = [^n]/', $source) === 1;

            if (! $explicit) {
                $missing[] = str_replace(base_path().'/', '', $file->getRealPath());
            }
        }

        $this->assertGreaterThan(0, $seen, 'The admin panel has relation managers to check.');
        $this->assertSame([], $missing, 'These relation managers set neither ->emptyStateHeading() nor a model label, so Filament would show the humanised class name untranslated.');
    }
}
