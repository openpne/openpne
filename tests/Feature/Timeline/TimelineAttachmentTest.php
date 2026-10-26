<?php

namespace Tests\Feature\Timeline;

use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TimelineAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_document_posts_through_the_single_image_wire_and_ships_as_a_file(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $author = Member::factory()->create();

        $this->actingAs($author)
            ->post('/timeline/create', ['body' => 'minutes', 'visibility' => Visibility::Members->value, 'image' => UploadedFile::fake()->create('minutes.pdf', 12, 'application/pdf')])
            ->assertRedirect()->assertSessionHasNoErrors();

        $post = TimelinePost::query()->firstOrFail();
        $this->assertSame('application/pdf', $post->images()->firstOrFail()->file->type);

        $this->actingAs($author)->get("/timeline/{$post->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('post.hasImages', false)
                ->where('post.images', [])
                ->where('post.files.0.name', 'minutes.pdf')
                ->where('post.files.0.url', $post->images()->firstOrFail()->file->url()));
    }

    public function test_the_classic_row_lists_a_file_by_name_and_draws_no_thumbnail_for_it(): void
    {
        $author = Member::factory()->create();

        $this->actingAs($author)
            ->post('/timeline/create', ['body' => 'minutes', 'visibility' => Visibility::Members->value, 'image' => UploadedFile::fake()->create('minutes.pdf', 12, 'application/pdf')])
            ->assertRedirect()->assertSessionHasNoErrors();
        $file = TimelinePost::query()->firstOrFail()->images()->firstOrFail()->file;

        $this->actingAs($author)->get('/timeline')->assertOk()
            ->assertSee('minutes.pdf')
            ->assertSee($file->url(), false)
            ->assertDontSee('timeline-post-image', false);
    }

    public function test_a_document_over_the_attachment_cap_is_refused(): void
    {
        config(['openpne.files.max_upload_kilobytes' => 10]);
        $author = Member::factory()->create();

        $this->actingAs($author)
            ->from('/timeline/new')
            ->post('/timeline/create', ['body' => 'minutes', 'visibility' => Visibility::Members->value, 'image' => UploadedFile::fake()->create('minutes.pdf', 12, 'application/pdf')])
            ->assertSessionHasErrors('image');

        $this->assertSame(0, TimelinePost::query()->count());
    }
}
