<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TermService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Detect translation key references in code that are missing from lang/{ja,en}.json,
 * and enforce a canonical key order.
 *
 *   php artisan i18n:check                  # CI gate: missing keys + key order
 *   php artisan i18n:check --unused         # also list defined-but-unused JSON keys
 *   php artisan i18n:check --update-baseline # snapshot current gaps to lang/.i18n-baseline.json
 *   php artisan i18n:check --prune-identity  # strip k === v entries from lang/{ja,en}.json
 *   php artisan i18n:check --sort            # rewrite lang/{ja,en}.json in canonical key order
 *   php artisan i18n:check --sort=lang/ja.json # ... restricted to a single file
 *
 * Omission policy: English-source codebase, so `key === English text`. Both `__()`
 * and `laravel-react-i18n` return the key verbatim when no entry is found, so
 * en.json entries that match the key are redundant and always optional. ja.json
 * normally requires a Japanese value, with one exception: keys composed solely
 * of `%name%` placeholders (e.g. `%Friend%`) round-trip through the term
 * substitution layer regardless of locale, so a JSON entry adds no value.
 */
class CheckTranslationsCommand extends Command
{
    protected $signature = 'i18n:check
        {--unused : Also report keys defined in lang/ but not used anywhere (informational, never fails CI)}
        {--update-baseline : Refresh lang/.i18n-baseline.json with the current set of missing keys}
        {--prune-identity : Remove all k === v entries from lang/{ja,en}.json (redundant under the omission policy)}
        {--sort= : Rewrite lang/{ja,en}.json in canonical key order; optionally scope to one file (lang/ja.json|lang/en.json)}';

    protected $description = 'Detect missing translation keys and enforce canonical key order in lang/ja.json / lang/en.json';

    /**
     * JSON dictionaries whose key order is enforced and rewritten by --sort.
     */
    private const SORTABLE_FILES = ['lang/ja.json', 'lang/en.json'];

    private const COLLISION_ALLOWLIST_FILE = 'lang/.i18n-collision-allowlist.json';

    /**
     * Pre-existing gaps recorded here are grandfathered; only NEW missing keys outside fail CI.
     */
    private const BASELINE_FILE = 'lang/.i18n-baseline.json';

    /**
     * Files that legitimately mention `__('...')` / `t('...')` strings without intending them as
     * real translation references (e.g. this command's own docblocks). Skip when extracting.
     */
    private const SELF_REFERENCE_FILES = [
        'app/Console/Commands/CheckTranslationsCommand.php',
    ];

    private const SCAN_DIRS = [
        'resources/js',
        'resources/views',
        'app',
        'database',
        'routes',
        'config',
    ];

    private const EXCLUDE_DIRS = [
        'node_modules',
        'vendor',
    ];

    public function handle(): int
    {
        $base = base_path();

        if ($this->wantsSort()) {
            return $this->sortFiles($base);
        }

        if ($this->option('prune-identity')) {
            return $this->pruneIdentityEntries($base);
        }

        $found = $this->extractUsedKeys($base);
        $defined = $this->loadDefinedKeys($base);
        $baseline = $this->loadBaseline($base);

        if ($this->option('update-baseline')) {
            return $this->writeBaseline($base, $found, $defined);
        }

        $missing = $this->reportMissing($found, $defined, $baseline);
        $unordered = $this->reportOrder($base);
        $this->reportCollisions($base);

        if ($this->option('unused')) {
            $this->reportUnused($found);
        }

        return ($missing > 0 || $unordered > 0) ? 1 : 0;
    }

    /**
     * Canonical key comparator: ASCII case-insensitive, with a byte-order
     * tiebreak so the order is total — case-only variants (`Cancel`/`cancel`)
     * get a single deterministic position. ASCII `strtolower` keeps it
     * locale/ICU-independent; non-ASCII bytes are settled by the tiebreak.
     * This is lexicographic, not numeric-aware: `Page 10` sorts before `Page 2`.
     */
    public static function localeKeyCompare(string $a, string $b): int
    {
        return strcmp(strtolower($a), strtolower($b)) ?: strcmp($a, $b);
    }

    private function wantsSort(): bool
    {
        return $this->input->hasParameterOption('--sort', true)
            || $this->option('sort') !== null;
    }

    /**
     * Resolve the --sort target(s). Empty value means both dictionaries;
     * a path is normalised and validated against the allow-list. Returns
     * null for an out-of-list path (caller reports the error).
     *
     * @return list<string>|null
     */
    private function sortTargets(): ?array
    {
        $value = (string) $this->option('sort');
        if ($value === '') {
            return self::SORTABLE_FILES;
        }

        $normalized = ltrim(str_replace('\\', '/', $value), './');
        if (! in_array($normalized, self::SORTABLE_FILES, true)) {
            return null;
        }

        return [$normalized];
    }

    private function sortFiles(string $base): int
    {
        $targets = $this->sortTargets();
        if ($targets === null) {
            $this->error('Invalid --sort target. Allowed: '.implode(', ', self::SORTABLE_FILES));

            return 1;
        }

        foreach ($targets as $rel) {
            $path = "{$base}/{$rel}";
            if (! is_file($path)) {
                $this->warn("Skipped {$rel}: not found");

                continue;
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (! is_array($data)) {
                $this->warn("Skipped {$rel}: not a JSON object");

                continue;
            }
            $this->writeSorted($path, $data);
            $this->info(sprintf('Sorted %s (%d keys)', $rel, count($data)));
        }

        return 0;
    }

    /**
     * Rewrite a JSON dictionary with keys in canonical order, preserving the
     * encoding the rest of the tooling uses (unescaped unicode/slashes, pretty,
     * trailing newline).
     *
     * @param  array<string, mixed>  $data
     */
    private function writeSorted(string $path, array $data): void
    {
        uksort($data, [self::class, 'localeKeyCompare']);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        file_put_contents($path, $json."\n");
    }

    /**
     * Hard gate: fail when a dictionary's keys are not in canonical order.
     * Uses the same comparator as --sort so the fixer's output always passes.
     *
     * @return int number of files out of order
     */
    private function reportOrder(string $base): int
    {
        $unordered = 0;
        foreach (self::SORTABLE_FILES as $rel) {
            $path = "{$base}/{$rel}";
            if (! is_file($path)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (! is_array($data)) {
                continue;
            }
            $keys = array_map('strval', array_keys($data));
            $sorted = $keys;
            usort($sorted, [self::class, 'localeKeyCompare']);
            if ($keys === $sorted) {
                continue;
            }
            $unordered++;
            $first = null;
            foreach ($keys as $i => $key) {
                if ($key !== $sorted[$i]) {
                    $first = $key;
                    break;
                }
            }
            $this->error(sprintf(
                '%s is not in canonical key order (first out of place: %s). Fix: php artisan i18n:check --sort=%s',
                $rel,
                json_encode($first, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $rel,
            ));
        }

        return $unordered;
    }

    /**
     * Advisory (never fails): list case-fold key collisions in lang/ja.json so
     * inconsistent translations of near-identical keys surface for review. The
     * canonical sort separates first-letter case variants, so this is the
     * deterministic net the adjacency cannot guarantee. Groups recorded in
     * COLLISION_ALLOWLIST_FILE (matched on the exact key set) are accepted and
     * suppressed; after normalisation the steady state is empty.
     */
    private function reportCollisions(string $base): void
    {
        $path = "{$base}/lang/ja.json";
        if (! is_file($path)) {
            return;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            return;
        }

        $allow = $this->loadCollisionAllowlist($base);

        $byFold = [];
        foreach (array_keys($data) as $key) {
            $byFold[strtolower((string) $key)][] = (string) $key;
        }

        $unresolved = [];
        foreach ($byFold as $keys) {
            if (count($keys) < 2) {
                continue;
            }
            if (isset($allow[$this->collisionSignature($keys)])) {
                continue;
            }
            $unresolved[] = $keys;
        }

        if ($unresolved === []) {
            return;
        }

        $this->warn(sprintf('Case-fold key collisions in lang/ja.json (%d) — informational, review for inconsistent translations:', count($unresolved)));
        foreach ($unresolved as $keys) {
            $values = array_map(static fn (string $k) => $data[$k], $keys);
            $tag = count(array_unique($values)) === 1 ? 'same-ja' : 'differ';
            $parts = array_map(
                static fn (string $k) => json_encode($k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    .'='.json_encode($data[$k], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $keys,
            );
            $this->line(sprintf('  - [%s] %s', $tag, implode('  ', $parts)));
        }
    }

    /**
     * @return array<string, true> exact-key-set signatures of accepted collisions
     */
    private function loadCollisionAllowlist(string $base): array
    {
        $path = "{$base}/".self::COLLISION_ALLOWLIST_FILE;
        if (! is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        $groups = is_array($data) ? ($data['groups'] ?? []) : [];

        $out = [];
        foreach ((array) $groups as $group) {
            if (is_array($group) && $group !== []) {
                $out[$this->collisionSignature(array_map('strval', $group))] = true;
            }
        }

        return $out;
    }

    /**
     * Order-independent signature of a collision group: the exact set of keys.
     * Matching on the full set (not the folded key) means adding a third
     * variant later re-surfaces the group instead of staying suppressed.
     *
     * @param  list<string>  $keys
     */
    private function collisionSignature(array $keys): string
    {
        sort($keys, SORT_STRING);

        return implode("\0", $keys);
    }

    /**
     * en.json is always optional (English-source key === text). ja.json is
     * optional only for keys that are entirely composed of `%name%`
     * placeholders, since those resolve via the term substitution layer.
     */
    private function isOptionalForLanguage(string $key, string $lang): bool
    {
        if ($lang === 'en') {
            return true;
        }

        if ($lang === 'ja') {
            return $this->isPurePlaceholderKey($key);
        }

        return false;
    }

    private function isPurePlaceholderKey(string $key): bool
    {
        return self::isResolvableViaTermLayer($key, $this->termNames());
    }

    /**
     * True when the key consists only of `%name%` placeholders (plus
     * whitespace) and every placeholder name resolves to a configured term.
     * Validating against the term set is what catches typos like `%Firend%`
     * — the runtime would leave those raw, and the exemption without a name
     * check would silently classify them as "no translation needed".
     *
     * @param  list<string>  $knownTermNames
     */
    public static function isResolvableViaTermLayer(string $key, array $knownTermNames): bool
    {
        if (preg_replace('/%[a-zA-Z_]+%|\s+/', '', $key) !== '') {
            return false;
        }

        preg_match_all('/%([a-zA-Z_]+)%/', $key, $matches);
        if ($matches[1] === []) {
            return false;
        }

        foreach ($matches[1] as $raw) {
            $name = ctype_upper($raw[0]) ? lcfirst($raw) : $raw;
            if (in_array($name, $knownTermNames, true)) {
                continue;
            }

            $singular = Str::singular($name);
            if ($singular !== $name && in_array($singular, $knownTermNames, true)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function termNames(): array
    {
        return $this->termNames ??= array_keys(TermService::defaults('ja'));
    }

    /**
     * @var list<string>|null
     */
    private ?array $termNames = null;

    private function pruneIdentityEntries(string $base): int
    {
        // en: every identity entry is redundant. ja: only pure-placeholder
        // identity entries are redundant (the term layer resolves those at
        // render time); regular ja entries that happen to match their key
        // are legitimate Japanese translations and must be kept.
        foreach (['ja', 'en'] as $lang) {
            $path = $base."/lang/{$lang}.json";
            if (! is_file($path)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($path), true);
            if (! is_array($data)) {
                $this->warn("Skipped {$path}: not a JSON object");

                continue;
            }
            $before = count($data);
            $kept = [];
            foreach ($data as $k => $v) {
                $key = (string) $k;
                if ($k === $v && $this->isOptionalForLanguage($key, $lang)) {
                    continue;
                }
                $kept[$key] = $v;
            }
            $this->writeSorted($path, $kept);
            $removed = $before - count($kept);
            $this->info(sprintf('lang/%s.json: %d → %d (-%d identity entries)', $lang, $before, count($kept), $removed));
        }

        return 0;
    }

    /**
     * @return array<string, list<string>> key => [file:line, ...]
     */
    private function extractUsedKeys(string $base): array
    {
        $found = [];

        $jsPattern = '/(?<![A-Za-z_$])t\(\s*([\'"])((?:(?!\1).)+)\1\s*[,)]/';
        $phpPattern = '/(?<![A-Za-z_])__\(\s*([\'"])((?:(?!\1).)+)\1\s*[,)]/';
        $bladePattern = '/@lang\(\s*([\'"])((?:(?!\1).)+)\1\s*[,)]/';

        foreach (self::SCAN_DIRS as $dir) {
            $abs = $base.DIRECTORY_SEPARATOR.$dir;
            if (! is_dir($abs)) {
                continue;
            }

            $finder = (new Finder)
                ->files()
                ->in($abs)
                ->exclude(self::EXCLUDE_DIRS)
                ->name(['*.tsx', '*.ts', '*.php', '*.blade.php']);

            foreach ($finder as $file) {
                /** @var SplFileInfo $file */
                $relPath = $this->relativePath($base, $file->getPathname());
                if (in_array($relPath, self::SELF_REFERENCE_FILES, true)) {
                    continue;
                }
                $patterns = match ($this->classify($file->getFilename())) {
                    'js' => [$jsPattern],
                    'php' => [$phpPattern],
                    'blade' => [$phpPattern, $bladePattern],
                    default => [],
                };
                if ($patterns === []) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());
                $lines = explode("\n", $contents);
                foreach ($lines as $i => $line) {
                    foreach ($patterns as $pat) {
                        if (preg_match_all($pat, $line, $m)) {
                            foreach ($m[2] as $key) {
                                $key = stripcslashes($key);
                                $found[$key][] = $relPath.':'.($i + 1);
                            }
                        }
                    }
                }
            }
        }

        return $found;
    }

    /**
     * @return array{ja: array<string, true>, en: array<string, true>}
     */
    private function loadDefinedKeys(string $base): array
    {
        $defined = ['ja' => [], 'en' => []];

        foreach (['ja', 'en'] as $lang) {
            $jsonPath = "{$base}/lang/{$lang}.json";
            if (is_file($jsonPath)) {
                $json = json_decode((string) file_get_contents($jsonPath), true);
                if (is_array($json)) {
                    foreach (array_keys($json) as $k) {
                        $defined[$lang][(string) $k] = true;
                    }
                }
            }

            $dir = "{$base}/lang/{$lang}";
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir.'/*.php') ?: [] as $file) {
                $ns = pathinfo($file, PATHINFO_FILENAME);
                /** @var array<string, mixed> $arr */
                $arr = require $file;
                if (! is_array($arr)) {
                    continue;
                }
                foreach (Arr::dot($arr) as $sub => $_) {
                    $defined[$lang]["{$ns}.{$sub}"] = true;
                }
                foreach (array_keys($arr) as $top) {
                    $defined[$lang]["{$ns}.".(string) $top] = true;
                }
            }
        }

        return $defined;
    }

    /**
     * @param  array<string, list<string>>  $found
     * @param  array{ja: array<string, true>, en: array<string, true>}  $defined
     * @param  array{ja: array<string, true>, en: array<string, true>}  $baseline
     * @return int number of unique missing keys NOT in baseline (across ja+en, deduplicated)
     */
    private function reportMissing(array $found, array $defined, array $baseline): int
    {
        $missingByLang = ['ja' => [], 'en' => []];
        $newMissingByLang = ['ja' => [], 'en' => []];
        foreach ($found as $key => $locations) {
            foreach (['ja', 'en'] as $lang) {
                if ($this->isOptionalForLanguage($key, $lang)) {
                    continue;
                }
                if (! isset($defined[$lang][$key])) {
                    $missingByLang[$lang][$key] = $locations;
                    if (! isset($baseline[$lang][$key])) {
                        $newMissingByLang[$lang][$key] = $locations;
                    }
                }
            }
        }

        $totalNew = count(array_unique([
            ...array_keys($newMissingByLang['ja']),
            ...array_keys($newMissingByLang['en']),
        ]));
        $totalAll = count(array_unique([
            ...array_keys($missingByLang['ja']),
            ...array_keys($missingByLang['en']),
        ]));
        $baselined = $totalAll - $totalNew;

        if ($baselined > 0) {
            $this->line(sprintf('Pre-existing gaps grandfathered by %s: %d (run `php artisan i18n:check --update-baseline` to refresh)', self::BASELINE_FILE, $baselined));
        }

        if ($totalNew === 0) {
            $this->info(sprintf('OK: %d translation keys checked, no new gaps.', count($found)));

            return 0;
        }

        foreach (['ja', 'en'] as $lang) {
            if ($newMissingByLang[$lang] === []) {
                continue;
            }
            $this->error(sprintf('NEW missing from lang/%s.json (%d):', $lang, count($newMissingByLang[$lang])));
            ksort($newMissingByLang[$lang]);
            foreach ($newMissingByLang[$lang] as $key => $locations) {
                $sample = $locations[0] ?? '?';
                $extra = count($locations) > 1 ? sprintf(' (+%d more)', count($locations) - 1) : '';
                $this->line('  - '.json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."  ← {$sample}{$extra}");
            }
            $this->line('');
        }

        return $totalNew;
    }

    /**
     * @return array{ja: array<string, true>, en: array<string, true>}
     */
    private function loadBaseline(string $base): array
    {
        $path = $base.'/'.self::BASELINE_FILE;
        if (! is_file($path)) {
            return ['ja' => [], 'en' => []];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data)) {
            return ['ja' => [], 'en' => []];
        }
        $out = ['ja' => [], 'en' => []];
        foreach (['ja', 'en'] as $lang) {
            foreach ((array) ($data[$lang] ?? []) as $k) {
                $out[$lang][(string) $k] = true;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<string>>  $found
     * @param  array{ja: array<string, true>, en: array<string, true>}  $defined
     */
    private function writeBaseline(string $base, array $found, array $defined): int
    {
        $missing = ['ja' => [], 'en' => []];
        foreach ($found as $key => $_) {
            foreach (['ja', 'en'] as $lang) {
                if ($this->isOptionalForLanguage($key, $lang)) {
                    continue;
                }
                if (! isset($defined[$lang][$key])) {
                    $missing[$lang][] = $key;
                }
            }
        }
        sort($missing['ja']);
        sort($missing['en']);

        $ordered = [
            '_note' => 'Generated by `php artisan i18n:check --update-baseline`. Lists translation keys referenced from code but currently missing from lang/{ja,en}.json. Pre-commit / CI checks ignore these grandfathered gaps; only NEW missing keys outside this list fail. Add proper translations and re-run --update-baseline to shrink the list.',
            'ja' => $missing['ja'],
            'en' => $missing['en'],
        ];

        $path = $base.'/'.self::BASELINE_FILE;
        $json = json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        file_put_contents($path, $json."\n");
        $this->info(sprintf('Wrote %s — ja: %d, en: %d', self::BASELINE_FILE, count($missing['ja']), count($missing['en'])));

        return 0;
    }

    /**
     * Only reports JSON-side unused keys. PHP-namespace defaults shipped by
     * laravel-lang/common are intentionally kept even when not referenced.
     *
     * @param  array<string, list<string>>  $found
     */
    private function reportUnused(array $found): void
    {
        $base = base_path();
        $unused = ['ja' => [], 'en' => []];

        foreach (['ja', 'en'] as $lang) {
            $jsonPath = "{$base}/lang/{$lang}.json";
            if (! is_file($jsonPath)) {
                continue;
            }
            $json = json_decode((string) file_get_contents($jsonPath), true);
            if (! is_array($json)) {
                continue;
            }
            foreach (array_keys($json) as $k) {
                $k = (string) $k;
                if (! isset($found[$k])) {
                    $unused[$lang][] = $k;
                }
            }
        }

        $total = count(array_unique([...$unused['ja'], ...$unused['en']]));
        if ($total === 0) {
            $this->info('No unused JSON translation keys.');

            return;
        }

        foreach (['ja', 'en'] as $lang) {
            if ($unused[$lang] === []) {
                continue;
            }
            $this->warn(sprintf('Unused in lang/%s.json (%d) — informational, not a CI failure:', $lang, count($unused[$lang])));
            sort($unused[$lang]);
            foreach (array_slice($unused[$lang], 0, 50) as $k) {
                $this->line('  - '.json_encode($k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            if (count($unused[$lang]) > 50) {
                $this->line(sprintf('  ... and %d more', count($unused[$lang]) - 50));
            }
            $this->line('');
        }
    }

    private function relativePath(string $base, string $abs): string
    {
        $base = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($abs, $base) ? substr($abs, strlen($base)) : $abs;
    }

    private function classify(string $filename): string
    {
        if (str_ends_with($filename, '.blade.php')) {
            return 'blade';
        }
        if (str_ends_with($filename, '.php')) {
            return 'php';
        }
        if (str_ends_with($filename, '.tsx') || str_ends_with($filename, '.ts')) {
            return 'js';
        }

        return '';
    }
}
