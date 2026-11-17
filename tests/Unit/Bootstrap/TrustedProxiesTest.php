<?php

namespace Tests\Unit\Bootstrap;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
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

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/openpne-trusted-proxies-'.getmypid();
        @mkdir($this->dir, 0777, true);
        putenv('OPENPNE_ENV_PATH='.$this->dir);
        $this->processEnvironment(null);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/.env');
        @unlink($this->dir.'/config.php');
        @rmdir($this->dir);
    }

    /** @return array<string, array{string, ?string, string, string}> */
    public static function settings(): array
    {
        return [
            'the named proxy is believed' => ['TRUSTED_PROXIES=10.0.0.1', null, '10.0.0.1', self::CLIENT],
            'another address is not' => ['TRUSTED_PROXIES=10.0.0.1', null, '10.0.0.2', '10.0.0.2'],
            'a list with a range' => ['TRUSTED_PROXIES="192.168.0.0/16, 10.0.0.1"', null, '192.168.7.7', self::CLIENT],
            'the second entry of a list' => ['TRUSTED_PROXIES="192.168.0.0/16, 10.0.0.1"', null, '10.0.0.1', self::CLIENT],
            'every caller' => ['TRUSTED_PROXIES=*', null, '198.51.100.7', self::CLIENT],
            'an empty value trusts none' => ['TRUSTED_PROXIES=', null, '10.0.0.1', '10.0.0.1'],
            'no line trusts none' => ['', null, '10.0.0.1', '10.0.0.1'],
            'the process environment alone' => ['', '10.0.0.1', '10.0.0.1', self::CLIENT],
            'a list in the process environment' => ['', '192.168.0.0/16, 10.0.0.1', '10.0.0.1', self::CLIENT],
            'the process environment comes before the file' => ['TRUSTED_PROXIES=10.0.0.1', '10.0.0.2', '10.0.0.1', '10.0.0.1'],
        ];
    }

    #[DataProvider('settings')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_setting_decides_whose_forwarded_address_is_believed(string $line, ?string $process, string $caller, string $expected): void
    {
        $this->envFile($line);
        $this->processEnvironment($process);

        $this->assertSame($expected, $this->send($this->app(), $caller)->ip());
    }

    /** A null setting lets the framework trust every caller under its own hosting's host names, and on Laravel Cloud under any. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_an_empty_setting_trusts_none_whatever_the_host_is(): void
    {
        $this->envFile('TRUSTED_PROXIES=');

        $request = $this->send($this->app(), '10.0.0.1', ['HTTP_HOST' => 'sns.on-forge.com']);

        $this->assertSame('10.0.0.1', $request->ip());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_a_trusted_proxy_sets_the_scheme_and_the_port_and_never_the_host(): void
    {
        $this->envFile('TRUSTED_PROXIES=10.0.0.1');

        $request = $this->send($this->app(), '10.0.0.1', [
            'HTTP_HOST' => 'sns.example.com',
            'HTTP_X_FORWARDED_HOST' => 'elsewhere.example.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_PORT' => '8443',
            'HTTP_X_FORWARDED_PREFIX' => '/elsewhere',
        ]);

        $this->assertTrue($request->isSecure());
        $this->assertSame(8443, $request->getPort());
        $this->assertSame('sns.example.com', $request->getHost());
        $this->assertSame('', $request->getBaseUrl());
    }

    /** A site may cache its configuration from a shell that lacks what its web server's environment has. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_process_environment_is_read_past_a_cached_configuration(): void
    {
        $this->envFile('');
        $building = $this->app();
        $building->make(ConsoleKernel::class)->bootstrap();
        $this->assertSame([], $building['config']->get('trustedproxy.proxies'));
        file_put_contents($this->dir.'/config.php', '<?php return '.var_export($building['config']->all(), true).';');
        putenv('APP_CONFIG_CACHE='.$this->dir.'/config.php');
        $_ENV['APP_CONFIG_CACHE'] = $_SERVER['APP_CONFIG_CACHE'] = $this->dir.'/config.php';
        $this->processEnvironment('10.0.0.1');

        $app = $this->app();
        $request = $this->send($app, '10.0.0.1');

        $this->assertTrue($app->configurationIsCached());
        $this->assertSame(self::CLIENT, $request->ip());
    }

    private function envFile(string $line): void
    {
        file_put_contents($this->dir.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n".$line."\n");
    }

    private function processEnvironment(?string $proxies): void
    {
        if ($proxies === null) {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);

            return;
        }

        putenv('TRUSTED_PROXIES='.$proxies);
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $proxies;
    }

    private function app(): Application
    {
        return require dirname(__DIR__, 3).'/bootstrap/app.php';
    }

    /** @param  array<string, string>  $server */
    private function send(Application $app, string $caller, array $server = []): Request
    {
        $request = Request::create('/up', 'GET', server: $server + ['REMOTE_ADDR' => $caller, 'HTTP_X_FORWARDED_FOR' => self::CLIENT]);

        $this->assertSame(200, $app->make(Kernel::class)->handle($request)->getStatusCode());

        return $request;
    }
}
