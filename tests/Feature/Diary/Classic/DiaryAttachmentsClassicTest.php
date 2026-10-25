<?php

namespace Tests\Feature\Diary\Classic;

use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiaryAttachmentsClassicTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_file_attachment_is_listed_as_a_download_link_not_a_photo(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $pdf = File::factory()->create(['type' => 'application/pdf', 'original_filename' => 'agenda.pdf', 'byte_size' => 3072]);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $pdf->getKey(), 'number' => 1]);

        $response = $this->actingAs($owner)->get(route('diary.show', $diary));

        $response->assertOk()
            ->assertSee('<ul class="files">', false)
            ->assertSee('<a href="'.$pdf->url().'">agenda.pdf</a> (3.0 KB)', false)
            ->assertDontSee('<ul class="photo">', false)
            ->assertDontSee('/cache/img/', false);
    }
}
