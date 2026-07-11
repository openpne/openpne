<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class SecurityLogTest extends TestCase
{
    use CapturesSecurityLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureSecurityLog();
    }

    private function contextOf(string $event, array $context): array
    {
        SecurityLog::event($event, $context);

        return $this->securityRecords($event)[0]['context'];
    }

    public function test_control_characters_and_newlines_become_spaces(): void
    {
        $context = $this->contextOf('e', ['v' => "line1\nline2\r\ttab\x00null"]);

        // \n, then \r and \t (two control chars → two spaces), then \x00 — each becomes one space.
        $this->assertSame('line1 line2  tab null', $context['v']);
    }

    public function test_values_are_truncated_to_256_characters(): void
    {
        $context = $this->contextOf('e', ['v' => str_repeat('x', 300)]);

        $this->assertSame(256, mb_strlen($context['v']));
    }

    public function test_non_string_values_are_cast_and_null_is_dropped(): void
    {
        $context = $this->contextOf('e', [
            'int' => 42,
            'true' => true,
            'false' => false,
            'gone' => null,
            'nested' => ['not' => 'stringable'],
        ]);

        $this->assertSame('42', $context['int']);
        $this->assertSame('true', $context['true']);
        $this->assertSame('false', $context['false']);
        $this->assertArrayNotHasKey('gone', $context);
        $this->assertArrayNotHasKey('nested', $context);
    }

    public function test_http_context_auto_attaches_ip_and_user_agent(): void
    {
        // A request with no CLI argv reads as HTTP even under the test runner's console SAPI.
        $this->app->instance('request', Request::create('/x', 'GET', server: [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_USER_AGENT' => 'Mozilla/Test',
        ]));

        $context = $this->contextOf('e', []);

        $this->assertSame('203.0.113.9', $context['ip']);
        $this->assertSame('Mozilla/Test', $context['user_agent']);
    }

    public function test_a_caller_supplied_network_field_is_not_overwritten(): void
    {
        $this->app->instance('request', Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']));

        $context = $this->contextOf('e', ['ip' => 'caller-set']);

        $this->assertSame('caller-set', $context['ip']);
    }

    public function test_console_context_attaches_no_network_fields_and_does_not_crash(): void
    {
        // A CLI-derived stub request (argv present) under the console SAPI must stamp nothing.
        $this->app->instance('request', Request::create('/', 'GET', server: ['argv' => ['artisan', 'x'], 'argc' => 2]));

        $context = $this->contextOf('e', ['member_id' => 7]);

        $this->assertSame(['member_id' => '7'], $context);
    }
}
