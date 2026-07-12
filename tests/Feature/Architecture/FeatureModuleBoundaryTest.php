<?php

namespace Tests\Feature\Architecture;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Pins the feature-module boundary rules from docs/internals/feature-modules.md that the folder
 * layout alone cannot enforce — the layout is conventional, so a violation would otherwise creep in
 * silently. Each assertion names the rule and the doc:
 *
 *  - HTTP stays at the adapter boundary: Actions/Queries/Data/Serializers never receive a Request
 *    or FormRequest (Key invariant 1 / "Boundary rules").
 *  - Transactional side effects live in Actions, not controllers (Key invariant 3 / "Side effects").
 *  - A controller never imports another feature's controller (surfaces stay thin, "Boundary rules").
 */
class FeatureModuleBoundaryTest extends TestCase
{
    /**
     * Controllers carrying a `DB::transaction(` today. Side effects belong in Actions, so the target
     * is zero everywhere; these predate this guard and their move into feature Actions is tracked for
     * burn-down. The pinned count may only go DOWN — a new/extra occurrence fails as a regression, and
     * a drop below the pinned number fails too so whoever lands the burn-down updates this baseline.
     */
    private const TRANSACTION_BASELINE = [
        'Member/MemberMfaController.php' => 2,
    ];

    /** @return list<string> absolute paths of every .php file under $dir, recursively, sorted */
    private function phpFilesUnder(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** Path relative to app/Features/, e.g. "Member/MemberMfaController.php". */
    private function featureRelative(string $absolute): string
    {
        return str_replace(app_path('Features').'/', '', $absolute);
    }

    public function test_http_stays_at_the_adapter_boundary(): void
    {
        // Scope is path-based: only the four adapter-facing subdirectories are scanned. Top-level
        // feature primitives (e.g. Auth/LoginFormData, Auth/SpamTrap) legitimately touch Request and
        // are deliberately NOT scanned — the FormRequest converts HTTP input at the controller edge.
        // (No GLOB_BRACE: it is unavailable on musl-based systems.)
        $dirs = [];
        foreach (glob(app_path('Features').'/*', GLOB_ONLYDIR) ?: [] as $featureDir) {
            foreach (['Actions', 'Queries', 'Data', 'Serializers'] as $sub) {
                $dirs[] = $featureDir.'/'.$sub;
            }
        }

        $scanned = [];
        $offenders = [];
        foreach ($dirs as $dir) {
            foreach ($this->phpFilesUnder($dir) as $file) {
                $rel = $this->featureRelative($file);
                $scanned[] = $rel;
                $contents = file_get_contents($file);
                if (str_contains($contents, 'Illuminate\Http\Request') || str_contains($contents, 'FormRequest')) {
                    $offenders[] = $rel;
                }
            }
        }

        $this->assertNotEmpty($scanned, 'Found no Actions/Queries/Data/Serializers files to scan — the walk is broken.');
        $this->assertSame([], $offenders, 'Actions/Queries/Data/Serializers must not receive an Illuminate\Http\Request or FormRequest; HTTP input crosses the adapter boundary as a Data object or typed arguments (docs/internals/feature-modules.md, Key invariant 1).');
    }

    public function test_transactions_live_in_actions_not_controllers(): void
    {
        $controllers = $this->phpFilesUnder(app_path('Features'));
        $controllers = array_values(array_filter($controllers, fn (string $f): bool => str_ends_with($f, 'Controller.php')));

        $overBaseline = [];
        $belowBaseline = [];
        foreach ($controllers as $file) {
            $rel = $this->featureRelative($file);
            $count = substr_count(file_get_contents($file), 'DB::transaction(');
            $baseline = self::TRANSACTION_BASELINE[$rel] ?? 0;

            if ($count > $baseline) {
                $overBaseline[] = "{$rel} ({$count} > {$baseline})";
            } elseif (isset(self::TRANSACTION_BASELINE[$rel]) && $count < $baseline) {
                $belowBaseline[] = "{$rel} ({$count} < pinned {$baseline})";
            }
        }

        $this->assertNotEmpty($controllers, 'Found no feature controllers to scan — the walk is broken.');
        $this->assertSame([], $overBaseline, 'A feature controller must not own a DB::transaction — transactional side effects live in an Action (docs/internals/feature-modules.md, Key invariant 3). New occurrences over the pinned baseline are a regression.');
        $this->assertSame([], $belowBaseline, 'A baselined controller dropped below its pinned DB::transaction count — the burn-down landed; lower or remove its TRANSACTION_BASELINE entry to keep the baseline honest.');
    }

    public function test_no_cross_feature_controller_imports(): void
    {
        $files = $this->phpFilesUnder(app_path('Features'));

        $offenders = [];
        foreach ($files as $file) {
            $rel = $this->featureRelative($file);
            $ownFeature = explode('/', $rel, 2)[0];
            $contents = file_get_contents($file);

            // Matches `use App\Features\<Feature>\...<Class>;`, including an aliased `... as X;`.
            if (preg_match_all('/^\s*use\s+App\\\\Features\\\\([A-Za-z0-9_]+)\\\\([A-Za-z0-9_\\\\]+)\s*(?:as\s+[A-Za-z0-9_]+\s*)?;/m', $contents, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $importedFeature = $m[1];
                    $className = substr(strrchr('\\'.$m[2], '\\'), 1);
                    if (str_ends_with($className, 'Controller') && $importedFeature !== $ownFeature) {
                        $offenders[] = "{$rel} imports {$importedFeature}\\{$className}";
                    }
                }
            }
        }

        $this->assertNotEmpty($files, 'Found no feature files to scan — the walk is broken.');
        $this->assertSame([], $offenders, 'A feature must not import another feature\'s controller; surfaces stay thin and features do not reach across the controller boundary (docs/internals/feature-modules.md, Boundary rules).');
    }
}
