<?php

namespace Tests\Unit\Upgrade\Diary;

use App\Features\Diary\Visibility;
use App\Upgrade\Diary\DiaryUpgradeMapper;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class DiaryVisibilityMappingTest extends TestCase
{
    public function test_sns_public_flag_maps_to_members(): void
    {
        $this->assertSame(Visibility::Members, DiaryUpgradeMapper::mapVisibility(1, false));
    }

    public function test_sns_public_flag_with_is_open_maps_to_open(): void
    {
        // OpenPNE 3 normalises web-public as public_flag=1 + is_open=1.
        $this->assertSame(Visibility::Open, DiaryUpgradeMapper::mapVisibility(1, true));
    }

    public function test_friend_public_flag_maps_to_friends(): void
    {
        $this->assertSame(Visibility::Friends, DiaryUpgradeMapper::mapVisibility(2, false));
    }

    public function test_private_public_flag_maps_to_private(): void
    {
        $this->assertSame(Visibility::Private, DiaryUpgradeMapper::mapVisibility(3, false));
    }

    public function test_legacy_open_public_flag_maps_to_open(): void
    {
        // Older data may carry PUBLIC_FLAG_OPEN=4 instead of the 1+is_open form.
        $this->assertSame(Visibility::Open, DiaryUpgradeMapper::mapVisibility(4, false));
    }

    public function test_is_open_does_not_promote_friend_or_private_to_open(): void
    {
        // is_open on a friend/private row is anomalous; the restrictive level wins.
        $this->assertSame(Visibility::Friends, DiaryUpgradeMapper::mapVisibility(2, true));
        $this->assertSame(Visibility::Private, DiaryUpgradeMapper::mapVisibility(3, true));
    }

    public function test_unknown_public_flag_throws_rather_than_widening_exposure(): void
    {
        $this->expectException(UnexpectedValueException::class);
        DiaryUpgradeMapper::mapVisibility(99, false);
    }
}
