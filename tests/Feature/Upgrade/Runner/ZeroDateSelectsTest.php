<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Upgrade\Runner\ZeroDatePreflight;
use App\Upgrade\SourceSchema;
use App\Upgrade\StepRegistry;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

/** Every registry step's SELECT runs under the scan, over the whole fixture, so a step whose SELECT cannot stand alone shows here. */
class ZeroDateSelectsTest extends UpgradeSqlTestCase
{
    protected function sourceTables(): array
    {
        // file_bin is the app's own table too (moved, never a step's source), so it stays out.
        return array_values(array_diff(SourceSchema::default()->tables(), ['file_bin']));
    }

    public function test_every_registry_step_scans_clean_over_an_empty_source(): void
    {
        $this->assertSame([], (new ZeroDatePreflight(StepRegistry::all(), SourceSchema::default()))->inspect('', null, []));
    }
}
