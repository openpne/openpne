<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\FileUploader;
use App\Files\ImageIntake;
use App\Files\ImageProcessor;
use App\Files\ImageSpec;
use App\Files\ProcessedImage;
use App\Http\Requests\Concerns\PostImageRules;
use App\Http\Requests\Member\AvatarRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** The upload rules and the stored type follow the processor's intake, not a list of their own. */
class ImageUploadRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_under_gd_a_heic_is_refused_by_the_rules_and_each_side_is_bounded(): void
    {
        $this->processorWith(ImageIntake::gd());
        $rule = PostImageRules::imageRule();

        $this->assertContains('mimetypes:image/jpeg,image/png,image/gif,image/webp', $rule);
        $this->assertTrue(collect($rule)->contains(fn ($r) => is_string($r) && str_starts_with($r, 'dimensions:')));
        $this->assertFalse(Validator::make(['images' => [$this->heic()]], PostImageRules::rules())->passes());
    }

    public function test_under_the_sidecar_a_heic_passes_the_rules_and_is_stored_as_its_canonical_type(): void
    {
        Storage::fake('image_cache');
        $this->processorWith(ImageIntake::imgproxy());
        $rule = PostImageRules::imageRule();

        $this->assertContains('mimetypes:image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif,image/avif', $rule);
        $this->assertFalse(collect($rule)->contains(fn ($r) => is_string($r) && str_starts_with($r, 'dimensions:')));
        $this->assertTrue(Validator::make(['images' => [$this->heic()]], PostImageRules::rules())->passes());

        $file = app(FileUploader::class)->store($this->heic());

        $this->assertSame('image/jpeg', $file->type);
        $this->assertSame('IMG_0001.heic', $file->original_filename);
        $this->assertSame(strlen($this->fixture('heic-gps-orientation.heic')), $file->byte_size);
    }

    public function test_the_type_is_read_from_the_bytes_not_the_name(): void
    {
        // A fake upload names its type after its extension, so the real uploader's sniff is what a
        // wrongly named file exercises: HEIC bytes called photo.jpg are still HEIC.
        Storage::fake('image_cache');
        $this->processorWith(ImageIntake::gd());
        $path = tempnam(sys_get_temp_dir(), 'heic');
        file_put_contents($path, $this->fixture('heic-gps-orientation.heic'));
        $upload = new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);

        try {
            $this->assertContains($upload->getMimeType(), ['image/heic', 'image/heif']);
            $this->assertFalse(Validator::make(['images' => [$upload]], PostImageRules::rules())->passes());
        } finally {
            @unlink($path);
        }
    }

    public function test_the_avatar_rule_keeps_required_outside_the_shared_rule(): void
    {
        $this->processorWith(ImageIntake::gd());

        $rules = (new AvatarRequest)->rules();

        $this->assertSame('required', $rules['image'][0]);
        $this->assertNotContains('nullable', $rules['image']);
        $this->assertContains('mimetypes:image/jpeg,image/png,image/gif,image/webp', $rules['image']);
    }

    /** A processor that reads what $intake says and answers every decode with a 1x1 JPEG. */
    private function processorWith(ImageIntake $intake): void
    {
        $this->app->instance(ImageProcessor::class, new class($intake) implements ImageProcessor
        {
            public function __construct(private readonly ImageIntake $intake) {}

            public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
            {
                $gd = imagecreatetruecolor(1, 1);
                ob_start();
                imagejpeg($gd);

                return new ProcessedImage((string) ob_get_clean(), 'image/jpeg', 1, 1, false);
            }

            public function preservesAnimation(): bool
            {
                return true;
            }

            public function intake(): ImageIntake
            {
                return $this->intake;
            }
        });
    }

    private function heic(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('IMG_0001.heic', $this->fixture('heic-gps-orientation.heic'));
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/images/'.$name));
    }
}
