<?php

namespace App\Upgrade\Steps;

class WithdrawnMemberTermJaUpgrade extends WithdrawnMemberTermUpgrade
{
    public function locale(): string
    {
        return 'ja';
    }
}
