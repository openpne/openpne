<?php

declare(strict_types=1);

namespace App\Features\Home\Queries;

use App\Features\Home\Data\HomeIssueWindow;
use App\Features\Home\Data\PlannedItem;
use Illuminate\Support\Collection;

/**
 * One kind of story, as the publisher asks about it. The pin is what makes the pair a contract: an
 * operator's choice is held to the same "every member may read it" predicate the algorithm applies,
 * which only one shared builder can guarantee.
 */
interface StoryCandidates
{
    /** The morph alias this kind is stored under — taken from the model, never written twice. */
    public function alias(): string;

    /**
     * The window's best $limit, already ranked, already minus what the ledger has featured.
     *
     * @return Collection<int, PlannedItem>
     */
    public function __invoke(HomeIssueWindow $window, int $limit): Collection;

    /**
     * One row by id, or null if it is not something every member may read. Neither the window nor
     * the ledger applies — this is the pin's path, and who may read the thing is what a pin may not
     * override.
     */
    public function find(int $id): ?PlannedItem;
}
