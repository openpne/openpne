<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\SiteCheck\BeforeInstallCheck;
use App\SiteCheck\Finding;
use App\SiteCheck\InstalledCheck;
use App\SiteCheck\SiteLayout;
use App\SiteCheck\Verdict;
use App\SiteCheck\WebCheck;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

/** See docs/internals/runtime.md, "Site check". */
class CheckCommand extends Command
{
    protected $signature = 'openpne:check
        {--only= : before, installed or web}
        {--parent-url= : The address that shows the directory holding this site\'s directory}
        {--lang= : ja or en}';

    protected $description = 'Say what this site lacks, and how to put it right';

    private const PARTS = ['before', 'installed', 'web'];

    private string $locale = 'en';

    public function handle(): int
    {
        $only = $this->option('only');
        $lang = $this->option('lang');
        if ($only !== null && ! in_array($only, self::PARTS, true)) {
            $this->error("Unknown part [{$only}]. Expected before, installed or web.");

            return self::FAILURE;
        }
        if ($lang !== null && ! in_array($lang, ['ja', 'en'], true)) {
            $this->error("Unknown language [{$lang}]. Expected ja or en.");

            return self::FAILURE;
        }
        $this->locale = $lang ?? self::shellLocale() ?? (config('app.locale') === 'ja' ? 'ja' : 'en');

        $layout = $this->laravel->bound(SiteLayout::class) ? $this->laravel->make(SiteLayout::class) : SiteLayout::of($this->laravel);
        $installed = file_exists($layout->envFile);
        $findings = [];

        if ($only === null || $only === 'before') {
            $findings = [...$findings, ...$this->part('heading_before', [], fn (): array => (new BeforeInstallCheck($layout))->run($installed))];
        }
        if ($only !== 'before' && ! $installed) {
            $this->newLine();
            $this->line(OutputFormatter::escape($this->say('not_installed', ['path' => $layout->envFile])));
            if ($only !== null) {
                return self::FAILURE;
            }
        } else {
            if ($only === null || $only === 'installed') {
                $check = new InstalledCheck($layout, $this->laravel->make('migrator'));
                $findings = [...$findings, ...$this->part('heading_installed', [], $check->run(...))];
            }
            if ($only === null || $only === 'web') {
                $web = $this->laravel->make(WebCheck::class, ['layout' => $layout]);
                // Laravel traps wherever pcntl is loaded, and fails where disable_functions holds what its trap calls.
                if (function_exists('pcntl_signal') && function_exists('pcntl_signal_get_handler') && function_exists('pcntl_async_signals')) {
                    $this->trap(fn (): array => [SIGINT, SIGTERM, SIGHUP], function (int $signal) use ($web): never {
                        $web->cleanUp();
                        exit(128 + $signal);
                    });
                }
                $findings = [...$findings, ...$this->part(
                    'heading_web',
                    ['url' => InstalledCheck::shownUrl((string) config('app.url'))],
                    fn (): array => $web->run($this->option('parent-url')),
                )];
                $this->untrap();
            }
        }

        $count = fn (Verdict $verdict): int => count(array_filter($findings, fn (Finding $f): bool => $f->verdict === $verdict));
        $this->newLine();
        $this->line(OutputFormatter::escape($this->say('summary', [
            'fail' => $count(Verdict::Fail),
            'warn' => $count(Verdict::Warn),
            'unknown' => $count(Verdict::Unknown),
        ])));

        return $count(Verdict::Fail) === 0 ? self::SUCCESS : self::FAILURE;
    }

    public static function shellLocale(): ?string
    {
        $lang = (string) getenv('LANG');

        return match (true) {
            str_starts_with($lang, 'ja') => 'ja',
            str_starts_with($lang, 'en') => 'en',
            default => null,
        };
    }

    /**
     * @param  array<string, string|int>  $replace
     * @param  callable(): list<Finding>  $run
     * @return list<Finding>
     */
    private function part(string $heading, array $replace, callable $run): array
    {
        $this->newLine();
        $this->line('<options=bold>'.OutputFormatter::escape($this->say($heading, $replace)).'</>');
        $findings = $run();
        foreach ($findings as $finding) {
            $tag = match ($finding->verdict) {
                Verdict::Ok => '<info>[ OK ]</info>',
                Verdict::Fail => '<error>[FAIL]</error>',
                Verdict::Warn => '<comment>[WARN]</comment>',
                Verdict::Unknown => '[ ?? ]',
            };
            $this->line('  '.$tag.' '.OutputFormatter::escape($this->say($finding->message, $finding->replace)));
        }

        return $findings;
    }

    /**
     * Placeholders are filled here rather than by the translator, whose term substitution would read
     * the database for any `%` a path brings, before a site has one.
     *
     * @param  array<string, string|int>  $replace
     */
    private function say(string $key, array $replace = []): string
    {
        $pairs = [];
        foreach ($replace as $name => $value) {
            $pairs[':'.$name] = (string) $value;
        }

        return strtr((string) __('console.check.'.$key, [], $this->locale), $pairs);
    }
}
