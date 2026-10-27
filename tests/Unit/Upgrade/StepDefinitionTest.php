<?php

namespace Tests\Unit\Upgrade;

use App\Upgrade\Column;
use App\Upgrade\Steps\FriendRequestUpgrade;
use App\Upgrade\Steps\FriendshipUpgrade;
use App\Upgrade\UpgradeStep;
use LogicException;
use PHPUnit\Framework\TestCase;

class StepDefinitionTest extends TestCase
{
    public function test_a_definition_is_stable_and_tells_steps_apart(): void
    {
        $this->assertSame((new FriendshipUpgrade)->definition(), (new FriendshipUpgrade)->definition());
        $this->assertNotSame((new FriendshipUpgrade)->definition(), (new FriendRequestUpgrade)->definition());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (new FriendshipUpgrade)->definition());
    }

    public function test_a_changed_filter_changes_the_definition(): void
    {
        $narrowed = new class extends FriendshipUpgrade
        {
            public function filter(): ?string
            {
                return '(is_friend = 1 AND member_id_from < 10)';
            }
        };

        $this->assertNotSame((new FriendshipUpgrade)->definition(), $narrowed->definition());
    }

    public function test_a_pending_step_has_no_definition(): void
    {
        $pending = new class extends UpgradeStep
        {
            protected string $source = 'member_relationship';

            protected string $target = 'friendships';

            public function columns(): array
            {
                return ['id' => Column::source('id')];
            }

            public function pendingTargets(): array
            {
                return ['unresolved' => 'no source yet'];
            }
        };

        $this->expectException(LogicException::class);
        $pending->definition();
    }
}
