<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\ImageIntake;
use App\Files\ImageProcessor;
use App\Files\ImageSpec;
use App\Files\ProcessedImage;
use App\Http\Requests\Concerns\PostImageRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/** A post's `images[]` slot takes a picture the processor reads, or any other file under its own cap (docs/internals/attachments.md, "Validation"). */
class AttachmentRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->processorWith(ImageIntake::gd());
    }

    public function test_a_document_under_the_cap_passes_as_a_file(): void
    {
        $this->assertTrue($this->passes([UploadedFile::fake()->create('report.pdf', 2048, 'application/pdf')]));
        $this->assertTrue($this->passes([UploadedFile::fake()->create('scan.tif', 10, 'image/tiff')]));
        $this->assertTrue($this->passes([UploadedFile::fake()->create('empty.txt', 0, 'text/plain')]));
        // No extension gate: what the browser does with a download is the member's call, not the site's.
        $this->assertTrue($this->passes([UploadedFile::fake()->create('setup.exe', 10, 'application/octet-stream')]));
    }

    public function test_an_svg_is_a_file_not_a_picture(): void
    {
        // Served as an attachment with nosniff, never inline, so its script never runs here.
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->assertStringStartsWith('image/svg', (string) $svg->getMimeType());
        $this->assertTrue($this->passes([$svg]));
    }

    public function test_a_file_over_the_attachment_cap_is_refused_by_size(): void
    {
        config(['openpne.files.max_upload_kilobytes' => 100]);
        $validator = Validator::make(['images' => [UploadedFile::fake()->create('big.pdf', 101, 'application/pdf')]], PostImageRules::rules(), [], PostImageRules::attributes());

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('100', $validator->errors()->first('images.0'));
        $this->assertTrue($this->passes([UploadedFile::fake()->create('fits.pdf', 100, 'application/pdf')]));
    }

    public function test_a_picture_keeps_the_picture_rules_and_a_sidecar_only_picture_is_refused_under_gd(): void
    {
        config(['openpne.images.max_upload_kilobytes' => 1, 'openpne.files.max_upload_kilobytes' => 10240]);
        $heic = UploadedFile::fake()->createWithContent('IMG_0001.heic', (string) file_get_contents(base_path('tests/Fixtures/images/heic-gps-orientation.heic')));

        // A readable picture is bound by the picture cap, not the larger file cap.
        $this->assertFalse($this->passes([UploadedFile::fake()->createWithContent('big.png', $this->noisyPng())]));
        // A HEIC is a picture this processor cannot read: refused, not turned into a download.
        $this->assertFalse($this->passes([$heic]));
    }

    public function test_the_picture_only_shape_still_refuses_a_document(): void
    {
        $this->assertFalse(Validator::make(['images' => [UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')]], PostImageRules::imagesOnly())->passes());
    }

    /** A PNG well over 1 KB: random pixels defeat the compression a flat fake image enjoys. */
    private function noisyPng(): string
    {
        $gd = imagecreatetruecolor(64, 64);
        mt_srand(7);
        for ($x = 0; $x < 64; $x++) {
            for ($y = 0; $y < 64; $y++) {
                imagesetpixel($gd, $x, $y, imagecolorallocate($gd, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
            }
        }
        ob_start();
        imagepng($gd);

        return (string) ob_get_clean();
    }

    /** @param  list<UploadedFile>  $uploads */
    private function passes(array $uploads): bool
    {
        return Validator::make(['images' => $uploads], PostImageRules::rules())->passes();
    }

    private function processorWith(ImageIntake $intake): void
    {
        $this->app->instance(ImageProcessor::class, new class($intake) implements ImageProcessor
        {
            public function __construct(private readonly ImageIntake $intake) {}

            public function intake(): ImageIntake
            {
                return $this->intake;
            }

            public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
            {
                return new ProcessedImage($bytes, $mime, 1, 1, false);
            }

            public function preservesAnimation(): bool
            {
                return false;
            }
        });
    }
}
