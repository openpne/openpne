<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Files\DiskFileStorage;
use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Mcp\Servers\OpenPneServer;
use App\Mcp\Tools\PostDiaryCommentTool;
use App\Mcp\Tools\ReadDiaryTool;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryImage;
use App\Models\File;
use App\Models\Member;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;

class DiaryImagePostToolTest extends DiaryImageToolsTestCase
{
    public function test_posting_an_entry_with_pictures_stores_them_numbered_and_reads_them_back(): void
    {
        $member = Member::factory()->create();
        $this->acting($member);

        // The temporary file a decoded picture is written to lives exactly as long as the write
        // needs it: readable where the bytes are stored, gone once the call is answered.
        $paths = [];
        $this->recordUploads($paths);

        $this->postDiary(['images' => [$this->encodedImage(40, 30), $this->encodedImage(20, 20)]])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('diary.imageCount', 2)->etc());

        $diary = Diary::query()->sole();
        $this->assertSame([1, 2], $diary->images()->pluck('number')->all());

        $file = $diary->images()->with('file')->first()->file;
        $this->assertSame('diary', $file->related_entity_type);
        $this->assertSame($diary->getKey(), $file->related_entity_id);
        $this->assertSame('upload', $file->original_filename);

        $this->read(['diary_id' => $diary->getKey(), 'size' => 'original'])
            ->assertOk()
            ->assertSee($this->wire($this->original($file)))
            ->assertStructuredContent(fn ($json) => $json
                ->count('images', 2)
                ->where('images.0.width', 40)
                ->where('images.0.height', 30)
                ->etc());

        $this->assertCount(2, $paths);
        foreach ($paths as $path) {
            $this->assertFileDoesNotExist($path, 'a decoded picture outlived the call it was posted in');
        }
    }

    public function test_commenting_with_pictures_stores_them_and_reads_them_back(): void
    {
        Notification::fake();

        $diary = $this->diary(Member::factory()->create());
        $bot = Member::factory()->aiAccount()->create();
        $this->acting($bot);

        OpenPneServer::tool(PostDiaryCommentTool::class, [
            'diary_id' => $diary->getKey(),
            'body' => 'here is what I saw',
            'images' => [$this->encodedImage(40, 30)],
        ])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('comment.imageCount', 1)->etc());

        $comment = DiaryComment::query()->sole();
        $file = $comment->images()->with('file')->sole()->file;
        $this->assertSame('diaryComment', $file->related_entity_type);
        $this->assertSame($comment->getKey(), $file->related_entity_id);

        OpenPneServer::tool(ReadDiaryTool::class, ['diary_id' => $diary->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('diary.comments.0.imageCount', 1)->etc());

        $this->read(['diary_id' => $diary->getKey(), 'comment_id' => $comment->getKey(), 'size' => 'original'])
            ->assertOk()
            ->assertSee($this->wire($this->original($file)))
            ->assertStructuredContent(fn ($json) => $json->where('images.0.number', 1)->etc());
    }

    public function test_a_posted_picture_is_answered_without_its_metadata_like_any_other(): void
    {
        $original = $this->fixture('jpeg-gps-orientation.jpg');

        $this->acting(Member::factory()->create());

        $this->postDiary(['images' => [base64_encode($original)]])->assertOk();

        $file = Diary::query()->sole()->images()->with('file')->sole()->file;

        $this->assertStringNotContainsString('2021:07:04', $this->original($file), 'the canonical carries no GPS');
        $this->assertSame([6, 12], [$file->width, $file->height]);
    }

    public function test_a_fourth_picture_is_refused(): void
    {
        $this->acting(Member::factory()->create());
        $this->app->setLocale('en');

        $image = $this->encodedImage(20, 20);

        $this->postDiary(['images' => [$image, $image, $image, $image]])->assertHasErrors(['images']);

        $this->assertSame(0, Diary::query()->count());
        $this->assertSame(0, File::query()->count());
    }

    /** Line breaks are skipped by the decoder, so at the bound this is still one small picture. */
    public function test_a_picture_longer_than_a_picture_may_be_is_refused_before_it_is_decoded(): void
    {
        $this->acting(Member::factory()->create());
        $this->app->setLocale('en');

        $image = $this->encodedImage(20, 20);
        $atTheBound = str_repeat("\n", self::MAX_ENCODED - strlen($image)).$image;

        $this->postDiary(['images' => [$atTheBound]])->assertOk();

        $this->assertSame(1, DiaryImage::query()->count());

        $this->postDiary(['images' => ["\n".$atTheBound]])->assertHasErrors(['at most 5120 KB, which is '.self::MAX_ENCODED.' base64 characters']);

        $this->assertSame(1, Diary::query()->count());
        $this->assertSame(1, DiaryImage::query()->count());
    }

    public function test_the_pre_decode_bound_follows_the_configured_cap(): void
    {
        $this->acting(Member::factory()->create());
        $this->app->setLocale('en');
        config()->set('openpne.images.max_upload_kilobytes', 1);

        // 1 KB encodes to intdiv(1024 + 2, 3) * 4 = 1368 characters; one more is refused unread.
        $this->postDiary(['images' => [str_repeat('A', 1369)]])
            ->assertHasErrors(['at most 1 KB, which is 1368 base64 characters']);

        $this->assertSame(0, Diary::query()->count());
    }

    /** A string at the encoded bound can still decode to a single byte over the shipped cap. */
    public function test_a_picture_over_the_size_cap_is_refused_by_the_rule_that_measures_it(): void
    {
        $this->acting(Member::factory()->create());
        $this->app->setLocale('en');

        $encoded = base64_encode(str_repeat('a', 5 * 1024 * 1024 + 1));
        $this->assertSame(self::MAX_ENCODED, strlen($encoded), 'the payload must reach the tool to be measured');

        $this->postDiary(['images' => [$encoded]])->assertHasErrors(['kilobytes']);

        $this->assertSame(0, Diary::query()->count());
        $this->assertSame(0, File::query()->count());
    }

    public function test_bytes_that_are_not_a_picture_are_refused(): void
    {
        $this->acting(Member::factory()->create());
        $this->app->setLocale('en');

        $refused = [
            base64_encode('just some text, not a picture at all'),
            base64_encode("%PDF-1.4\n1 0 obj\n<< >>\nendobj\ntrailer\n%%EOF\n"),
        ];

        foreach ($refused as $encoded) {
            $this->postDiary(['images' => [$encoded]])->assertHasErrors(['images.0']);
        }

        $this->assertSame(0, Diary::query()->count());
        $this->assertSame(0, File::query()->count());
    }

    public function test_anything_but_standard_base64_is_refused(): void
    {
        $this->acting(Member::factory()->create());
        $this->app->setLocale('en');

        $image = $this->encodedImage(20, 20);

        // A committed fixture rather than a generated one, so the two characters url-safe base64
        // substitutes are certainly in it.
        $gif = base64_encode($this->fixture('tiny.gif'));
        $this->assertNotFalse(strpbrk($gif, '+/'), 'the fixture must exercise the url-safe substitutions');

        $refused = [
            'data:image/png;base64,'.$image,
            strtr($gif, '+/', '-_'),
            'not base64 at all!',
        ];

        foreach ($refused as $encoded) {
            $this->postDiary(['images' => [$encoded]])->assertHasErrors(['standard base64']);
        }

        $this->assertSame(0, Diary::query()->count());

        // Wrapped at 76 characters, as a client encoding a file for mail would send it.
        $this->postDiary(['images' => [chunk_split($image, 76)]])->assertOk();

        $this->assertSame(1, DiaryImage::query()->count());
    }

    public function test_an_images_argument_that_is_not_a_list_of_strings_is_refused(): void
    {
        $this->acting(Member::factory()->create());
        $this->app->setLocale('en');

        $image = $this->encodedImage(20, 20);

        foreach ([$image, 42, ['first' => $image], [$image, 42], [[$image]]] as $images) {
            $this->postDiary(['images' => $images])->assertHasErrors(['images']);
        }

        $this->assertSame(0, Diary::query()->count());
        $this->assertSame(0, File::query()->count());

        // Nothing sent, and nothing sent as null, are both an entry without pictures.
        $this->postDiary([])->assertOk();
        $this->postDiary(['images' => null])->assertOk();
        $this->postDiary(['images' => []])->assertOk();

        $this->assertSame(0, DiaryImage::query()->count());
    }

    public function test_a_picture_the_processor_refuses_is_an_error_on_that_picture(): void
    {
        $this->acting(Member::factory()->create());
        $this->app->setLocale('en');

        // A picture the rules pass and the source cap, set under them, refuses at the header check both
        // processors share; the first picture stays under that cap.
        config(['openpne.images.max_source_kilobytes' => 1]);
        $this->assertLessThan(1024, strlen(base64_decode($this->encodedImage(20, 20))));
        $big = base64_decode($this->encodedImage(20, 20)).str_repeat("\0", 2048);

        $this->postDiary(['images' => [$this->encodedImage(20, 20), base64_encode($big)]])
            ->assertHasErrors(['image']);

        $this->assertSame(0, Diary::query()->count());
        $this->assertSame(0, File::query()->count());
        $this->assertSame(0, DiaryImage::query()->count());
    }

    public function test_a_failed_second_picture_leaves_neither_bytes_nor_rows_behind(): void
    {
        config(['openpne.files.disk' => 'local']);
        Storage::fake('local');

        $real = new DiskFileStorage('local');
        $writes = 0;
        $this->instance(FileStorage::class, Mockery::mock(FileStorage::class, function ($mock) use ($real, &$writes) {
            $mock->shouldReceive('writeStream')->andReturnUsing(function ($file, $stream) use ($real, &$writes) {
                $writes++;
                if ($writes === 2) {
                    throw new RuntimeException('disk full');
                }
                $real->writeStream($file, $stream);
            });
            $mock->shouldReceive('delete')->andReturnUsing(fn ($file) => $real->delete($file));
            $mock->shouldReceive('readStream')->andReturnUsing(fn ($file) => $real->readStream($file));
            $mock->shouldReceive('exists')->andReturnUsing(fn ($file) => $real->exists($file));
        }));

        $this->acting(Member::factory()->create());

        $paths = [];
        $this->recordUploads($paths);

        $this->postDiary(['images' => [$this->encodedImage(40, 30), $this->encodedImage(20, 20)]])
            ->assertHasErrors([]);

        $this->assertSame(0, Diary::query()->count());
        $this->assertSame(0, File::query()->count());
        $this->assertSame(0, DiaryImage::query()->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());

        $this->assertCount(2, $paths);
        foreach ($paths as $path) {
            $this->assertFileDoesNotExist($path, 'a decoded picture outlived the call it was posted in');
        }
    }

    /**
     * Records the temporary file behind every upload and asserts, while it is being stored, that it
     * is still there — the other half of "gone afterwards".
     *
     * @param  array<int, string>  $paths
     */
    private function recordUploads(array &$paths): void
    {
        $real = app(FileUploader::class);

        $this->instance(FileUploader::class, Mockery::mock(FileUploader::class, function ($mock) use ($real, &$paths) {
            $mock->shouldReceive('store')->andReturnUsing(function (...$arguments) use ($real, &$paths) {
                $path = $arguments[0]->getPathname();
                $paths[] = $path;
                $this->assertFileExists($path, 'the decoded picture was gone before it could be stored');

                return $real->store(...$arguments);
            });
        }));
    }
}
