<?php

namespace Tests\Unit\Bootstrap;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Each case boots the application from a .env file and sends a request through the HTTP kernel: a
 * setting read before the file is loaded passes any test that sets it in the process environment.
 */
final class TrustedProxiesTest extends TestCase
{
    private const CLIENT = '203.0.113.9';

    /** @return array<string, array{string, string, string}> */
    public static function settings(): array
    {
        return [
            'the named proxy is believed' => ['TRUSTED_PROXIES=10.0.0.1', '10.0.0.1', self::CLIENT],
            'another address is not' => ['TRUSTED_PROXIES=10.0.0.1', '10.0.0.2', '10.0.0.2'],
            'a list with a range' => ['TRUSTED_PROXIES="192.168.0.0/16, 10.0.0.1"', '192.168.7.7', self::CLIENT],
            'the second entry of a list' => ['TRUSTED_PROXIES="192.168.0.0/16, 10.0.0.1"', '10.0.0.1', self::CLIENT],
            'every caller' => ['TRUSTED_PROXIES=*', '198.51.100.7', self::CLIENT],
            'an empty value trusts none' => ['TRUSTED_PROXIES=', '10.0.0.1', '10.0.0.1'],
            'no line trusts none' => ['', '10.0.0.1', '10.0.0.1'],
        ];
    }

    #[DataProvider('settings')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_env_file_decides_whose_forwarded_address_is_believed(string $line, string $caller, string $expected): void
    {
        $dir = sys_get_temp_dir().'/openpne-trusted-proxies-'.getmypid();
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n".$line."\n");
        putenv('OPENPNE_ENV_PATH='.$dir);
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

        try {
            $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
            $request = Request::create('/up', 'GET', server: ['REMOTE_ADDR' => $caller, 'HTTP_X_FORWARDED_FOR' => self::CLIENT]);

            $response = $app->make(Kernel::class)->handle($request);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame($expected, $request->ip());
        } finally {
            @unlink($dir.'/.env');
            @rmdir($dir);
        }
    }
}
