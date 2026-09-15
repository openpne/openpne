<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\File;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForgetStillVerdictsOnGifAndWebpFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_a_still_verdict_on_a_format_that_may_animate_goes_back_to_unknown(): void
    {
        $rows = [
            'gif still' => ['image/gif', false, null],
            'webp still' => ['image/webp', false, null],
            'gif animated' => ['image/gif', true, true],
            'webp animated' => ['image/webp', true, true],
            'png still' => ['image/png', false, false],
            'jpeg unknown' => ['image/jpeg', null, null],
        ];
        $files = array_map(fn (array $row): File => File::factory()->create(['type' => $row[0], 'animated' => $row[1]]), $rows);

        (require database_path('migrations/2026_09_15_000001_forget_still_verdicts_on_gif_and_webp_files.php'))->up();

        $this->assertSame(array_map(fn (array $row): ?bool => $row[2], $rows), array_map(fn (File $file): ?bool => $file->refresh()->animated, $files));
    }
}
