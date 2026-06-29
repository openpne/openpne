<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TermService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use SplFileInfo;
use stdClass;
use Symfony\Component\Finder\Finder;

/**
 * Detect translation key references in code that are missing from lang/{ja,en}.json,
 * and enforce a canonical key order.
 *
 *   php artisan i18n:check                  # CI gate: missing keys + key order + marker en
 *   php artisan i18n:check --unused         # also list defined-but-unused JSON keys
 *   php artisan i18n:check --duplicates     # also list keys sharing an identical ja value
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
        {--duplicates : Also list keys that share an identical ja value, i.e. consolidation candidates (informational, never fails CI)}
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
     * PHP namespace groups laravel-lang's publisher (`lang:add` / `lang:update`)
     * owns. The app must not author or edit these, nor reuse the names. A group
     * outside the three lists fails {@see reportUnknownGroups} so a new
     * publisher group (added by `lang:update`) or a misplaced app file gets a
     * deliberate classification rather than silently joining the catalog.
     */
    private const PUBLISHER_GROUPS = ['validation', 'auth', 'passwords', 'pagination', 'http-statuses', 'actions'];

    /**
     * App-authored PHP groups whose keys must carry a real value in BOTH ja and
     * en (structured keys have no "key === English text" omission fallback).
     */
    private const APP_UI_GROUPS = ['terms'];

    /**
     * App-authored PHP groups that tolerate source fallback / partial coverage
     * (e.g. `regions`: `lang/en/regions.php` is empty and RegionListService
     * falls back to the English source name). Boundary/collision checks still
     * apply; the en+ja coverage requirement does not.
     */
    private const APP_REFERENCE_GROUPS = ['regions'];

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
        $markerGaps = $this->reportMarkerLeaks($base);
        $boundary = $this->reportLangSubdirectories($base)
            + $this->reportNamespaceCollisions($base)
            + $this->reportUnknownGroups($base)
            + $this->reportAppUiCoverage($base)
            + $this->reportReactPhpGroupKeys($base);
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
                $unordered++;
                $this->error("{$rel} is missing — it is a required dictionary.");

                continue;
            }
            $raw = (string) file_get_contents($path);
            // Enforce the JSON-object shape: a `[]` array (or scalar/invalid)
            // is not a dictionary. `{}` decodes to an empty stdClass and passes.
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
     * Advisory (never fails): near-fold key pairs — label keys whose singularised,
     * lowercased form matches (singular/plural/light derivation) but whose
     * Japanese differs (e.g. `Link` リンク vs `Links` リンク集). Only `[differ]`
     * groups surface; same-ja folds (`Diary`/`Diaries`→日記) are benign and
     * omitted. Uses Str::singular (not naive s/es stripping) so `Status`,
     * `Address`, `News` keep a stable stem. Restricted to plain ASCII label
     * keys so sentences and `%name%`/`:count` strings never group. Intentional
     * pairs are recorded in COLLISION_ALLOWLIST_FILE, same as case-fold groups.
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
                continue; // single key, or a benign same-ja fold
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
     * Hard gate: a homograph marker key (`Word (noun)` / `(verb)` /
     * `(adjective)` / `(adverb)`) must render a real translation in BOTH
     * locales — never the key itself. A missing entry falls back to the key,
     * and an identity entry (value === key) IS the key, so either one leaks the
     * `(context)` tag into the UI. (`--prune-identity` is not part of the
     * default gate, so the value is checked here.) The closed vocabulary keeps
     * this from catching display parentheticals like `Caption (English)`.
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
     * Advisory, opt-in (`--duplicates`): keys sharing an identical ja value are
     * consolidation candidates (`Order` / `Sort Order` → 並び順). Off by default
     * because many identical-value groups are legitimately distinct keys; this
     * is a manual review aid, not a gate.
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
     * Hard gate: `lang/{locale}/` must hold flat `*.php` group files only. The
     * laravel-react-i18n Vite parser recurses subdirectories into `dir.file.key`
     * dotted keys, but Laravel's backend loader reads only `{group}.php` at the
     * top level — so a subdirectory resolves on the frontend and 404s on the
     * backend. Express hierarchy with nested PHP arrays, not directories.
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
     * Hard gate: a flat JSON key must not fall under a PHP namespace group.
     * laravel-react-i18n merges `php_{locale}.json` AFTER `{locale}.json`, so a
     * PHP-namespace key silently wins over a colliding JSON key — exactly the
     * Laravel "Key/File" rule. `[overrides]` = the PHP key exists today (active
     * silent override); `[shadows]` = the group exists but not (yet) this key
     * (latent — adding it later would shadow the JSON entry).
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
     * Hard gate: every PHP group must be classified as publisher, app-ui, or
     * app-reference. An unrecognised group means `lang:update` published a new
     * framework group (add to PUBLISHER_GROUPS) or an app file was added without
     * a classification (add to APP_UI_GROUPS / APP_REFERENCE_GROUPS).
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
     * Hard gate: app-ui group keys must exist in BOTH ja and en (structured
     * keys have no "key === English text" omission). app-reference groups are
     * exempt (source fallback). A group absent from both locales is skipped.
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
     * Hard gate: a React `t()` call may not reference a key under a PHP namespace
     * group. The React provider loads only `lang/*.json`, so PHP dotted keys are
     * unreachable from the frontend and render raw — yet the coverage gate counts
     * them as defined (they exist in `lang/{locale}/*.php`), so the miss would
     * otherwise pass. This is a standing invariant: app UI strings live in the
     * flat source-text JSON dictionary, and PHP namespaces are reserved for
     * framework/reference groups the frontend does not consume — so the
     * laravel-react-i18n Vite php-namespace plugin is intentionally not wired.
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
     * Sorted union of PHP group (file) names across both locales.
     *
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
     * Dotted keys per PHP group for one locale, as the Vite parser flattens
     * them (`{group}.{nested.path}`).
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
     * JSON keys whose first dot-segment is a PHP group name (`terms.x`, or the
     * bare group `terms`). Sentence keys whose first segment is not a group
     * (`%Community% deleted.`) are unaffected.
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
     * PHP groups present on disk but not in the known classification.
     *
     * @param  list<string>  $present
     * @param  list<string>  $known
     * @return list<string>
     */
    public static function unknownGroups(array $present, array $known): array
    {
        return array_values(array_diff($present, $known));
    }

    /**
     * One-sided keys between two locales of a group (each side's keys absent on
     * the other).
     *
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
     * Only plain ASCII label keys take part in near-fold grouping — letters,
     * spaces, and `/` (`Sender/Recipient`). This excludes sentences (which end
     * in punctuation), `%name%` placeholders, `:count` strings, and the
     * `(context)` markers, none of which are singular/plural label pairs.
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
     * Singular/plural/derivation-insensitive stem for near-fold grouping. Uses
     * Str::singular rather than naive suffix stripping so `Status`, `Address`,
     * `News` keep a stable stem.
     */
    public static function nearFoldStem(string $key): string
    {
        return strtolower(Str::singular(trim($key)));
    }

    /**
     * Marker keys that would leak the `(context)` tag: the value is missing
     * (falls back to the key) or identity (equals the key) in ja or en. Checked
     * against full key→value maps, not just key presence — an identity entry
     * passes a presence check but still renders the tag.
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

    /**
     * Keys referenced from React `t()` (JS/TS), with one sample location, as
     * populated by {@see extractUsedKeys}. Used by {@see reportReactPhpGroupKeys}.
     *
     * @var array<string, string> key => "file:line"
     */
    private array $jsReferencedKeys = [];

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
     * Reports JSON keys not referenced by the app-code scan. Informational and NOT a deletion
     * list: lang/*.json mixes app-authored keys with laravel-lang publisher keys that the
     * framework/vendor renders at runtime (pagination "to"/"results", validation, http-statuses,
     * ...) which this scan cannot see. PHP-namespace defaults are likewise kept. See
     * docs/internals/i18n.md.
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
