<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Files\ImageCache;
use App\Files\ImageTransform;
use App\Mcp\McpAbilities;
use App\Mcp\Servers\OpenPneServer;
use App\Mcp\Tools\PostDiaryTool;
use App\Mcp\Tools\ReadDiaryImagesTool;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryCommentImage;
use App\Models\DiaryImage;
use App\Models\File;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

abstract class DiaryImageToolsTestCase extends McpTestCase
{
    /** A copy of the trait's own cap, which is private to it. */
    protected const CAP = 8 * 1024 * 1024;

    /** Written out rather than recomputed: four characters per three bytes of the shipped 5120 KB cap. */
    protected const MAX_ENCODED = 6990508;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('image_cache');
    }

    protected function acting(Member $member, array $abilities = [McpAbilities::READ, McpAbilities::WRITE]): Member
    {
        return Sanctum::actingAs($member, $abilities);
    }

    protected function diary(Member $author, Visibility $visibility = Visibility::Members): Diary
    {
        return Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => $visibility]);
    }

    protected function comment(Diary $diary, ?Member $author = null): DiaryComment
    {
        return DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(),
            'member_id' => ($author ?? Member::factory()->create())->getKey(),
        ]);
    }

    protected function attach(Diary $diary, int $number, int $width = 800, int $height = 400): File
    {
        $file = $this->store('diary', (int) $diary->getKey(), $width, $height);

        DiaryImage::factory()->create([
            'diary_id' => $diary->getKey(),
            'file_id' => $file->getKey(),
            'number' => $number,
        ]);

        return $file;
    }

    protected function attachToComment(DiaryComment $comment, int $width = 800, int $height = 400): File
    {
        return $this->link($comment, $this->store('diaryComment', (int) $comment->getKey(), $width, $height));
    }

    protected function link(DiaryComment $comment, File $file): File
    {
        DiaryCommentImage::factory()->create([
            'diary_comment_id' => $comment->getKey(),
            'file_id' => $file->getKey(),
        ]);

        return $file;
    }

    protected function store(string $relatedType, int $relatedId, int $width, int $height): File
    {
        return app(FileUploader::class)->store(
            UploadedFile::fake()->image('shot.png', $width, $height),
            $relatedType,
            $relatedId,
        );
    }

    /** How many picture blocks the call returned; the harness exposes only text search, so this reads the RPC result. */
    protected function imageBlocks(TestResponse $response): int
    {
        $content = (fn (): array => $this->response->toArray()['result']['content'] ?? [])->call($response);

        return count(array_filter($content, fn (array $block): bool => ($block['type'] ?? null) === 'image'));
    }

    /** A slot whose bytes were written straight to storage, as an upgraded OpenPNE 3 row is. */
    protected function attachStoredBytes(Diary $diary, int $number, string $bytes): File
    {
        $file = File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'diary',
            'related_entity_id' => $diary->getKey(),
            'byte_size' => strlen($bytes),
        ]);
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $file->getKey(), 'number' => $number]);

        return $file;
    }

    protected function notAPicture(string $type, int $id): File
    {
        return File::factory()->create([
            'type' => 'application/pdf',
            'related_entity_type' => $type,
            'related_entity_id' => $id,
        ]);
    }

    /** The canonical off the same cache the tool reads: what `size=original` answers, never the stored bytes. */
    protected function original(File $file): string
    {
        return app(ImageCache::class)->canonical($file);
    }

    /** The 640px variant off the same cache the tool reads. */
    protected function thumbnail(File $file): string
    {
        return app(ImageCache::class)->bytes($file, ImageTransform::fromGeometry('w640_h640'), (string) $file->imageFormat());
    }

    /** Image content travels base64-encoded, so this is the exact bytes appearing on the wire. */
    protected function wire(string $bytes): string
    {
        return base64_encode($bytes);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function read(array $arguments): TestResponse
    {
        return OpenPneServer::tool(ReadDiaryImagesTool::class, $arguments);
    }

    protected function encodedImage(int $width = 40, int $height = 30): string
    {
        // Held in a variable: a fake upload deletes its temporary file with the object.
        $image = UploadedFile::fake()->image('shot.png', $width, $height);

        return base64_encode((string) file_get_contents((string) $image->getRealPath()));
    }

    protected function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/images/{$name}"));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function postDiary(array $arguments): TestResponse
    {
        return OpenPneServer::tool(PostDiaryTool::class, [
            'title' => 'With pictures',
            'body' => 'See attached.',
            'visibility' => 'private',
            ...$arguments,
        ]);
    }
}
