<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * Pins the logging config that the security trail depends on: a dedicated always-`info` daily
 * channel with its own retention, and a bounded (daily, not single) default app log.
 */
class SecurityLogChannelTest extends TestCase
{
    public function test_security_channel_is_a_daily_info_channel_with_its_own_retention(): void
    {
        $channel = config('logging.channels.security');

        $this->assertSame('daily', $channel['driver']);
        $this->assertSame('info', $channel['level']);
        $this->assertSame(90, $channel['days']);
        $this->assertSame(storage_path('logs/security.log'), $channel['path']);
        $this->assertTrue($channel['replace_placeholders']);
    }

    public function test_the_app_log_stack_falls_back_to_daily_not_single(): void
    {
        // Pin the config file's fallback independent of the ambient .env (which may pin LOG_STACK):
        // clear the key, re-evaluate the file, restore.
        $repository = Env::getRepository();
        $original = $repository->get('LOG_STACK');
        $repository->clear('LOG_STACK');

        try {
            $config = require base_path('config/logging.php');
            $this->assertSame(['daily'], $config['channels']['stack']['channels']);
        } finally {
            if ($original !== null) {
                $repository->set('LOG_STACK', $original);
            }
        }
    }
}
