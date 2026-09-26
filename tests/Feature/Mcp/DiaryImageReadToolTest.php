<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Files\FileStorage;
use App\Files\ImageCache;
use App\Files\ImageIntake;
use App\Files\ImageProcessingException;
use App\Files\ImageProcessor;
use App\Files\ImageProcessorUnavailableException;
use App\Files\ImageSpec;
use App\Files\ProcessedImage;
use App\Models\DiaryImage;
use App\Models\File;
use App\Models\Member;
use App\Support\Feature;
use App\Support\Visibility;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\CountedByteStream;
use Tests\Fixtures\CountingFileStorage;

class DiaryImageReadToolTest extends DiaryImageToolsTestCase
{
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

    public function test_the_original_size_answers_with_the_canonical(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diary($author);
        $file = $this->attach($diary, 1, 800, 400);
        $stored = $this->original($file);

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
            ->assertSee($this->wire($this->original($second)))
            ->assertDontSee($this->wire($this->original($first)))
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

    public function test_a_picture_the_processor_refuses_is_reported_in_its_slot_and_the_others_still_answer(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diary($author);
        $first = $this->attach($diary, 1);
        $this->attachStoredBytes($diary, 2, 'not an image at all');
        $third = $this->attach($diary, 3);

        $this->acting($author);

        $this->read(['diary_id' => $diary->getKey()])
            ->assertOk()
            ->assertSee($this->wire($this->thumbnail($first)))
            ->assertSee($this->wire($this->thumbnail($third)))
            ->assertStructuredContent(fn ($json) => $json
                ->count('images', 3)
                ->where('images.0.number', 1)
                ->where('images.1', ['number' => 2, 'unavailable' => true])
                ->where('images.2.number', 3)
                ->etc());

        // Exactly the drawable two come back as pictures, so skipping the unavailable entry pairs them up.
        $this->assertSame(2, $this->imageBlocks($this->read(['diary_id' => $diary->getKey()])));
    }

    public function test_naming_a_refused_picture_is_an_error_not_an_empty_answer(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diary($author);
        $this->attachStoredBytes($diary, 1, 'not an image at all');

        $this->acting($author);

        $this->read(['diary_id' => $diary->getKey(), 'number' => 1])->assertHasErrors(['cannot be drawn']);
    }

    public function test_a_variant_the_processor_refuses_is_reported_in_its_slot_too(): void
    {
        // The first thumbnail is drawn before the swap and served from the cache after it; the second
        // is drawn under a processor that keeps the canonical but refuses every variant.
        $author = Member::factory()->create();
        $diary = $this->diary($author);
        $this->attach($diary, 1);
        $this->acting($author);
        $this->read(['diary_id' => $diary->getKey()])->assertOk();
        $this->attach($diary, 2);
        $inner = $this->app->make(ImageProcessor::class);
        $this->app->instance(ImageProcessor::class, new class($inner) implements ImageProcessor
        {
            public function __construct(private readonly ImageProcessor $inner) {}

            public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
            {
                if (! $spec->isCanonical()) {
                    throw new ImageProcessingException('the sidecar answered more than the cap');
                }

                return $this->inner->process($bytes, $mime, $spec);
            }

            public function preservesAnimation(): bool
            {
                return false;
            }

            public function intake(): ImageIntake
            {
                return ImageIntake::gd();
            }
        });
        $this->app->forgetInstance(ImageCache::class);

        $this->read(['diary_id' => $diary->getKey()])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json
                ->count('images', 2)
                ->where('images.0.number', 1)
                ->where('images.1', ['number' => 2, 'unavailable' => true])
                ->etc());
        $this->read(['diary_id' => $diary->getKey(), 'number' => 2])->assertHasErrors(['cannot be drawn']);
    }

    public function test_a_processor_outage_refuses_the_call_whole(): void
    {
        $author = Member::factory()->create();
        $diary = $this->diary($author);
        $this->attach($diary, 1);
        $this->app->instance(ImageProcessor::class, new class implements ImageProcessor
        {
            public function process(string $bytes, string $mime, ImageSpec $spec): ProcessedImage
            {
                throw new ImageProcessorUnavailableException('imgproxy did not answer');
            }

            public function preservesAnimation(): bool
            {
                return false;
            }

            public function intake(): ImageIntake
            {
                return ImageIntake::gd();
            }
        });
        Storage::disk('image_cache')->deleteDirectory($diary->images()->sole()->file->name);

        $this->acting($author);

        $this->read(['diary_id' => $diary->getKey()])->assertHasErrors(['temporarily unavailable']);
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
            ->assertSee($this->wire($this->original($first)))
            ->assertSee($this->wire($this->original($second)))
            ->assertStructuredContent(fn ($json) => $json
                ->count('images', 2)
                ->where('images.0.number', 1)
                ->where('images.0.width', 800)
                ->where('images.1.number', 2)
                ->where('images.1.width', 400)
                ->etc());

        $this->read(['diary_id' => $diary->getKey(), 'comment_id' => $comment->getKey(), 'size' => 'original', 'number' => 1])
            ->assertOk()
            ->assertSee($this->wire($this->original($first)))
            ->assertDontSee($this->wire($this->original($second)));

        // The row ids of those same two pictures, which are positions this comment does not have.
        foreach ([3, 4] as $rowId) {
            $this->read([
                'diary_id' => $diary->getKey(),
                'comment_id' => $comment->getKey(),
                'size' => 'original',
                'number' => $rowId,
            ])
                ->assertHasErrors(['No such diary'])
                ->assertDontSee($this->wire($this->original($first)))
                ->assertDontSee($this->wire($this->original($second)));
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
            ->assertSee($this->wire($this->original($secret)));

        foreach ([$strayed->getKey(), $strayed->getKey() + 9999] as $commentId) {
            $this->read(['diary_id' => $mine->getKey(), 'comment_id' => $commentId, 'size' => 'original'])
                ->assertHasErrors(['No such diary'])
                ->assertDontSee($this->wire($this->original($secret)));
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
                ->assertDontSee([$this->wire($this->original($secret)), $this->wire($this->original($alsoSecret))]);
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
            ->assertDontSee($this->wire($this->original($first)));

        // One at a time fits, which is what the refusal tells the caller to do.
        $this->read(['diary_id' => $diary->getKey(), 'size' => 'original', 'number' => 1])
            ->assertOk()
            ->assertSee($this->wire($this->original($first)));
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
                ->assertDontSee($this->wire($this->original($honest)));

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
}
