<?php

declare(strict_types=1);

namespace App\Gadgets\Kinds;

/** Recently posted diaries across the whole SNS (OpenPNE 3 diaryList). */
class DiaryAllListGadget extends DiaryListGadget
{
    public function name(): string
    {
        return 'diaryList';
    }

    public function description(): string
    {
        return __('Recently posted %diaries% from everyone.');
    }

    public function component(): string
    {
        return 'gadget.diary-list';
    }
}
