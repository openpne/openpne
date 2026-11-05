<?php

namespace App\Upgrade\Steps;

class WithdrawnMemberTermEnUpgrade extends WithdrawnMemberTermUpgrade
{
    public function locale(): string
    {
        return 'en';
    }
}
