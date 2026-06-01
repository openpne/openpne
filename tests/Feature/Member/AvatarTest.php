<?php

namespace Tests\Feature\Member;

use App\Features\Member\Actions\SetAvatar;
use App\Files\FileStorage;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_page_renders_for_a_member_without_an_avatar(): void
    {
        $this->actingAs(Member::factory()->create())
            ->get(route('member.avatar.edit'))
            ->assertOk()
            ->assertSee('name="image"', escape: false);
    }

    public function test_upload_stores_a_member_owned_file_and_links_it(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->post(route('member.avatar.update'), ['image' => UploadedFile::fake()->image('me.png', 20, 20)])
            ->assertRedirect(route('member.avatar.edit'));

        $file = $member->fresh()->primaryImage?->file;
        $this->assertNotNull($file);
        $this->assertSame('member', $file->related_entity_type);
        $this->assertSame($member->getKey(), $file->related_entity_id);
        // The stored avatar is then fetchable through the gated delivery route.
        $this->actingAs($member)->get($file->url())->assertOk();
    }

    public function test_uploading_a_new_avatar_replaces_the_old_one_and_purges_its_bytes(): void
    {
        $member = Member::factory()->create();
        $this->actingAs($member)->post(route('member.avatar.update'), ['image' => UploadedFile::fake()->image('one.png', 10, 10)]);

        $old = $member->fresh()->primaryImage->file;

        $this->actingAs($member)->post(route('member.avatar.update'), ['image' => UploadedFile::fake()->image('two.png', 10, 10)]);

        // Exactly one image remains, and it is the new one.
        $this->assertSame(1, $member->fresh()->images()->count());
        $new = $member->fresh()->primaryImage->file;
        $this->assertNotSame($old->getKey(), $new->getKey());

        // The old File row and its bytes are gone.
        $this->assertNull(File::find($old->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $old->getKey())->count());
    }

    public function test_a_failed_replacement_upload_keeps_the_previous_avatar(): void
    {
        $member = Member::factory()->create();
        app(SetAvatar::class)($member, UploadedFile::fake()->image('old.png', 10, 10));
        $old = $member->fresh()->primaryImage->file;

        // The next byte write fails mid-replace (e.g. a disk error). The previous
        // avatar — row and bytes — must survive.
        $this->mock(FileStorage::class, function ($mock) {
            $mock->shouldReceive('writeStream')->andThrow(new RuntimeException('storage down'));
            $mock->shouldReceive('delete');
        });

        try {
            app(SetAvatar::class)($member, UploadedFile::fake()->image('new.png', 10, 10));
            $this->fail('expected the failed store to throw');
        } catch (RuntimeException) {
            // expected
        }

        $member = $member->fresh();
        $this->assertSame(1, $member->images()->count());
        $this->assertTrue($member->primaryImage->file->is($old));
        $this->assertSame(1, DB::table('file_bin')->where('file_id', $old->getKey())->count());
    }

    public function test_the_edit_page_carries_the_classic_body_id(): void
    {
        $this->actingAs(Member::factory()->create())
            ->get(route('member.avatar.edit'))
            ->assertSee('id="page_member_configImage"', escape: false);
    }

    public function test_the_openpne3_avatar_url_redirects_to_the_editor(): void
    {
        $this->actingAs(Member::factory()->create())
            ->get('/member/image/config')
            ->assertRedirect(route('member.avatar.edit'));
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->post(route('member.avatar.update'), ['image' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')])
            ->assertSessionHasErrors('image');

        $this->assertSame(0, $member->images()->count());
    }

    public function test_an_svg_upload_is_rejected(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->post(route('member.avatar.update'), ['image' => UploadedFile::fake()->createWithContent('x.svg', '<svg></svg>')])
            ->assertSessionHasErrors('image');

        $this->assertSame(0, $member->images()->count());
    }

    public function test_an_oversized_image_is_rejected(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)
            ->post(route('member.avatar.update'), ['image' => UploadedFile::fake()->image('huge.png')->size(6000)])
            ->assertSessionHasErrors('image');

        $this->assertSame(0, $member->images()->count());
    }
}
