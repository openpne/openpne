<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationGroup;
use Filament\Resources\RelationManagers\RelationManagerConfiguration;
use ReflectionClass;
use Tests\TestCase;

/**
 * Wherever a relation manager sets no model label, Filament humanises the model class name into the
 * empty state and every action modal ("group topic comment 削除"), a string no i18n gate sees because
 * it never goes through __(). The label is set on the table: the static property and getModelLabel()
 * are deprecated in Filament.
 */
class RelationManagerLabelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    public function test_every_registered_relation_manager_sets_a_model_label_on_its_table(): void
    {
        $managers = [];

        foreach (Filament::getCurrentPanel()->getResources() as $resource) {
            foreach ($resource::getRelations() as $registration) {
                foreach ($registration instanceof RelationGroup ? $registration->getManagers() : [$registration] as $manager) {
                    $managers[] = $manager instanceof RelationManagerConfiguration ? $manager->relationManager : $manager;
                }
            }
        }

        $managers = array_values(array_unique($managers));
        $this->assertNotEmpty($managers, 'The admin panel registers relation managers to check.');

        $missing = [];

        foreach ($managers as $manager) {
            if (! $this->setsModelLabel((string) (new ReflectionClass($manager))->getFileName())) {
                $missing[] = $manager;
            }
        }

        $this->assertSame([], $missing, 'These relation managers do not call ->modelLabel(...) with a value in table(), so Filament would show the humanised class name in the empty state and the action modals.');
    }

    /** Comments are dropped first, so a comment naming the method cannot stand in for the call. */
    private function setsModelLabel(string $file): bool
    {
        $tokens = array_values(array_filter(
            token_get_all((string) file_get_contents($file)),
            fn ($token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'modelLabel') {
                continue;
            }
            $previous = $tokens[$index - 1] ?? null;
            $argument = $tokens[$index + 2] ?? null;
            if (! is_array($previous) || $previous[0] !== T_OBJECT_OPERATOR || ($tokens[$index + 1] ?? null) !== '(') {
                continue;
            }
            if (is_array($argument) && $argument[0] === T_STRING && strtolower($argument[1]) === 'null') {
                continue;
            }

            return true;
        }

        return false;
    }
}
