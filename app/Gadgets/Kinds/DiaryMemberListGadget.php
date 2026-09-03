<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

/**
 * A profile owner's recently posted diaries (OpenPNE 3 diaryMemberList). Two OpenPNE 3 divergences:
 * the wrapper carries no DOM id (OpenPNE 3 emitted class-only .homeRecentList here), and the max
 * config is honored (the OpenPNE 3 component ignored it and always showed 5).
 */
class DiaryMemberListGadget extends DiaryListGadget
{
    public function name(): string
    {
        return 'diaryMemberList';
    }

    public function label(): string
    {
        return __('%Diary% Member List');
    }

    public function description(): string
    {
        return __("The member's recently posted %diaries%.");
    }

    public function component(): string
    {
        return 'gadget.diary-member-list';
    }

    public function contexts(): array
    {
        return ['profile'];
    }

    public function partId(int $gadgetId): ?string
    {
        return null;
    }
}
