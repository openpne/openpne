<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\Template\MailTemplate;
use App\Notifications\Settings\NotificationCategory;
use App\Notifications\Settings\NotificationKind;
use App\Services\TermService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use SplFileInfo;
use stdClass;
use Symfony\Component\Finder\Finder;

/**
 * See docs/internals/i18n.md "CI gate" and "Omission policy ("key === text" pruning)".
 */
class CheckTranslationsCommand extends Command
{
    protected $signature = 'i18n:check
        {--unused : Also report keys defined in lang/ but not used anywhere (informational, never fails CI)}
        {--duplicates : Also list keys that share an identical ja value, i.e. consolidation candidates (informational, never fails CI)}
        {--update-baseline : Refresh lang/.i18n-baseline.json with the current set of missing keys}
        {--prune-identity : Remove all k === v entries from lang/{ja,en}.json (redundant under the omission policy)}
        {--sort= : Rewrite lang/{ja,en}.json in canonical key order; optionally scope to one file (lang/ja.json|lang/en.json)}';

    protected $description = 'Detect missing translation keys and enforce canonical key order in lang/ja.json / lang/en.json';

    private const SORTABLE_FILES = ['lang/ja.json', 'lang/en.json'];

    private const COLLISION_ALLOWLIST_FILE = 'lang/.i18n-collision-allowlist.json';

    /** laravel-lang's publisher owns these: the app must not author or edit them, nor reuse the names. */
    private const PUBLISHER_GROUPS = ['validation', 'auth', 'passwords', 'pagination', 'http-statuses', 'actions'];

    /**
     * App-authored PHP groups whose keys must carry a real value in BOTH ja and
     * en (structured keys have no "key === English text" omission fallback).
     */
    private const APP_UI_GROUPS = ['terms'];

    /**
     * App-authored PHP groups that tolerate source fallback: boundary and collision checks still
     * apply, the en+ja coverage requirement does not.
     */
    private const APP_REFERENCE_GROUPS = ['regions'];

    /**
     * Pre-existing gaps recorded here are grandfathered; only NEW missing keys outside fail CI.
     */
    private const BASELINE_FILE = 'lang/.i18n-baseline.json';

    /**
     * post_activity renders "post" / "ポスト", which occurs in ordinary prose; every other term's
     * value is distinctive enough to gate as a bare literal.
     */
    private const TERM_LITERAL_GENERIC_TERMS = ['post_activity'];

    /**
     * Registries whose captions reach __() through a variable, so the code scanner never sees them:
     * each exposes `sourceStrings()` for the term-literal gate and `coverageStrings()` for the
     * coverage gate. A new such registry must be added here or its literals go ungated.
     *
     * @var list<class-string>
     */
    private const DYNAMIC_SOURCE_REGISTRIES = [
        NotificationKind::class,
        NotificationCategory::class,
        MailTemplate::class,
    ];

    /**
     * Matched exactly against a source string or a ja value: an exception is a data addition here,
     * never a code change or a weakened gate.
     */
    private const TERM_LITERAL_ALLOWLIST_FILE = 'lang/.i18n-term-literal-allowlist.json';

    /**
     * Files that mention `__('...')` / `t('...')` without intending a real reference, skipped when
     * extracting.
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
        foreach (self::coverageSourceStrings() as $string => $registry) {
            $found[$string][] = 'registry: '.class_basename($registry);
        }
        $defined = $this->loadDefinedKeys($base);
        $baseline = $this->loadBaseline($base);

        if ($this->option('update-baseline')) {
            return $this->writeBaseline($base, $found, $defined);
        }

        $missing = $this->reportMissing($found, $defined, $baseline);
        $unordered = $this->reportOrder($base);
        $markerGaps = $this->reportMarkerLeaks($base);
        $boundary = $this->reportLangSubdirectories($base)
            + $this->reportNamespaceCollisions($base)
            + $this->reportUnknownGroups($base)
            + $this->reportAppUiCoverage($base)
            + $this->reportReactPhpGroupKeys($base)
            + $this->reportLiteralTerms($base, $found);
        $this->reportCollisions($base);
        $this->reportNearFold($base);

        if ($this->option('unused')) {
            $this->reportUnused($found);
        }
        if ($this->option('duplicates')) {
            $this->reportDuplicateValues($base);
        }

        return ($missing > 0 || $unordered > 0 || $markerGaps > 0 || $boundary > 0) ? 1 : 0;
    }

    /**
     * ASCII `strtolower` with a byte-order tiebreak, so the order is total and independent of
     * locale and ICU. Lexicographic, not numeric-aware: `Page 10` sorts before `Page 2`.
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
     * Null for a path outside SORTABLE_FILES, which the caller reports.
     *
     * @return list<string>|null
     */
    private function sortTargets(): ?array
    {
        $value = (string) $this->option('sort');
        if ($value === '') {
            return self::SORTABLE_FILES;
        }

        $normalized = str_replace('\\', '/', $value);
        if (str_starts_with($normalized, './')) {
            $normalized = substr($normalized, 2);
        }
        if (str_contains($normalized, '..') || ! in_array($normalized, self::SORTABLE_FILES, true)) {
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
     * The encoding stays the one the rest of the tooling writes: unescaped unicode and slashes,
     * pretty, trailing newline.
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
     * The same comparator as --sort, so the fixer's output always passes this gate.
     *
     * @return int number of files out of order
     */
    private function reportOrder(string $base): int
    {
        $unordered = 0;
        foreach (self::SORTABLE_FILES as $rel) {
            $path = "{$base}/{$rel}";
            if (! is_file($path)) {
                $unordered++;
                $this->error("{$rel} is missing — it is a required dictionary.");

                continue;
            }
            $raw = (string) file_get_contents($path);
            // A `[]` array, a scalar or invalid JSON is not a dictionary; `{}` decodes to an empty
            // stdClass and passes.
            if (! json_decode($raw, false) instanceof stdClass) {
                $unordered++;
                $this->error("{$rel} is not a JSON object.");

                continue;
            }
            $data = (array) json_decode($raw, true);
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
     * Advisory: the canonical sort separates first-letter case variants, so adjacency alone cannot
     * surface these.
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
     * Matching on the full key set rather than the folded key means adding a third variant later
     * re-surfaces the group instead of staying suppressed.
     *
     * @param  list<string>  $keys
     */
    private function collisionSignature(array $keys): string
    {
        sort($keys, SORT_STRING);

        return implode("\0", $keys);
    }

    /**
     * Advisory: only groups whose Japanese differs surface, a same-ja fold (`Diary`/`Diaries`→日記)
     * being benign.
     */
    private function reportNearFold(string $base): void
    {
        $data = $this->loadJsonDictionary("{$base}/lang/ja.json");
        if ($data === []) {
            return;
        }

        $allow = $this->loadCollisionAllowlist($base);

        $byStem = [];
        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (! self::isNearFoldCandidate($key)) {
                continue;
            }
            $byStem[self::nearFoldStem($key)][$key] = (string) $value;
        }

        $flagged = [];
        foreach ($byStem as $group) {
            if (count($group) < 2 || count(array_unique($group)) === 1) {
                continue;
            }
            if (isset($allow[$this->collisionSignature(array_keys($group))])) {
                continue;
            }
            $flagged[] = $group;
        }

        if ($flagged === []) {
            return;
        }

        $this->warn(sprintf('Near-fold key pairs with differing ja in lang/ja.json (%d) — informational, review for semantic collisions:', count($flagged)));
        foreach ($flagged as $group) {
            $parts = [];
            foreach ($group as $k => $v) {
                $parts[] = json_encode($k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    .'='.json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $this->line('  - [differ] '.implode('  ', $parts));
        }
    }

    /**
     * A missing entry falls back to the key and an identity entry is the key, so either one leaks
     * the `(context)` tag into the UI.
     *
     * @return int number of marker keys that would leak the tag
     */
    private function reportMarkerLeaks(string $base): int
    {
        $leaking = self::markerKeysWithLeak(
            array_map('strval', $this->loadJsonDictionary("{$base}/lang/ja.json")),
            array_map('strval', $this->loadJsonDictionary("{$base}/lang/en.json")),
        );

        if ($leaking === []) {
            return 0;
        }

        $this->error(sprintf('Marker keys without a real translation (%d) — a missing or identity-valued ja/en entry leaks the `(context)` tag into the UI:', count($leaking)));
        foreach ($leaking as $key) {
            $this->line('  - '.json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return count($leaking);
    }

    /**
     * Off by default: many identical-value groups are legitimately distinct keys, so this is a
     * review aid rather than a gate.
     */
    private function reportDuplicateValues(string $base): void
    {
        $byValue = [];
        foreach ($this->loadJsonDictionary("{$base}/lang/ja.json") as $key => $value) {
            $byValue[(string) $value][] = (string) $key;
        }
        $groups = array_filter($byValue, static fn (array $keys): bool => count($keys) > 1);

        if ($groups === []) {
            $this->info('No duplicate ja values.');

            return;
        }

        $this->warn(sprintf('Keys sharing an identical ja value in lang/ja.json (%d groups) — informational, consolidation candidates:', count($groups)));
        ksort($groups);
        foreach ($groups as $value => $keys) {
            sort($keys);
            $rendered = implode(', ', array_map(
                static fn (string $k) => json_encode($k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $keys,
            ));
            $this->line('  - '.json_encode((string) $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).': '.$rendered);
        }
    }

    /**
     * The laravel-react-i18n Vite parser recurses subdirectories into `dir.file.key` keys while
     * Laravel's loader reads only top-level `{group}.php`, so a subdirectory diverges between them.
     *
     * @return int number of offending subdirectories
     */
    private function reportLangSubdirectories(string $base): int
    {
        $violations = 0;
        foreach (['ja', 'en'] as $lang) {
            $dir = "{$base}/lang/{$lang}";
            if (! is_dir($dir)) {
                continue;
            }
            $subdirs = [];
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (is_dir("{$dir}/{$entry}")) {
                    $subdirs[] = $entry;
                }
            }
            if ($subdirs === []) {
                continue;
            }
            $violations += count($subdirs);
            $this->error(sprintf(
                'lang/%s/ has subdirectories (%s) — namespace files must be flat. The React parser recurses subdirs but the Laravel loader does not, so they diverge. Use nested PHP arrays, not directories.',
                $lang,
                implode(', ', $subdirs),
            ));
        }

        return $violations;
    }

    /**
     * laravel-react-i18n merges `php_{locale}.json` after `{locale}.json`, so a PHP-namespace key
     * silently wins over a colliding JSON key.
     *
     * @return int number of colliding JSON keys
     */
    private function reportNamespaceCollisions(string $base): int
    {
        $groups = $this->phpGroupNames($base);
        if ($groups === []) {
            return 0;
        }

        $phpKeys = [];
        foreach (['ja', 'en'] as $lang) {
            foreach ($this->phpGroupKeys($base, $lang) as $keys) {
                foreach ($keys as $key) {
                    $phpKeys[$key] = true;
                }
            }
        }

        $violations = 0;
        foreach (['ja', 'en'] as $lang) {
            $json = $this->loadJsonDictionary("{$base}/lang/{$lang}.json");
            $bad = self::jsonKeysUnderPhpGroups(array_map('strval', array_keys($json)), $groups);
            if ($bad === []) {
                continue;
            }
            $violations += count($bad);
            $this->error(sprintf(
                'lang/%s.json keys collide with PHP namespace groups (%d) — php_%s.json is merged last, so the PHP value silently wins:',
                $lang,
                count($bad),
                $lang,
            ));
            foreach ($bad as $key) {
                $tag = isset($phpKeys[$key]) ? 'overrides' : 'shadows';
                $this->line(sprintf(
                    '  - [%s] %s (group "%s")',
                    $tag,
                    json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    explode('.', $key)[0],
                ));
            }
        }

        return $violations;
    }

    /**
     * A group outside the three lists fails, so one newly published by `lang:update` and a
     * misplaced app file both get a deliberate classification rather than joining the catalog.
     *
     * @return int number of unrecognised groups
     */
    private function reportUnknownGroups(string $base): int
    {
        $unknown = self::unknownGroups($this->phpGroupNames($base), [
            ...self::PUBLISHER_GROUPS,
            ...self::APP_UI_GROUPS,
            ...self::APP_REFERENCE_GROUPS,
        ]);
        if ($unknown === []) {
            return 0;
        }

        $this->error(sprintf(
            'Unrecognised PHP translation group(s): %s. Classify in CheckTranslationsCommand: APP_UI_GROUPS (en+ja required) or APP_REFERENCE_GROUPS (source fallback) if app-authored, or PUBLISHER_GROUPS if lang:update published it.',
            implode(', ', $unknown),
        ));

        return count($unknown);
    }

    /**
     * app-reference groups are exempt, and a group absent from both locales is skipped.
     *
     * @return int number of one-sided keys across app-ui groups
     */
    private function reportAppUiCoverage(string $base): int
    {
        $ja = $this->phpGroupKeys($base, 'ja');
        $en = $this->phpGroupKeys($base, 'en');

        $violations = 0;
        foreach (self::APP_UI_GROUPS as $group) {
            $gaps = self::coverageGaps($ja[$group] ?? [], $en[$group] ?? []);
            foreach (['en' => $gaps['missing_en'], 'ja' => $gaps['missing_ja']] as $lang => $missing) {
                if ($missing === []) {
                    continue;
                }
                $violations += count($missing);
                $this->error(sprintf(
                    'App-UI group "%s" missing from lang/%s/%s.php (%d): %s',
                    $group,
                    $lang,
                    $group,
                    count($missing),
                    implode(', ', $missing),
                ));
            }
        }

        return $violations;
    }

    /**
     * The React provider loads only `lang/*.json`, so a PHP dotted key renders raw there while the
     * coverage gate counts it as defined. The Vite php-namespace plugin is deliberately not wired.
     *
     * @return int number of unreachable React references
     */
    private function reportReactPhpGroupKeys(string $base): int
    {
        $groups = $this->phpGroupNames($base);
        if ($groups === []) {
            return 0;
        }

        $bad = self::jsonKeysUnderPhpGroups(array_keys($this->jsReferencedKeys), $groups);
        if ($bad === []) {
            return 0;
        }

        $this->error(sprintf(
            'React t() references PHP-namespace keys (%d) — the React provider loads only lang/*.json, so PHP dotted keys render raw. Use a flat JSON key until the Vite PHP-namespace plugin is wired:',
            count($bad),
        ));
        foreach ($bad as $key) {
            $this->line(sprintf(
                '  - %s  ← %s',
                json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $this->jsReferencedKeys[$key],
            ));
        }

        return count($bad);
    }

    /**
     * A term word must sit inside a `%term%` placeholder so an admin's override reaches every
     * surface. English words are matched against source strings, Japanese words against ja values.
     *
     * @param  array<string, list<string>>  $found  extracted key => [file:line, ...]
     * @return int number of offending strings
     */
    private function reportLiteralTerms(string $base, array $found): int
    {
        $allow = $this->loadTermLiteralAllowlist($base);
        $ja = $this->loadJsonDictionary("{$base}/lang/ja.json");
        $enWords = self::termLiteralWords('en');
        $jaWords = self::termLiteralWords('ja');

        $sources = [];
        foreach ($found as $key => $locations) {
            $sources[(string) $key] ??= $locations[0] ?? 'code';
        }
        foreach (array_keys($ja) as $key) {
            $sources[(string) $key] ??= 'lang/ja.json (key)';
        }
        foreach (self::dynamicSourceStrings() as $string => $registry) {
            $sources[(string) $string] ??= $registry;
        }

        $violations = [];
        foreach ($sources as $text => $origin) {
            if (isset($allow[$text])) {
                continue;
            }
            $hits = self::bareTermMatches((string) $text, $enWords, true);
            if ($hits !== []) {
                $violations[] = [(string) $text, $origin, $hits];
            }
        }

        foreach ($ja as $key => $value) {
            $value = (string) $value;
            if (isset($allow[$value]) || isset($allow[(string) $key])) {
                continue;
            }
            $hits = self::bareTermMatches($value, $jaWords, false);
            if ($hits !== []) {
                $violations[] = [$value, 'lang/ja.json value of '.json_encode((string) $key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $hits];
            }
        }

        if ($violations === []) {
            return 0;
        }

        $this->error(sprintf(
            'Bare term-vocabulary literals (%d) — wrap the term in a %%placeholder%% (e.g. %%diary%%) so admin term overrides reach every surface, or allowlist in %s if genuinely generic:',
            count($violations),
            self::TERM_LITERAL_ALLOWLIST_FILE,
        ));
        foreach ($violations as [$text, $origin, $hits]) {
            $this->line(sprintf(
                '  - %s  [%s]  ← %s',
                json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                implode(', ', $hits),
                $origin,
            ));
        }

        return count($violations);
    }

    /**
     * @return array<string, true> exact strings exempt from the term-literal gate
     */
    private function loadTermLiteralAllowlist(string $base): array
    {
        $path = "{$base}/".self::TERM_LITERAL_ALLOWLIST_FILE;
        if (! is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        $allow = is_array($data) ? ($data['allow'] ?? []) : [];

        $out = [];
        foreach ((array) $allow as $string) {
            $out[(string) $string] = true;
        }

        return $out;
    }

    /**
     * Every registry's raw `sourceStrings()`, which the term-literal gate reads here because they
     * never enter the code scanner.
     *
     * @return array<string, class-string> source string => registry class
     */
    public static function dynamicSourceStrings(): array
    {
        $out = [];
        foreach (self::DYNAMIC_SOURCE_REGISTRIES as $registry) {
            foreach ($registry::sourceStrings() as $string) {
                $out[(string) $string] ??= $registry;
            }
        }

        return $out;
    }

    /**
     * Every registry's `coverageStrings()` — the rendered subset the coverage gate requires a ja
     * translation for.
     *
     * @return array<string, class-string> surfaced source string => registry class
     */
    public static function coverageSourceStrings(): array
    {
        $out = [];
        foreach (self::DYNAMIC_SOURCE_REGISTRIES as $registry) {
            foreach ($registry::coverageStrings() as $string) {
                $out[(string) $string] ??= $registry;
            }
        }

        return $out;
    }

    /**
     * Derived from the shipped term defaults, so adding a term extends the gate with no second list
     * to maintain.
     *
     * @return list<string>
     */
    public static function termLiteralWords(string $locale): array
    {
        $defaults = array_diff_key(TermService::defaults($locale), array_flip(self::TERM_LITERAL_GENERIC_TERMS));

        $words = [];
        foreach ($defaults as $value) {
            $words[$value] = true;
            if ($locale === 'en') {
                $words[Str::plural($value)] = true;
            }
        }

        return array_keys($words);
    }

    /**
     * Matches outside any `%placeholder%` and outside a `:param` token. ASCII words match on word
     * boundaries; non-ASCII words match as substrings, Japanese having none.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    public static function bareTermMatches(string $text, array $words, bool $ascii): array
    {
        $stripped = (string) preg_replace(['/%[A-Za-z_]+%/', '/:[A-Za-z_]+/'], '', $text);

        $hits = [];
        foreach ($words as $word) {
            $pattern = $ascii
                ? '/\b'.preg_quote($word, '/').'\b/i'
                : '/'.preg_quote($word, '/').'/u';
            if (preg_match($pattern, $stripped) === 1) {
                $hits[] = $word;
            }
        }

        return $hits;
    }

    /**
     * @return list<string>
     */
    private function phpGroupNames(string $base): array
    {
        $groups = [];
        foreach (['ja', 'en'] as $lang) {
            $dir = "{$base}/lang/{$lang}";
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir.'/*.php') ?: [] as $file) {
                $groups[pathinfo($file, PATHINFO_FILENAME)] = true;
            }
        }
        $names = array_keys($groups);
        sort($names);

        return $names;
    }

    /**
     * Flattened as the Vite parser flattens them, `{group}.{nested.path}`.
     *
     * @return array<string, list<string>> group => dotted keys
     */
    private function phpGroupKeys(string $base, string $lang): array
    {
        $out = [];
        $dir = "{$base}/lang/{$lang}";
        if (! is_dir($dir)) {
            return $out;
        }
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $ns = pathinfo($file, PATHINFO_FILENAME);
            /** @var array<string, mixed> $arr */
            $arr = require $file;
            $keys = [];
            if (is_array($arr)) {
                foreach (array_keys(Arr::dot($arr)) as $sub) {
                    $keys[] = "{$ns}.".(string) $sub;
                }
            }
            $out[$ns] = $keys;
        }

        return $out;
    }

    /**
     * A sentence key whose first segment is not a group (`%Community% deleted.`) is unaffected.
     *
     * @param  list<string>  $jsonKeys
     * @param  list<string>  $groupNames
     * @return list<string>
     */
    public static function jsonKeysUnderPhpGroups(array $jsonKeys, array $groupNames): array
    {
        $bad = [];
        foreach ($jsonKeys as $key) {
            if (in_array(explode('.', $key)[0], $groupNames, true)) {
                $bad[] = $key;
            }
        }

        return $bad;
    }

    /**
     * @param  list<string>  $present
     * @param  list<string>  $known
     * @return list<string>
     */
    public static function unknownGroups(array $present, array $known): array
    {
        return array_values(array_diff($present, $known));
    }

    /**
     * @param  list<string>  $jaKeys
     * @param  list<string>  $enKeys
     * @return array{missing_en: list<string>, missing_ja: list<string>}
     */
    public static function coverageGaps(array $jaKeys, array $enKeys): array
    {
        return [
            'missing_en' => array_values(array_diff($jaKeys, $enKeys)),
            'missing_ja' => array_values(array_diff($enKeys, $jaKeys)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJsonDictionary(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Plain ASCII label keys only, so sentences, `%name%` placeholders, `:count` strings and
     * `(context)` markers never group.
     */
    public static function isNearFoldCandidate(string $key): bool
    {
        return (bool) preg_match('#^[A-Za-z][A-Za-z /]*$#', $key);
    }

    /**
     * Closed homograph-marker vocabulary, kept small so it never collides with
     * display parentheticals (`Caption (English)`, `Message (optional)`).
     */
    public static function isMarkerKey(string $key): bool
    {
        return (bool) preg_match('/\((?:noun|verb|adjective|adverb)\)$/', $key);
    }

    /**
     * Str::singular rather than naive suffix stripping, so `Status`, `Address` and `News` keep a
     * stable stem.
     */
    public static function nearFoldStem(string $key): string
    {
        return strtolower(Str::singular(trim($key)));
    }

    /**
     * Checked against full key→value maps, not key presence: an identity entry passes a presence
     * check but still renders the tag.
     *
     * @param  array<string, string>  $ja
     * @param  array<string, string>  $en
     * @return list<string>
     */
    public static function markerKeysWithLeak(array $ja, array $en): array
    {
        $leaking = [];
        foreach (array_unique([...array_keys($ja), ...array_keys($en)]) as $key) {
            $key = (string) $key;
            if (! self::isMarkerKey($key)) {
                continue;
            }
            $jaLeaks = ! array_key_exists($key, $ja) || $ja[$key] === $key;
            $enLeaks = ! array_key_exists($key, $en) || $en[$key] === $key;
            if ($jaLeaks || $enLeaks) {
                $leaking[] = $key;
            }
        }

        return $leaking;
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
     * Validating each placeholder against the term set is what catches a typo like `%Firend%`,
     * which the runtime leaves raw and the exemption would otherwise excuse.
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

    /**
     * @var array<string, string> key => "file:line"
     */
    private array $jsReferencedKeys = [];

    private function pruneIdentityEntries(string $base): int
    {
        // Every en identity entry is redundant, but a regular ja entry matching its key is a legitimate
        // translation and is kept; only pure-placeholder ja identity rows go.
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
                $kind = $this->classify($file->getFilename());
                $patterns = match ($kind) {
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
                                $location = $relPath.':'.($i + 1);
                                $found[$key][] = $location;
                                if ($kind === 'js') {
                                    $this->jsReferencedKeys[$key] ??= $location;
                                }
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
     * Never a deletion list: lang/*.json also holds publisher keys the framework renders at
     * runtime, which this scan cannot see (docs/internals/i18n.md "Ownership: publisher-managed vs app-authored").
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
            $this->info('No unreferenced JSON translation keys.');

            return;
        }

        $this->warn('JSON keys not referenced by the app-code scan (informational, never fails CI).');
        $this->line('NOT a deletion list: lang/*.json also holds laravel-lang publisher keys rendered by');
        $this->line('the framework/vendor (e.g. pagination "to"/"results", validation, http-statuses) that');
        $this->line('this scan cannot see — removing them breaks framework output. See docs/internals/i18n.md.');
        $this->line('');

        foreach (['ja', 'en'] as $lang) {
            if ($unused[$lang] === []) {
                continue;
            }
            $this->warn(sprintf('Not referenced in app code — lang/%s.json (%d):', $lang, count($unused[$lang])));
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
