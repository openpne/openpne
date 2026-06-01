<?php

namespace Tests\Feature\File;

use App\Files\FileStorage;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The file delivery endpoint. Every backend streams through the controller so
 * FilePolicy gates each fetch, and a stored file is never interpreted as a
 * same-origin document.
 */
class FileDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $file = $this->memberImage(Member::factory()->create(), 'image/png', 'bytes');

        $this->get(route('file.show', $file->name))->assertRedirect(route('login'));
    }

    public function test_owner_gets_the_bytes_inline_with_hardening_headers(): void
    {
        $owner = Member::factory()->create();
        $file = $this->memberImage($owner, 'image/png', 'PNGDATA');

        $response = $this->actingAs($owner)->get(route('file.show', $file->name));

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame('PNGDATA', $response->streamedContent());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_another_member_can_fetch_an_image(): void
    {
        $owner = Member::factory()->create();
        $file = $this->memberImage($owner, 'image/png', 'x');

        $this->actingAs(Member::factory()->create())
            ->get(route('file.show', $file->name))
            ->assertOk();
    }

    public function test_a_blocked_member_gets_404_not_403(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $owner->blocksMade()->attach($viewer, ['created_at' => now()]);
        $file = $this->memberImage($owner, 'image/png', 'secret');

        $this->actingAs($viewer)->get(route('file.show', $file->name))->assertNotFound();
    }

    public function test_unlinked_file_is_not_served(): void
    {
        $file = File::factory()->create(['related_entity_type' => null, 'related_entity_id' => null, 'type' => 'image/png']);
        $this->writeBytes($file, 'orphan');

        $this->actingAs(Member::factory()->create())->get(route('file.show', $file->name))->assertNotFound();
    }

    public function test_svg_is_forced_to_download_not_rendered_inline(): void
    {
        // SVG can carry script; serving it inline same-origin would be stored XSS.
        $owner = Member::factory()->create();
        $file = $this->memberImage($owner, 'image/svg+xml', '<svg onload="alert(1)"></svg>');

        $response = $this->actingAs($owner)->get(route('file.show', $file->name));

        $response->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_url_is_always_an_app_route_even_on_a_disk_backend(): void
    {
        config()->set('openpne.files.disk', 'local');
        Storage::fake('local');

        $owner = Member::factory()->create();
        $file = $this->memberImage($owner, 'image/png', 'DISKBYTES');

        // File::url points at the app route, not a direct disk URL.
        $this->assertSame(route('file.show', ['file' => $file->name]), $file->url());

        // And the disk backend streams through the app (status 200, not a redirect).
        $response = $this->actingAs($owner)->get($file->url());
        $response->assertOk();
        $this->assertSame('DISKBYTES', $response->streamedContent());
    }

    private function memberImage(Member $owner, string $type, string $bytes): File
    {
        $file = File::factory()->create([
            'type' => $type,
            'related_entity_type' => 'member',
            'related_entity_id' => $owner->getKey(),
            'byte_size' => strlen($bytes),
        ]);
        $this->writeBytes($file, $bytes);

        return $file;
    }

    private function writeBytes(File $file, string $bytes): void
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $bytes);
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);
    }
}
