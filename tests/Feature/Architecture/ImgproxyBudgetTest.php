<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Files\ImageIntake;
use Tests\TestCase;

/**
 * The app applies the sidecar's pixel budget itself, so the budget the shipped sidecars state has to be
 * the one the code assumes (docs/internals/images.md, "Upload size").
 */
class ImgproxyBudgetTest extends TestCase
{
    public function test_the_shipped_sidecars_state_the_budget_the_app_assumes(): void
    {
        $budget = (string) ImageIntake::SIDECAR_MEGAPIXELS;

        $this->assertStringContainsString('IMGPROXY_MAX_SRC_RESOLUTION: "'.$budget.'"', (string) file_get_contents(base_path('docker-compose.yml')));
        $this->assertStringContainsString("IMGPROXY_MAX_SRC_RESOLUTION: '".$budget."'", (string) file_get_contents(base_path('.github/workflows/ci.yml')));
    }
}
