<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Support\ClassicScriptPolicy;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClassicScriptPolicyFormTargetTest extends TestCase
{
    #[DataProvider('targets')]
    public function test_a_form_target_becomes_a_source_expression(string $uri, string $expected): void
    {
        config(['openpne.security.classic_script_policy' => true]);
        $policy = new ClassicScriptPolicy;
        $policy->begin(Request::create('/oauth/authorize'));

        $policy->allowFormTarget($uri);

        $this->assertStringEndsWith('; form-action '.$expected, (string) $policy->header());
    }

    /** @return array<string, array{string, string}> */
    public static function targets(): array
    {
        return [
            'https origin, path and query dropped' => ['https://client.example/cb?x=1', "'self' https://client.example"],
            'loopback with a port' => ['http://127.0.0.1:53124/callback', "'self' http://127.0.0.1:53124"],
            'ipv6 loopback' => ['http://[::1]:8123/cb', "'self' http://[::1]:8123"],
            'private-use scheme' => ['com.example.app:/oauth', "'self' com.example.app:"],
            'a host that would break out of the directive is dropped' => ["https://a;script-src 'unsafe-inline'/", "'self'"],
            'no scheme' => ['/relative', "'self'"],
        ];
    }

    public function test_begin_forgets_the_previous_requests_targets(): void
    {
        config(['openpne.security.classic_script_policy' => true]);
        $policy = new ClassicScriptPolicy;
        $policy->begin(Request::create('/oauth/authorize'));
        $policy->allowFormTarget('https://client.example/cb');

        $policy->begin(Request::create('/login'));

        $this->assertStringEndsWith("; form-action 'self'", (string) $policy->header());
    }
}
