<?php

namespace Tests\Feature\File;

use App\Features\Diary\Serializers\DiarySerializer;
use App\Files\AttachmentShape;
use App\Files\PostAttachments;
use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A slot row holding a file lands in `files` with the chip shape, never in `images` (docs/internals/attachments.md, "Pictures and files"). */
class AttachmentFileSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_partition_splits_slot_rows_by_the_stored_type(): void
    {
        $diary = Diary::factory()->create(['member_id' => Member::factory()->create()->getKey()]);
        $pdf = File::factory()->create(['type' => 'application/pdf', 'original_filename' => 'report.pdf', 'byte_size' => 2048]);
        $png = File::factory()->create(['type' => 'image/png']);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $pdf->getKey(), 'number' => 1]);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $png->getKey(), 'number' => 2]);

        $parts = PostAttachments::partition($diary->images()->with('file')->get());

        $this->assertSame([$png->getKey()], $parts['images']->pluck('file_id')->all());
        $this->assertSame([$pdf->getKey()], $parts['files']->pluck('file_id')->all());
        $this->assertSame(1, $diary->pictures()->count());
        $this->assertSame(2, $diary->images()->count());
        $this->assertSame([
            'id' => $parts['files'][0]->getKey(),
            'url' => $pdf->url(),
            'name' => 'report.pdf',
            'byteSize' => 2048,
            'sizeLabel' => '2.0 KB',
            'type' => 'application/pdf',
        ], AttachmentShape::of($parts['files'][0]));
    }

    public function test_the_diary_detail_ships_pictures_and_files_apart(): void
    {
        $author = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $author->getKey()]);
        $pdf = File::factory()->create(['type' => 'application/pdf', 'original_filename' => 'minutes.pdf', 'byte_size' => 10]);
        $png = File::factory()->create(['type' => 'image/png']);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $png->getKey(), 'number' => 1]);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $pdf->getKey(), 'number' => 2]);

        $detail = DiarySerializer::detail($diary->load('images.file', 'member.avatar.file'), $author, []);

        $this->assertCount(1, $detail['images']);
        $this->assertSame($png->url(), $detail['images'][0]['url']);
        $this->assertSame([['name' => 'minutes.pdf', 'url' => $pdf->url()]], array_map(fn (array $f): array => ['name' => $f['name'], 'url' => $f['url']], $detail['files']));
        $this->assertTrue($detail['hasImages']);
    }

    public function test_a_diary_with_only_a_file_has_no_pictures(): void
    {
        $author = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $author->getKey()]);
        $pdf = File::factory()->create(['type' => 'application/pdf']);
        DiaryImage::factory()->create(['diary_id' => $diary->getKey(), 'file_id' => $pdf->getKey(), 'number' => 1]);

        $summary = DiarySerializer::summary(Diary::query()->withCount(['comments', 'pictures'])->with('images.file')->findOrFail($diary->getKey()));

        $this->assertFalse($summary['hasImages']);
        $this->assertSame([], $summary['thumbnails']);
    }
}
