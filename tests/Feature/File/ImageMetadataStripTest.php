<?php

namespace Tests\Feature\File;

use App\Models\Diary;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Fixtures are the committed byte images described in tests/Fixtures/images/README.md. */
class ImageMetadataStripTest extends TestCase
{
    use RefreshDatabase;

    private const GPS_SENTINEL = '2021:07:04';

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/images/{$name}"));
    }

    private function upload(string $name, string $fixture): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->fixture($fixture));
    }

    public function test_a_diary_image_upload_is_stored_stripped_and_delivered_consistently(): void
    {
        $author = Member::factory()->create();
        $original = $this->fixture('jpeg-gps-orientation.jpg');

        $this->actingAs($author)->post(route('diary.store'), [
            'title' => 'Trip',
            'body' => 'Photo attached.',
            'visibility' => Visibility::Members->value,
            'images' => [$this->upload('trip.jpg', 'jpeg-gps-orientation.jpg')],
        ])->assertRedirect();

        $file = Diary::firstOrFail()->images()->with('file')->first()->file;
        $this->assertLessThan(strlen($original), $file->byte_size, 'stripped image is smaller than the original');

        $response = $this->actingAs($author)->get($file->url())->assertOk();
        $body = $response->streamedContent();

        $this->assertStringNotContainsString(self::GPS_SENTINEL, $body, 'delivered bytes carry no GPS');
        $this->assertNotFalse(getimagesizefromstring($body), 'delivered bytes still decode');
        // Content-Length == delivered body length == the stored byte_size (delivery invariant).
        $this->assertSame((string) strlen($body), $response->headers->get('Content-Length'));
        $this->assertSame($file->byte_size, strlen($body));
    }

    public function test_an_avatar_upload_is_stored_stripped(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->post(route('member.avatar.update'), ['image' => $this->upload('me.jpg', 'jpeg-gps-orientation.jpg')])
            ->assertRedirect(route('member.avatar.edit'));

        $file = $member->fresh()->avatar->file;
        $response = $this->actingAs($member)->get($file->url())->assertOk();
        $body = $response->streamedContent();

        $this->assertStringNotContainsString(self::GPS_SENTINEL, $body);
        $this->assertSame($file->byte_size, strlen($body));
        $this->assertSame((string) strlen($body), $response->headers->get('Content-Length'));
    }

    public function test_the_toggle_off_stores_bytes_unchanged(): void
    {
        config(['openpne.images.strip_metadata' => false]);
        $member = Member::factory()->create();
        $original = $this->fixture('jpeg-gps-orientation.jpg');

        $this->actingAs($member)
            ->post(route('member.avatar.update'), ['image' => $this->upload('me.jpg', 'jpeg-gps-orientation.jpg')])
            ->assertRedirect();

        $file = $member->fresh()->avatar->file;
        $this->assertSame(strlen($original), $file->byte_size, 'byte_size equals the original length');

        $body = $this->actingAs($member)->get($file->url())->streamedContent();
        $this->assertSame($original, $body, 'stored bytes are byte-identical to the upload');
        $this->assertStringContainsString(self::GPS_SENTINEL, $body, 'GPS is retained when stripping is off');
    }

    public function test_a_corrupt_image_that_passes_validation_fails_closed_as_a_diary_field_error(): void
    {
        // A JPEG truncated mid-scan: getimagesize (hence the image/dimensions rules) still reads its
        // SOF header, so it passes upstream validation, but the full segment walk fails closed.
        $author = Member::factory()->create();

        $response = $this->actingAs($author)->post(
            route('diary.store'),
            [
                'title' => 'Broken',
                'body' => 'b',
                'visibility' => Visibility::Members->value,
                'images' => [$this->upload('broken.jpg', 'jpeg-truncated.jpg')],
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(422)->assertJsonValidationErrors('images.0');
        $this->assertDatabaseCount('diaries', 0);
    }

    public function test_a_corrupt_avatar_fails_closed_as_an_image_field_error(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post(
            route('member.avatar.update'),
            ['image' => $this->upload('broken.jpg', 'jpeg-truncated.jpg')],
            ['Accept' => 'application/json'],
        )->assertStatus(422)->assertJsonValidationErrors('image');

        $this->assertSame(0, $member->fresh()->avatar()->count());
    }
}
