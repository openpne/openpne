<?php

declare(strict_types=1);

namespace Tests\Feature\AdminPanel;

use App\Auth\AdminPasswords;
use App\Models\AdminPasswordSetupToken;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Tests\Concerns\CapturesSecurityLog;
use Tests\TestCase;

class AdminPasswordLinkCommandTest extends TestCase
{
    use CapturesSecurityLog;
    use RefreshDatabase;

    /**
     * $this->artisan() folds stderr into stdout, so the streams are kept apart here.
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runCommand(string $command, array $parameters = []): array
    {
        $output = new class(fopen('php://memory', 'w+')) extends StreamOutput implements ConsoleOutputInterface
        {
            private OutputInterface $stderr;

            public function __construct($stream)
            {
                parent::__construct($stream);
                $this->stderr = new StreamOutput(fopen('php://memory', 'w+'));
            }

            public function getErrorOutput(): OutputInterface
            {
                return $this->stderr;
            }

            public function setErrorOutput(OutputInterface $error): void
            {
                $this->stderr = $error;
            }

            public function section(): ConsoleSectionOutput
            {
                throw new LogicException('not used');
            }
        };

        $exit = Artisan::call($command, $parameters, $output);
        $read = function (StreamOutput $stream): string {
            rewind($stream->getStream());

            return (string) stream_get_contents($stream->getStream());
        };

        return [$exit, $read($output), $read($output->getErrorOutput())];
    }

    public function test_stdout_is_one_line_holding_the_url(): void
    {
        $this->captureSecurityLog();
        AdminUser::factory()->create(['username' => 'amy']);
        $this->freezeSecond();

        [$exit, $stdout, $stderr] = $this->runCommand('openpne:admin:password-link', ['username' => 'amy']);

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('#\Ahttp://localhost/admin/password-setup/[A-Za-z0-9]{40}\n\z#', $stdout);
        $this->assertStringContainsString('amy', $stderr);
        $this->assertStringNotContainsString('/admin/password-setup/', $stderr);

        $token = Str::afterLast(trim($stdout), '/');
        $row = AdminPasswordSetupToken::sole();
        $this->assertSame(hash('sha256', $token), $row->token);
        $this->assertSame(now()->addMinutes(60)->getTimestamp(), $row->expires_at);

        $logged = $this->assertOneSecurityEvent('password.link_printed');
        $this->assertSame(['admin', 'amy', 'cli', now()->addMinutes(60)->toIso8601ZuluString()], [$logged['guard'], $logged['username'], $logged['via'], $logged['expires_at']]);
        $this->assertStringNotContainsString($token, json_encode($this->securityRecords(), JSON_THROW_ON_ERROR));
    }

    public function test_json_names_the_stored_username_and_the_expiry(): void
    {
        AdminUser::factory()->create(['username' => 'Amy']);
        $this->freezeSecond();

        [$exit, $stdout, $stderr] = $this->runCommand('openpne:admin:password-link', ['username' => 'amy', '--minutes' => '15', '--json' => true]);

        $this->assertSame([0, ''], [$exit, $stderr]);
        $this->assertStringEndsWith("}\n", $stdout);
        $this->assertSame(1, substr_count($stdout, "\n"));
        $json = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['username', 'url', 'expires_at'], array_keys($json));
        $this->assertSame('Amy', $json['username']);
        $this->assertStringStartsWith('http://localhost/admin/password-setup/', $json['url']);
        $this->assertSame(now()->addMinutes(15)->toIso8601ZuluString(), $json['expires_at']);
    }

    public function test_a_username_that_looks_like_console_markup_reaches_every_output_unchanged(): void
    {
        $names = ['<info>amy</info>', '<fg=nope>bob'];
        foreach ($names as $name) {
            AdminUser::factory()->create(['username' => $name]);

            [$exit, $stdout] = $this->runCommand('openpne:admin:password-link', ['username' => $name, '--json' => true]);
            $this->assertSame([0, $name], [$exit, json_decode($stdout, true, flags: JSON_THROW_ON_ERROR)['username']]);

            [$exit, , $stderr] = $this->runCommand('openpne:admin:password-link', ['username' => $name]);
            $this->assertSame(0, $exit);
            $this->assertStringContainsString("[{$name}]", $stderr);
        }

        [$exit, $stdout] = $this->runCommand('openpne:admin:list', ['--json' => true]);
        $this->assertSame([0, $names], [$exit, array_column(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR), 'username')]);

        [$exit, $stdout] = $this->runCommand('openpne:admin:list');
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('<fg=nope>bob', $stdout);
    }

    public function test_a_link_lasts_its_minutes_across_a_daylight_saving_change(): void
    {
        $original = date_default_timezone_get();
        config(['app.timezone' => 'America/New_York']);
        date_default_timezone_set('America/New_York');

        try {
            AdminUser::factory()->create(['username' => 'amy']);
            // Two hours from 00:45 EDT is 01:45 EST, a wall-clock time 01:50 EDT has passed; no instant here is in the repeated hour's second pass, which Carbon's test clock misreads.
            $this->travelTo(Carbon::parse('2026-11-01 04:45:00', 'UTC'));

            [, $stdout] = $this->runCommand('openpne:admin:password-link', ['username' => 'amy', '--minutes' => '120', '--json' => true]);
            $printed = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
            $token = Str::afterLast($printed['url'], '/');
            $this->assertSame('2026-11-01T06:45:00Z', $printed['expires_at']);

            $this->travelTo(Carbon::parse('2026-11-01 05:50:00', 'UTC'));
            $this->assertNotNull(app(AdminPasswords::class)->setupLinkOwner($token));
            [, $listed] = $this->runCommand('openpne:admin:list', ['--json' => true]);
            $this->assertSame($printed['expires_at'], json_decode($listed, true, flags: JSON_THROW_ON_ERROR)[0]['link_expires_at']);

            $this->travelTo(Carbon::parse('2026-11-01 07:00:00', 'UTC'));
            $this->assertNull(app(AdminPasswords::class)->setupLinkOwner($token));
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_a_new_link_replaces_the_old_and_leaves_the_password_and_sessions(): void
    {
        config(['session.driver' => 'database']);
        $admin = AdminUser::factory()->create(['username' => 'amy']);
        $before = DB::table('admin_users')->where('id', $admin->getKey())->first();
        DB::table('admin_sessions')->insert(['id' => 'amy-device', 'user_id' => $admin->getKey(), 'payload' => base64_encode('{}'), 'last_activity' => time()]);

        [, $first] = $this->runCommand('openpne:admin:password-link', ['username' => 'amy']);
        [, $second] = $this->runCommand('openpne:admin:password-link', ['username' => 'amy']);

        $this->assertNotSame($first, $second);
        $this->assertSame(hash('sha256', Str::afterLast(trim($second), '/')), AdminPasswordSetupToken::sole()->token);
        $this->assertEquals($before, DB::table('admin_users')->where('id', $admin->getKey())->first());
        $this->assertDatabaseHas('admin_sessions', ['id' => 'amy-device']);
    }

    public function test_minutes_outside_one_to_a_day_are_refused_with_nothing_on_stdout(): void
    {
        AdminUser::factory()->create(['username' => 'amy']);

        foreach (['0', '1441', '-5', '1.5', '', 'abc', ' 60'] as $minutes) {
            [$exit, $stdout, $stderr] = $this->runCommand('openpne:admin:password-link', ['username' => 'amy', '--minutes' => $minutes]);
            $this->assertSame([1, ''], [$exit, $stdout], "--minutes={$minutes}");
            $this->assertStringContainsString('--minutes', $stderr);
        }
        foreach (['1', '1440'] as $minutes) {
            $this->assertSame(0, $this->runCommand('openpne:admin:password-link', ['username' => 'amy', '--minutes' => $minutes])[0]);
        }
    }

    public function test_an_exception_goes_to_stderr_with_nothing_on_stdout(): void
    {
        AdminUser::factory()->create(['username' => 'amy']);
        DB::beforeExecuting(function (string $query): void {
            if (str_contains($query, 'admin_password_setup_tokens')) {
                throw new RuntimeException('link table unavailable');
            }
        });

        [$exit, $stdout, $stderr] = $this->runCommand('openpne:admin:password-link', ['username' => 'amy', '--json' => true]);

        $this->assertSame([1, ''], [$exit, $stdout]);
        $this->assertStringContainsString('link table unavailable', $stderr);
    }

    public function test_an_unknown_username_fails_with_nothing_on_stdout(): void
    {
        [$exit, $stdout, $stderr] = $this->runCommand('openpne:admin:password-link', ['username' => 'ghost', '--json' => true]);

        $this->assertSame([1, ''], [$exit, $stdout]);
        $this->assertStringContainsString('ghost', $stderr);
        $this->assertDatabaseCount('admin_password_setup_tokens', 0);
    }

    public function test_list_reports_password_two_factor_and_link_state(): void
    {
        $this->freezeSecond();
        $this->artisan('openpne:admin:create', ['username' => 'new', '--without-password' => true])->assertSuccessful();
        $set = AdminUser::factory()->create(['username' => 'set']);
        $set->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $empty = AdminUser::factory()->create(['username' => 'empty']);
        DB::table('admin_users')->where('id', $empty->getKey())->update(['password' => '']);
        $stale = AdminUser::factory()->create(['username' => 'stale']);

        $this->runCommand('openpne:admin:password-link', ['username' => 'new', '--minutes' => '30']);
        $this->runCommand('openpne:admin:password-link', ['username' => 'stale', '--minutes' => '1']);
        $this->travel(2)->minutes();

        [$exit, $stdout] = $this->runCommand('openpne:admin:list', ['--json' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(1, substr_count($stdout, "\n"));
        $created = now()->subMinutes(2)->toIso8601ZuluString();
        $this->assertSame([
            ['username' => 'new', 'password_set' => false, 'two_factor' => false, 'link_expires_at' => now()->addMinutes(28)->toIso8601ZuluString(), 'created_at' => $created],
            ['username' => 'set', 'password_set' => true, 'two_factor' => true, 'link_expires_at' => null, 'created_at' => $created],
            ['username' => 'empty', 'password_set' => false, 'two_factor' => false, 'link_expires_at' => null, 'created_at' => $created],
            ['username' => 'stale', 'password_set' => true, 'two_factor' => false, 'link_expires_at' => null, 'created_at' => $created],
        ], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));

        $this->artisan('openpne:admin:list')->expectsOutputToContain('not set')->assertSuccessful();
    }

    public function test_list_is_an_empty_array_when_there_are_no_administrators(): void
    {
        $this->assertSame([0, "[]\n"], array_slice($this->runCommand('openpne:admin:list', ['--json' => true]), 0, 2));
    }
}
