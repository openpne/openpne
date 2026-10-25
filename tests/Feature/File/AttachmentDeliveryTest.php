<?php

namespace Tests\Feature\File;

use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** A file attached to a post downloads for the post's readers and nobody else. */
class AttachmentDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_posted_pdf_downloads_as_an_attachment_for_a_reader_and_404s_for_a_stranger(): void
    {
        $author = Member::factory()->create();
        $this->actingAs($author)->post(route('diary.store'), [
            'title' => 'minutes',
            'body' => 'b',
            'visibility' => Visibility::Friends->value,
            'images' => [UploadedFile::fake()->createWithContent('minutes.pdf', '%PDF-1.4 minutes')],
        ])->assertSessionHasNoErrors();
        $diary = Diary::query()->firstOrFail();
        $file = DiaryImage::query()->where('diary_id', $diary->getKey())->firstOrFail()->file;

        $response = $this->actingAs($author)->get($file->url());
        $response->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment; filename=', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('minutes.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('%PDF-1.4 minutes', $response->streamedContent());

        $this->actingAs(Member::factory()->create())->get($file->url())->assertNotFound();
    }
}
