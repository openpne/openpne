<?php

namespace Tests\Feature\File;

use App\Files\FileResponse;
use App\Files\FileStorage;
use App\Models\File;
use App\Models\Group;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageBytes;
use Tests\TestCase;

/**
 * OpenPNE 3 upload-plugin links (`/f/show/{id}.{ext}`) against migrated rows: names with slashes and
 * non-ASCII, owners set by the upgrade, the extension ignored as OpenPNE 3 ignored it.
 */
class LegacyUploadFileDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('image_cache');
    }

    public function test_a_member_downloads_another_members_upload_with_its_original_name(): void
    {
        $file = $this->upload(Member::factory()->create(), '/m12/1699000000報告 書.pdf', '報告 書.pdf');

        $response = $this->actingAs(Member::factory()->create())->get("/f/show/{$file->id}.pdf");

        $response->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $disposition = (string) $response->headers->get('Content-Disposition');
        $this->assertStringStartsWith('attachment; filename=file-'.$file->id.'.pdf', $disposition);
        $this->assertStringContainsString("filename*=utf-8''%E5%A0%B1%E5%91%8A%20%E6%9B%B8.pdf", $disposition);
        $this->assertSame(FileResponse::etag($file), $response->headers->get('ETag'));
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{40}"$/', (string) $response->headers->get('ETag'));
        $this->assertSame('%PDF-1.4 legacy', $response->streamedContent());
    }

    public function test_the_extension_is_ignored_whatever_it_is(): void
    {
        $file = $this->upload(Member::factory()->create(), '/m12/1699000000report.backup_2023', 'report.backup_2023');
        $viewer = Member::factory()->create();

        foreach (["/f/show/{$file->id}", "/f/show/{$file->id}.backup_2023", "/f/show/{$file->id}.pdf", "/f/show/{$file->id}.報告"] as $url) {
            $this->actingAs($viewer)->get($url)->assertOk();
        }
        // No extension to lift into the ASCII fallback: the id alone.
        $this->assertStringStartsWith('attachment; filename=file-'.$file->id.';', (string) $this->actingAs($viewer)->get("/f/show/{$file->id}")->headers->get('Content-Disposition'));
    }

    public function test_the_owners_block_hides_it_and_a_stranger_to_the_id_gets_404(): void
    {
        $owner = Member::factory()->create();
        $blocked = Member::factory()->create();
        $owner->blocksMade()->attach($blocked, ['created_at' => now()]);
        $file = $this->upload($owner, '/m12/1699000000a.pdf', 'a.pdf');

        $this->actingAs($blocked)->get("/f/show/{$file->id}.pdf")->assertNotFound();
        $this->actingAs($blocked)->get('/f/show/999999.pdf')->assertNotFound();
    }

    public function test_a_guest_is_sent_to_login_and_an_inertia_visit_is_told_to_reload(): void
    {
        $file = $this->upload(Member::factory()->create(), '/m12/1699000000a.pdf', 'a.pdf');

        $this->get("/f/show/{$file->id}.pdf")->assertRedirect(route('login'));

        // After the login redirect the Inertia router follows into the bytes; a 409 makes it a full visit.
        $response = $this->actingAs(Member::factory()->create())->withHeader('X-Inertia', 'true')->get("/f/show/{$file->id}.pdf");
        $response->assertStatus(409);
        $this->assertSame(url("/f/show/{$file->id}.pdf"), $response->headers->get('X-Inertia-Location'));
    }

    public function test_a_group_owned_upload_is_readable_by_any_member_until_groups_are_switched_off(): void
    {
        $group = Group::factory()->create();
        $file = $this->file('/c9/1699000000minutes.docx', 'minutes.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx bytes', 'group', (int) $group->getKey());
        $viewer = Member::factory()->create();

        $this->actingAs($viewer)->get("/f/show/{$file->id}.docx")->assertOk();

        $this->setSnsSetting(Feature::Group->settingKey(), false);
        $this->freshRequestState();
        $this->actingAs($viewer)->get("/f/show/{$file->id}.docx")->assertNotFound();
    }

    public function test_a_legacy_picture_is_drawn_inline_from_its_canonical_despite_the_slashes_in_its_name(): void
    {
        $file = $this->file('/m12/1699000000photo.png', 'photo.png', 'image/png', ImageBytes::png(16, 8), 'member', (int) Member::factory()->create()->getKey());

        $response = $this->actingAs(Member::factory()->create())->get("/f/show/{$file->id}.png");

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline; filename=file-'.$file->id.'.png', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame([16, 8], array_slice((array) getimagesizefromstring($response->getContent()), 0, 2));
    }

    public function test_bytes_that_only_claim_to_be_a_picture_answer_404(): void
    {
        // OpenPNE 3 stored the browser's type unverified; a canonical cannot be made, so the row is refused.
        $file = $this->file('/m12/1699000000fake.jpg', 'fake.jpg', 'image/jpeg', 'not a jpeg', 'member', (int) Member::factory()->create()->getKey());

        $this->actingAs(Member::factory()->create())->get("/f/show/{$file->id}.jpg")->assertNotFound();
    }

    private function upload(Member $owner, string $name, string $originalFilename): File
    {
        return $this->file($name, $originalFilename, 'application/pdf', '%PDF-1.4 legacy', 'member', (int) $owner->getKey());
    }

    private function file(string $name, string $originalFilename, string $type, string $bytes, string $ownerType, int $ownerId): File
    {
        $file = File::factory()->create([
            'name' => $name,
            'original_filename' => $originalFilename,
            'type' => $type,
            'related_entity_type' => $ownerType,
            'related_entity_id' => $ownerId,
            'byte_size' => strlen($bytes),
        ]);

        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        return $file;
    }
}
