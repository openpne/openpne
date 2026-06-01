<?php

namespace Tests\Unit\Bootstrap;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Guards the env/storage path hook in bootstrap/app.php. Each case controls the
 * process environment in isolation and re-bootstraps the app, so it does not
 * extend Tests\TestCase (which would boot the framework first).
 */
final class EnvHookTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_paths_follow_environment_variables(): void
    {
        putenv('OPENPNE_ENV_PATH=/tmp/openpne-env-hook');
        putenv('LARAVEL_STORAGE_PATH=/tmp/openpne-storage-hook');

        $app = $this->bootstrapApp();

        $this->assertSame('/tmp/openpne-env-hook', $app->environmentPath());
        $this->assertSame('/tmp/openpne-storage-hook', $app->storagePath());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_paths_fall_back_to_in_project_defaults_when_unset(): void
    {
        putenv('OPENPNE_ENV_PATH');
        putenv('LARAVEL_STORAGE_PATH');

        $base = dirname(__DIR__, 3);
        $app = $this->bootstrapApp();

        $this->assertSame($base, $app->environmentPath());
        $this->assertSame($base.DIRECTORY_SEPARATOR.'storage', $app->storagePath());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_relocated_env_file_is_actually_loaded(): void
    {
        $dir = sys_get_temp_dir().'/openpne-env-hook-'.getmypid();
        @mkdir($dir, 0777, true);
        file_put_contents($dir.'/.env', "OPENPNE_ENV_HOOK_PROOF=relocated\n");
        putenv('OPENPNE_ENV_PATH='.$dir);

        try {
            $app = $this->bootstrapApp();
            (new LoadEnvironmentVariables)->bootstrap($app);

            $this->assertSame('relocated', env('OPENPNE_ENV_HOOK_PROOF'));
        } finally {
            @unlink($dir.'/.env');
            @rmdir($dir);
        }
    }

    private function bootstrapApp(): Application
    {
        return require dirname(__DIR__, 3).'/bootstrap/app.php';
    }
}
