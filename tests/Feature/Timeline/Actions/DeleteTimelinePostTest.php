<?php

namespace Tests\Feature\Timeline\Actions;

use App\Features\Timeline\Actions\CreateTimelinePost;
use App\Features\Timeline\Actions\DeleteTimelinePost;
use App\Features\Timeline\Data\TimelinePostFormData;
use App\Models\Member;
use App\Models\Reaction;
use App\Models\TimelinePost;
use App\Models\TimelinePostImage;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeleteTimelinePostTest extends TestCase
{
    use RefreshDatabase;

    public function test_deletes_the_post(): void
    {
        $post = TimelinePost::factory()->create();

        (new DeleteTimelinePost)($post);

        $this->assertDatabaseMissing('timeline_posts', ['id' => $post->getKey()]);
    }

    public function test_deleting_a_post_cascades_its_replies(): void
    {
        $post = TimelinePost::factory()->create();
        $reply = TimelinePost::factory()->replyTo($post)->create();

        (new DeleteTimelinePost)($post);

        $this->assertDatabaseMissing('timeline_posts', ['id' => $reply->getKey()]);
    }

    /** The cascade takes the reply rows; nothing takes a reaction, so the thread's are swept by hand. */
    public function test_deleting_a_post_sweeps_the_threads_reactions_and_spares_another_threads(): void
    {
        $post = TimelinePost::factory()->create();
        $reply = TimelinePost::factory()->replyTo($post)->create();
        $elsewhere = TimelinePost::factory()->create();
        foreach ([$post, $reply, $elsewhere] as $target) {
            $target->reactions()->create(['member_id' => Member::factory()->create()->getKey(), 'emoji' => "\u{1F44D}"]);
        }

        (new DeleteTimelinePost)($post);

        $this->assertSame([$elsewhere->getKey()], Reaction::query()->pluck('reactable_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_deleting_a_reply_sweeps_only_its_own_reactions(): void
    {
        $post = TimelinePost::factory()->create();
        $reply = TimelinePost::factory()->replyTo($post)->create();
        foreach ([$post, $reply] as $target) {
            $target->reactions()->create(['member_id' => Member::factory()->create()->getKey(), 'emoji' => "\u{1F44D}"]);
        }

        (new DeleteTimelinePost)($reply);

        $this->assertDatabaseHas('timeline_posts', ['id' => $post->getKey()]);
        $this->assertSame([$post->getKey()], Reaction::query()->pluck('reactable_id')->map(fn ($id) => (int) $id)->all());
    }

    /** The sweep and the delete are one transaction: neither outlives the other. */
    public function test_the_sweep_is_in_the_deletes_transaction(): void
    {
        $post = TimelinePost::factory()->create();
        $post->reactions()->create(['member_id' => Member::factory()->create()->getKey(), 'emoji' => "\u{1F44D}"]);
        // Relative to the level the test itself runs at: RefreshDatabase already wraps everything.
        $outside = DB::transactionLevel();
        $depth = [];
        DB::listen(function ($query) use (&$depth): void {
            if (str_contains($query->sql, 'delete from')) {
                $depth[] = DB::transactionLevel();
            }
        });

        (new DeleteTimelinePost)($post);

        $this->assertNotEmpty($depth);
        $this->assertSame([], array_filter($depth, fn (int $level): bool => $level <= $outside), 'a delete ran outside the action\'s transaction');
    }

    public function test_purges_the_owned_image_file_and_join_row(): void
    {
        $author = Member::factory()->create();
        $image = UploadedFile::fake()->image('p.png', 20, 20);
        $post = app(CreateTimelinePost::class)($author, new TimelinePostFormData('pic', Visibility::Members), $image);
        $fileId = $post->fresh()->images->first()->file_id;

        (new DeleteTimelinePost)($post);

        // The FK cascade drops the join row; DeleteTimelinePost purges the File (and its bytes).
        $this->assertDatabaseMissing('timeline_post_images', ['timeline_post_id' => $post->getKey()]);
        $this->assertDatabaseMissing('files', ['id' => $fileId]);
    }

    public function test_purges_an_image_a_reply_carries(): void
    {
        // OpenPNE 4's writer attaches no image to a reply, but the column allows one and upgraded
        // OpenPNE 3 threads can carry one — and the cascade takes the join row with it, so nothing
        // else could ever reach that File again.
        $post = TimelinePost::factory()->create();
        $reply = TimelinePost::factory()->replyTo($post)->create();
        $image = TimelinePostImage::factory()->create(['timeline_post_id' => $reply->getKey()]);

        (new DeleteTimelinePost)($post);

        $this->assertDatabaseMissing('files', ['id' => $image->file_id]);
    }
}
