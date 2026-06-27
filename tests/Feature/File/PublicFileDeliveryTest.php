<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Files\FileStorage;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public delivery route for admin-uploaded public assets. The point the Gate-only policy tests
 * miss: a guest must actually receive the bytes over HTTP (file.show is behind auth, so the public
 * route is what makes an embedded asset load for logged-out visitors).
 */
class PublicFileDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function publicImage(string $content = 'PNGDATA'): File
    {
        return $this->fileWithBytes($content, [
            'type' => 'image/png',
            'explicit_visibility' => File::VISIBILITY_PUBLIC,
            'related_entity_type' => null,
            'related_entity_id' => null,
        ]);
    }

    private function fileWithBytes(string $content, array $attributes): File
    {
        $file = File::factory()->create($attributes + ['byte_size' => strlen($content)]);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        app(FileStorage::class)->writeStream($file, $stream);
        fclose($stream);

        return $file;
    }

    public function test_guest_can_fetch_a_public_file(): void
    {
        $file = $this->publicImage();

        $response = $this->get(route('file.public', $file->name));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('PNGDATA', $response->streamedContent());
    }

    public function test_a_non_public_file_is_not_served_here(): void
    {
        // A member's avatar (owner-gated, not explicit-public) must 404 on the public route even for a
        // member who could otherwise view it — the public route only serves explicit_visibility='public'.
        $owner = Member::factory()->create();
        $file = $this->fileWithBytes('x', [
            'type' => 'image/png',
            'related_entity_type' => 'member',
            'related_entity_id' => $owner->getKey(),
        ]);

        $this->actingAs($owner)->get(route('file.public', $file->name))->assertNotFound();
    }

    public function test_unknown_name_is_not_found(): void
    {
        $this->get(route('file.public', 'does-not-exist'))->assertNotFound();
    }
}
