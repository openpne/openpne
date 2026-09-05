<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Files\DiskFileStorage;
use App\Files\FileStorage;
use App\Files\FileUploader;
use App\Files\ImageCache;
use App\Files\ImageTransform;
use App\Mcp\McpAbilities;
use App\Mcp\Servers\OpenPneServer;
use App\Mcp\Tools\PostDiaryCommentTool;
use App\Mcp\Tools\PostDiaryTool;
use App\Mcp\Tools\ReadDiaryImagesTool;
use App\Mcp\Tools\ReadDiaryTool;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryCommentImage;
use App\Models\DiaryImage;
use App\Models\File;
use App\Models\Member;
use App\Support\Feature;
use App\Support\Visibility;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Tests\Fixtures\CountedByteStream;
use Tests\Fixtures\CountingFileStorage;

class DiaryImageToolsTest extends McpTestCase
{
    /** A copy of the trait's own cap, which is private to it. */
    private const CAP = 8 * 1024 * 1024;

    /** Written out rather than recomputed: four characters per three bytes of the shipped 5120 KB cap. */
    private const MAX_ENCODED = 6990508;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('image_cache');
    }

    private function acting(Member $member, array $abilities = [McpAbilities::READ, McpAbilities::WRITE]): Member
    {
        return Sanctum::actingAs($member, $abilities);
    }

    private function diary(Member $author, Visibility $visibility = Visibility::Members): Diary
    {
        return Diary::factory()->create(['member_id' => $author->getKey(), 'visibility' => $visibility]);
    }

    private function comment(Diary $diary, ?Member $author = null): DiaryComment
    {
        return DiaryComment::factory()->create([
            'diary_id' => $diary->getKey(),
            'member_id' => ($author ?? Member::factory()->create())->getKey(),
        ]);
    }

    private function attach(Diary $diary, int $number, int $width = 800, int $height = 400): File
    {
        $file = $this->store('diary', (int) $diary->getKey(), $width, $height);

        DiaryImage::factory()->create([
            'diary_id' => $diary->getKey(),
            'file_id' => $file->getKey(),
            'number' => $number,
        ]);

        return $file;
    }

    private function attachToComment(DiaryComment $comment, int $width = 800, int $height = 400): File
    {
        return $this->link($comment, $this->store('diaryComment', (int) $comment->getKey(), $width, $height));
    }

    private function link(DiaryComment $comment, File $file): File
    {
        DiaryCommentImage::factory()->create([
            'diary_comment_id' => $comment->getKey(),
            'file_id' => $file->getKey(),
        ]);

        return $file;
    }

    private function store(string $relatedType, int $relatedId, int $width, int $height): File
    {
        return app(FileUploader::class)->store(
            UploadedFile::fake()->image('shot.png', $width, $height),
            $relatedType,
            $relatedId,
        );
    }

    private function notAPicture(string $type, int $id): File
    {
        return File::factory()->create([
            'type' => 'application/pdf',
            'related_entity_type' => $type,
            'related_entity_id' => $id,
        ]);
    }

    private function stored(File $file): string
    {
        $stream = app(FileStorage::class)->readStream($file);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);

        return $bytes;
    }

    /** The 640px variant off the same cache the tool reads. */
    private function thumbnail(File $file): string
    {
        return app(ImageCache::class)->bytes($file, ImageTransform::fromGeometry('w640_h640'), (string) $file->imageFormat());
    }

    /** Image content travels base64-encoded, so this is the exact bytes appearing on the wire. */
    private function wire(string $bytes): string
    {
        return base64_encode($bytes);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function read(array $arguments): TestResponse
    {
        return OpenPneServer::tool(ReadDiaryImagesTool::class, $arguments);
    }

    private function encodedImage(int $width = 40, int $height = 30): string
    {
        // Held in a variable: a fake upload deletes its temporary file with the object.
        $image = UploadedFile::fake()->image('shot.png', $width, $height);

        return base64_encode((string) file_get_contents((string) $image->getRealPath()));
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/images/{$name}"));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function postDiary(array $arguments): TestResponse
    {
        return OpenPneServer::tool(PostDiaryTool::class, [
            'title' => 'With pictures',
            'body' => 'See attached.',
            'visibility' => 'private',
            ...$arguments,
        ]);
    }

    public function test_an_entrys_pictures_come_back_as_thumbnails_with_the_shape_they_were_returned_at(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diary($author);
        $first = $this->attach($diary, 1, 800, 400);
        $second = $this->attach($diary, 2, 400, 800);

        $this->acting(Member::factory()->create());

        $this->read(['diary_id' => $diary->getKey()])
            ->assertOk()
            ->assertSee($this->wire($this->thumbnail($first)))
            ->assertSee($this->wire($this->thumbnail($second)))
            // Fitted into the 640 box, so the reported size is the thumbnail's and not the source's.
            ->assertStructuredContent(['images' => [
                ['number' => 1, 'width' => 640, 'height' => 320, 'mimeType' => 'image/png', 'byteSize' => strlen($this->thumbnail($first))],
                ['number' => 2, 'width' => 320, 'height' => 640, 'mimeType' => 'image/png', 'byteSize' => strlen($this->thumbnail($second))],
            ]]);
    }

    public function test_the_original_size_answers_with_the_stored_bytes_untouched(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diary($author);
        $file = $this->attach($diary, 1, 800, 400);
        $stored = $this->stored($file);

        $this->acting($author);

        $this->read(['diary_id' => $diary->getKey(), 'size' => 'original'])
            ->assertOk()
            ->assertSee($this->wire($stored))
            ->assertStructuredContent(['images' => [[
                'number' => 1,
                'width' => 800,
                'height' => 400,
                'mimeType' => 'image/png',
                'byteSize' => strlen($stored),
            ]]]);

        $this->assertSame($file->byte_size, strlen($stored));
    }

    public function test_naming_a_slot_answers_with_that_picture_alone(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diary($author);
        $first = $this->attach($diary, 1, 800, 400);
        $second = $this->attach($diary, 2, 400, 800);

        $this->acting(Member::factory()->create());

        $this->read(['diary_id' => $diary->getKey(), 'size' => 'original', 'number' => 2])
            ->assertOk()
            ->assertSee($this->wire($this->stored($second)))
            ->assertDontSee($this->wire($this->stored($first)))
            ->assertStructuredContent(fn ($json) => $json
                ->count('images', 1)
                ->where('images.0.number', 2)
                ->where('images.0.width', 400)
                ->etc());
    }

    public function test_an_entry_without_pictures_answers_with_none_rather_than_a_refusal(): void
    {
        $diary = $this->diary(Member::factory()->create());

        $this->acting(Member::factory()->create());

        $this->read(['diary_id' => $diary->getKey()])
            ->assertOk()
            ->assertStructuredContent(['images' => []]);
    }

    /** The rows here start well past 1, so a tool reading `number` as a row id answers the wrong picture. */
    public function test_a_comments_pictures_are_numbered_by_position_and_never_by_row_id(): void
    {
        // A comment elsewhere, so the rows under test are not the first ones written.
        $decoy = $this->comment($this->diary(Member::factory()->create()));
        $this->attachToComment($decoy);
        $this->attachToComment($decoy);

        $author = Member::factory()->create();
        $diary = $this->diary($author);
        $comment = $this->comment($diary);
        $first = $this->attachToComment($comment, 800, 400);
        $second = $this->attachToComment($comment, 400, 800);

        // Not exact ids: MySQL's auto-increment does not rewind on the per-test rollback, so only the
        // decoy's two rows preceding these is guaranteed.
        $this->assertGreaterThan(2, min($comment->images()->pluck('id')->all()), 'the fixture must not number rows 1..N');

        $this->acting(Member::factory()->create());

        $this->read(['diary_id' => $diary->getKey(), 'comment_id' => $comment->getKey(), 'size' => 'original'])
            ->assertOk()
            ->assertSee($this->wire($this->stored($first)))
            ->assertSee($this->wire($this->stored($second)))
            ->assertStructuredContent(fn ($json) => $json
                ->count('images', 2)
                ->where('images.0.number', 1)
                ->where('images.0.width', 800)
                ->where('images.1.number', 2)
                ->where('images.1.width', 400)
                ->etc());

        $this->read(['diary_id' => $diary->getKey(), 'comment_id' => $comment->getKey(), 'size' => 'original', 'number' => 1])
            ->assertOk()
            ->assertSee($this->wire($this->stored($first)))
            ->assertDontSee($this->wire($this->stored($second)));

        // The row ids of those same two pictures, which are positions this comment does not have.
        foreach ([3, 4] as $rowId) {
            $this->read([
                'diary_id' => $diary->getKey(),
                'comment_id' => $comment->getKey(),
                'size' => 'original',
                'number' => $rowId,
            ])
                ->assertHasErrors(['No such diary'])
                ->assertDontSee($this->wire($this->stored($first)))
                ->assertDontSee($this->wire($this->stored($second)));
        }
    }

    /** Both entries here are readable, so a global lookup would answer with the other one's picture. */
    public function test_a_comment_of_another_entry_is_no_more_findable_than_one_that_is_not_there(): void
    {
        $author = Member::factory()->create();
        $mine = $this->diary($author);

        $elsewhere = $this->diary(Member::factory()->create());
        $strayed = $this->comment($elsewhere);
        $secret = $this->attachToComment($strayed);

        $this->acting(Member::factory()->create());

        $this->read(['diary_id' => $elsewhere->getKey(), 'comment_id' => $strayed->getKey(), 'size' => 'original'])
            ->assertOk()
            ->assertSee($this->wire($this->stored($secret)));

        foreach ([$strayed->getKey(), $strayed->getKey() + 9999] as $commentId) {
            $this->read(['diary_id' => $mine->getKey(), 'comment_id' => $commentId, 'size' => 'original'])
                ->assertHasErrors(['No such diary'])
                ->assertDontSee($this->wire($this->stored($secret)));
        }
    }

    public function test_an_entry_the_caller_may_not_read_never_yields_its_pictures(): void
    {
        $stranger = Member::factory()->create();
        $hidden = $this->diary($stranger, Visibility::Private);
        $secret = $this->attach($hidden, 1);
        $comment = $this->comment($hidden);
        $alsoSecret = $this->attachToComment($comment);

        $this->acting(Member::factory()->create());

        $refusals = [
            ['diary_id' => $hidden->getKey()],
            ['diary_id' => $hidden->getKey(), 'comment_id' => $comment->getKey()],
            ['diary_id' => $hidden->getKey() + 9999],
        ];

        foreach ($refusals as $arguments) {
            $this->read([...$arguments, 'size' => 'original'])
                ->assertHasErrors(['No such diary'])
                ->assertDontSee([$this->wire($this->stored($secret)), $this->wire($this->stored($alsoSecret))]);
        }
    }

    /** Dropping the middle row from the numbering would let a caller tell "not a picture" from "no picture at all". */
    public function test_a_number_that_holds_no_picture_is_refused_when_named_and_passed_over_when_not(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diary($author);
        $this->attach($diary, 1, 800, 400);
        DiaryImage::factory()->create([
            'diary_id' => $diary->getKey(),
            'file_id' => $this->notAPicture('diary', (int) $diary->getKey())->getKey(),
            'number' => 2,
        ]);
        $this->attach($diary, 3, 200, 100);

        $comment = $this->comment($diary);
        $this->attachToComment($comment, 800, 400);
        $this->link($comment, $this->notAPicture('diaryComment', (int) $comment->getKey()));
        $this->attachToComment($comment, 200, 100);

        $this->acting(Member::factory()->create());

        foreach ([[], ['comment_id' => $comment->getKey()]] as $where) {
            $this->read([...$where, 'diary_id' => $diary->getKey()])
                ->assertOk()
                ->assertStructuredContent(fn ($json) => $json
                    ->count('images', 2)
                    ->where('images.0.number', 1)
                    ->where('images.1.number', 3)
                    ->etc());

            foreach ([2, 9] as $number) {
                $this->read([...$where, 'diary_id' => $diary->getKey(), 'number' => $number])
                    ->assertHasErrors(['No such diary']);
            }
        }
    }

    public function test_more_bytes_than_a_call_may_return_is_refused_before_any_are_read(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diary($author);
        $first = $this->attach($diary, 1, 800, 400);
        $second = $this->attach($diary, 2, 400, 800);

        // Recorded sizes only: the stored bytes stay small, so the refusal can only come from the
        // preflight — nothing here is big enough to trip the check on what was actually read.
        $first->update(['byte_size' => intdiv(self::CAP, 2) + 1]);
        $second->update(['byte_size' => intdiv(self::CAP, 2) + 1]);

        $this->acting($author);

        $this->read(['diary_id' => $diary->getKey(), 'size' => 'original'])
            ->assertHasErrors(['8 MB'])
            ->assertDontSee($this->wire($this->stored($first)));

        // One at a time fits, which is what the refusal tells the caller to do.
        $this->read(['diary_id' => $diary->getKey(), 'size' => 'original', 'number' => 1])
            ->assertOk()
            ->assertSee($this->wire($this->stored($first)));
    }

    public function test_bytes_that_outgrow_their_recorded_size_are_refused_before_they_are_all_read(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diary($author);
        $comment = $this->comment($diary);
        $honest = $this->attachToComment($comment, 800, 400);

        // A row that understates what it stores, by several times what a call may answer with: the
        // preflight lets it through on its recorded size, so only the read itself can stop it.
        $liar = $this->link($comment, File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'diaryComment',
            'related_entity_id' => $comment->getKey(),
            'byte_size' => 1024,
        ]));
        $this->app->instance(
            FileStorage::class,
            new CountingFileStorage(app(FileStorage::class), (int) $liar->getKey()),
        );

        $this->acting($author);

        foreach (['original', 'thumbnail'] as $size) {
            CountedByteStream::prepare(4 * self::CAP);

            $this->read(['diary_id' => $diary->getKey(), 'comment_id' => $comment->getKey(), 'size' => $size])
                ->assertHasErrors(['8 MB'])
                // Nothing partial: the picture read before the liar was reached does not go back either.
                ->assertDontSee($this->wire($this->stored($honest)));

            $this->assertLessThanOrEqual(
                self::CAP + CountedByteStream::SLACK,
                CountedByteStream::consumed(),
                "The whole file was read before the {$size} answer was judged too large.",
            );
        }
    }

    public function test_switching_diaries_off_takes_the_picture_tool_away(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diary($author);
        $this->attach($diary, 1);

        $this->acting($author);
        $this->setSnsSetting(Feature::Diary->settingKey(), false);

        $this->read(['diary_id' => $diary->getKey()])->assertHasErrors(['not found']);
    }

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
            ->assertSee($this->wire($this->stored($file)))
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
            ->assertSee($this->wire($this->stored($file)))
            ->assertStructuredContent(fn ($json) => $json->where('images.0.number', 1)->etc());
    }

    public function test_a_posted_picture_is_stripped_of_its_metadata_like_any_other(): void
    {
        $original = $this->fixture('jpeg-gps-orientation.jpg');

        $this->acting(Member::factory()->create());

        $this->postDiary(['images' => [base64_encode($original)]])->assertOk();

        $file = Diary::query()->sole()->images()->with('file')->sole()->file;
        $stored = $this->stored($file);

        $this->assertStringNotContainsString('2021:07:04', $stored, 'the stored bytes carry no GPS');
        $this->assertLessThan(strlen($original), strlen($stored));
        $this->assertSame($file->byte_size, strlen($stored));
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

    public function test_a_picture_that_cannot_be_stripped_is_refused_as_an_error_on_that_picture(): void
    {
        $this->acting(Member::factory()->create());
        $this->app->setLocale('en');

        // A JPEG truncated mid-scan: getimagesize still reads its header, so it passes the rules,
        // and the segment walk the stripper does fails closed.
        $this->postDiary(['images' => [$this->encodedImage(20, 20), base64_encode($this->fixture('jpeg-truncated.jpg'))]])
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
