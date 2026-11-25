<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\FileUploader;
use App\Files\ImageTooLargeException;
use App\Models\File;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Under the shipped side limit the pixel limit is the one that refuses; each entry point answers it as a field error, not a 500.
 */
class ImagePixelLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['openpne.images.processor' => 'gd', 'openpne.images.max_source_pixels' => 0, 'openpne.images.max_upload_dimension' => 5000]);
    }

    public function test_a_diary_picture_over_the_pixel_limit_is_a_field_error(): void
    {
        $this->actingAs(Member::factory()->create())
            ->post(route('diary.store'), ['title' => 't', 'body' => 'b', 'visibility' => Visibility::Members->value, 'images' => [$this->tooLarge()]], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0' => ImageTooLargeException::userMessage()]);

        $this->assertSame(0, File::count());
    }

    public function test_a_timeline_picture_over_the_pixel_limit_is_a_field_error(): void
    {
        $this->actingAs(Member::factory()->create())
            ->post(route('timeline.store'), ['body' => 'b', 'visibility' => Visibility::Members->value, 'image' => $this->tooLarge()], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image' => ImageTooLargeException::userMessage()]);
    }

    public function test_an_avatar_over_the_pixel_limit_is_a_field_error(): void
    {
        $this->actingAs(Member::factory()->create())
            ->post(route('member.avatar.update'), ['image' => $this->tooLarge()], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image' => ImageTooLargeException::userMessage()]);
    }

    public function test_the_uploader_the_admin_pages_call_refuses_it_as_too_large(): void
    {
        // Livewire's temporary upload is not drivable here, so this stops at the uploader the admin pages catch around.
        $this->expectException(ImageTooLargeException::class);

        try {
            app(FileUploader::class)->store($this->tooLarge(), explicitVisibility: File::VISIBILITY_PUBLIC);
        } finally {
            $this->assertSame(0, File::count());
        }
    }

    /** Within the 5000 px side limit, 4000 x 4000 counts as 4096 x 4000, over the 12.6 M default. */
    private function tooLarge(): UploadedFile
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NN', 4000, 4000)."\x08\x02\x00\x00\x00").$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('big.png', $png);
    }
}
