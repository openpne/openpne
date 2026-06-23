<?php

namespace Tests\Feature\Community;

use App\Features\Community\Actions\CreateCommunity;
use App\Features\Community\Actions\DeleteCommunity;
use App\Features\Community\Actions\UpdateCommunity;
use App\Features\Community\Data\CommunityFormData;
use App\Features\Community\JoinPolicy;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunityImageTest extends TestCase
{
    use RefreshDatabase;

    private function fake(string $name = 'i.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    private function data(string $name = 'Community'): CommunityFormData
    {
        return new CommunityFormData($name, 'desc', JoinPolicy::Approval, null);
    }

    private function communityWithAdmin(): array
    {
        $community = Community::factory()->create();
        $admin = Member::factory()->create();
        CommunityMember::factory()->admin()->create(['community_id' => $community->getKey(), 'member_id' => $admin->getKey()]);

        return [$community, $admin];
    }

    public function test_creating_a_community_with_an_image_owns_the_file(): void
    {
        $creator = Member::factory()->create();

        $community = app(CreateCommunity::class)($creator, $this->data('With Image'), $this->fake());

        $file = $community->image()->first();
        $this->assertNotNull($file);
        $this->assertSame('community', $file->related_entity_type);
        $this->assertSame($community->getKey(), $file->related_entity_id);
    }

    public function test_updating_with_a_new_image_replaces_and_purges_the_old_bytes(): void
    {
        [$community, $admin] = $this->communityWithAdmin();
        app(UpdateCommunity::class)($admin, $community, $this->data(), $this->fake('old.png'));
        $old = $community->refresh()->image()->first();

        app(UpdateCommunity::class)($admin, $community->refresh(), $this->data(), $this->fake('new.png'));

        $new = $community->refresh()->image()->first();
        $this->assertNotSame($old->getKey(), $new->getKey());
        $this->assertNull(File::find($old->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $old->getKey())->count());
    }

    public function test_removing_the_image_clears_the_link_and_purges_the_bytes(): void
    {
        [$community, $admin] = $this->communityWithAdmin();
        app(UpdateCommunity::class)($admin, $community, $this->data(), $this->fake());
        $file = $community->refresh()->image()->first();

        app(UpdateCommunity::class)($admin, $community->refresh(), $this->data(), null, removeImage: true);

        $this->assertNull($community->refresh()->file_id);
        $this->assertNull(File::find($file->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $file->getKey())->count());
    }

    public function test_a_stale_instance_still_purges_the_current_image_on_replace(): void
    {
        // Regression for the concurrency race: file_id is a mutable self-column, so an action handed
        // a stale community (its in-memory file_id already overwritten by a concurrent edit) must
        // read the *current* image under the lock, not the stale one, or the live image orphans.
        [$community, $admin] = $this->communityWithAdmin();
        app(UpdateCommunity::class)($admin, $community, $this->data(), $this->fake('a.png'));

        // A concurrent edit replaces the image through a fresh instance; $community is now stale.
        $fresh = Community::findOrFail($community->getKey());
        app(UpdateCommunity::class)($admin, $fresh, $this->data(), $this->fake('b.png'));
        $fileB = $fresh->refresh()->image()->first();

        // Replace again through the STALE $community (still carrying file_id = A in memory).
        app(UpdateCommunity::class)($admin, $community, $this->data(), $this->fake('c.png'));

        $fileC = $community->refresh()->image()->first();
        $this->assertNotSame($fileB->getKey(), $fileC->getKey());
        // The live image (B) was purged, not missed; no orphan left behind.
        $this->assertNull(File::find($fileB->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $fileB->getKey())->count());
    }

    public function test_a_new_upload_wins_over_the_remove_flag(): void
    {
        [$community, $admin] = $this->communityWithAdmin();
        app(UpdateCommunity::class)($admin, $community, $this->data(), $this->fake('first.png'));

        // remove_image set AND a new image given: the upload wins (the community keeps an image).
        app(UpdateCommunity::class)($admin, $community->refresh(), $this->data(), $this->fake('second.png'), removeImage: true);

        $this->assertNotNull($community->refresh()->file_id);
    }

    public function test_deleting_a_community_purges_its_top_image_bytes(): void
    {
        [$community, $admin] = $this->communityWithAdmin();
        app(UpdateCommunity::class)($admin, $community, $this->data(), $this->fake());
        $file = $community->refresh()->image()->first();

        app(DeleteCommunity::class)($admin, $community->refresh());

        $this->assertNull(File::find($file->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $file->getKey())->count());
    }

    public function test_the_top_image_is_visible_to_any_signed_in_member(): void
    {
        [$community, $admin] = $this->communityWithAdmin();
        app(UpdateCommunity::class)($admin, $community, $this->data(), $this->fake());
        $file = $community->refresh()->image()->first();

        $this->actingAs(Member::factory()->create())->get($file->url())->assertOk();
    }

    public function test_the_show_page_renders_the_top_image_and_the_edit_form_offers_removal(): void
    {
        [$community, $admin] = $this->communityWithAdmin();
        app(UpdateCommunity::class)($admin, $community, $this->data(), $this->fake());
        $file = $community->refresh()->image()->first();

        $this->actingAs($admin)->get(route('community.show', $community))
            ->assertOk()
            ->assertSee($file->thumbnailUrl(120, 120, square: true), escape: false);

        $this->actingAs($admin)->get(route('community.edit', ['id' => $community->getKey()]))
            ->assertOk()
            ->assertSee('name="remove_image"', escape: false);
    }

    public function test_a_non_image_attachment_is_rejected(): void
    {
        [$community, $admin] = $this->communityWithAdmin();

        $this->actingAs($admin)->post(route('community.save', ['id' => $community->getKey()]), [
            'name' => $community->name,
            'register_policy' => $community->register_policy->value,
            'image' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors('image');

        $this->assertNull($community->refresh()->file_id);
    }
}
