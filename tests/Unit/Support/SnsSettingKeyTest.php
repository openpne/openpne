<?php

namespace Tests\Unit\Support;

use App\Support\SnsSettingKey;
use PHPUnit\Framework\TestCase;

class SnsSettingKeyTest extends TestCase
{
    public function test_web_public_age_decodes_fail_closed(): void
    {
        $key = SnsSettingKey::AllowWebPublicAge;

        // `true` widens exposure, so only an explicit '1' enables it; a malformed/empty/absent value
        // must stay off (the opposite direction from CaptchaEnabled's fail-closed-on).
        $this->assertTrue($key->decode('1'));
        $this->assertFalse($key->decode('0'));
        $this->assertFalse($key->decode(''));
        $this->assertFalse($key->decode('x'));
        $this->assertFalse($key->decode(null)); // absent → default
        $this->assertFalse($key->default());
    }

    public function test_web_public_age_upgrades_from_op3(): void
    {
        $key = SnsSettingKey::AllowWebPublicAge;

        $this->assertSame('is_allow_web_public_flag_age', $key->op3SourceName());
        $this->assertTrue($key->isMigratedFromOp3());
        $this->assertSame($key, SnsSettingKey::fromOp3SourceName('is_allow_web_public_flag_age'));
    }
}
