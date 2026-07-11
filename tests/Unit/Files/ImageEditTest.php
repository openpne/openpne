<?php

declare(strict_types=1);

namespace Tests\Unit\Files;

use App\Files\ImageEdit;
use App\Files\PostImages;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

class ImageEditTest extends TestCase
{
    private function file(string $name = 'x.png'): UploadedFile
    {
        return UploadedFile::fake()->create($name);
    }

    public function test_a_non_array_images_payload_yields_no_additions_without_throwing(): void
    {
        // A malformed payload can make file() return a single UploadedFile, not an array.
        $request = Request::create('/', 'POST', [], [], ['images' => $this->file()]);

        $this->assertSame([], ImageEdit::fromRequest($request)->additions);
    }

    public function test_a_well_formed_images_payload_keeps_only_uploaded_files(): void
    {
        $request = Request::create('/', 'POST', [], [], ['images' => [$this->file('a.png'), $this->file('b.png')]]);

        $this->assertCount(2, ImageEdit::fromRequest($request)->additions);
    }

    public function test_duplicate_removal_ids_are_deduped(): void
    {
        $request = Request::create('/', 'POST', ['remove_images' => [5, 5, 6]]);

        $this->assertSame([5, 6], ImageEdit::fromRequest($request)->removals);
    }

    public function test_duplicate_removal_ids_cannot_slip_the_cap(): void
    {
        // Full post; a crafted [id, id] must not read as two freed slots and let two adds through.
        $edit = ImageEdit::of([$this->file('a.png'), $this->file('b.png')], [5, 5]);

        $this->assertTrue($edit->exceedsCap([5, 6, 7]));
    }

    public function test_removals_among_ignores_foreign_ids(): void
    {
        $edit = ImageEdit::of([], [2, 99]);

        $this->assertSame([2], $edit->removalsAmong([1, 2]));
    }

    public function test_exceeds_cap_boundary(): void
    {
        $currentIds = [1, 2];
        $removals = [1]; // keep one existing image

        // kept (1) + additions == MAX passes; one more fails.
        $atCap = ImageEdit::of(array_fill(0, PostImages::MAX_IMAGES - 1, $this->file()), $removals);
        $overCap = ImageEdit::of(array_fill(0, PostImages::MAX_IMAGES, $this->file()), $removals);

        $this->assertFalse($atCap->exceedsCap($currentIds));
        $this->assertTrue($overCap->exceedsCap($currentIds));
    }
}
