<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Files\GdImageProcessor;
use App\Files\ImageIntake;
use App\Files\ImageProcessor;
use App\Files\ImageSpec;
use App\Files\ProcessedImage;
use App\Models\Member;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/** Both surfaces take their `<input accept>` from the processor, so a picker never offers what the server refuses. */
class ImageUploadAcceptPropTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shared_prop_and_the_helper_follow_the_processor(): void
    {
        $member = Member::factory()->create();
        $this->app->instance(ImageProcessor::class, new GdImageProcessor(new ImageManager(GdDriver::class, decodeAnimation: false)));

        $this->assertSame('image/jpeg,image/png,image/gif,image/webp', image_upload_accept());
        $this->actingAs($member)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('imageUpload.accept', 'image/jpeg,image/png,image/gif,image/webp'));

        $this->app->instance(ImageProcessor::class, $this->sidecarLike());

        $this->assertStringContainsString('image/heic', image_upload_accept());
        $this->assertStringContainsString('.avif', image_upload_accept());
        $this->actingAs($member)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('imageUpload.accept', ImageIntake::imgproxy()->accept()));
    }

    public function test_the_shared_prop_carries_the_shrink_thresholds_until_the_site_switches_them_off(): void
    {
        $member = Member::factory()->create();
        config(['openpne.images.browser_shrink' => ['max_edge' => 1600, 'passthrough_kb' => 1024, 'jpeg_quality' => 75]]);

        $this->app->instance(ImageProcessor::class, new GdImageProcessor(new ImageManager(GdDriver::class, decodeAnimation: false)));
        $this->actingAs($member)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('imageUpload.shrink', ['maxEdge' => 1600, 'passthroughBytes' => 1048576, 'maxBytes' => 5242880, 'quality' => 0.75, 'keepsFrames' => false]));

        // A threshold above the upload rules' own would send a picture those rules then refuse.
        config(['openpne.images.max_upload_kilobytes' => 512, 'openpne.images.max_upload_dimension' => 1200]);
        $this->app->instance(ImageProcessor::class, $this->sidecarLike());
        $this->actingAs($member)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('imageUpload.shrink', ['maxEdge' => 1200, 'passthroughBytes' => 524288, 'maxBytes' => 524288, 'quality' => 0.75, 'keepsFrames' => true]));

        DB::table('sns_settings')->updateOrInsert(['key' => SnsSettingKey::ImageUploadBrowserShrink->value], ['value' => '0']);
        app(SnsSettingService::class)->clearCache();

        $this->actingAs($member)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('imageUpload.shrink', null));
    }

    public function test_a_classic_form_renders_the_same_list(): void
    {
        $member = Member::factory()->create();
        $this->app->instance(ImageProcessor::class, $this->sidecarLike());

        $this->actingAs($member)->get('/member/avatar')->assertOk()->assertSee('accept="'.ImageIntake::imgproxy()->accept().'"', false);
    }

    private function sidecarLike(): ImageProcessor
    {
        return new class implements ImageProcessor
        {
            public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
            {
                throw new \LogicException('not decoded here');
            }

            public function preservesAnimation(): bool
            {
                return true;
            }

            public function intake(): ImageIntake
            {
                return ImageIntake::imgproxy();
            }
        };
    }
}
