<?php

namespace Tests\Feature\Group;

use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Features\Group\Actions\CreateGroup;
use App\Features\Group\Actions\DeleteGroup;
use App\Features\Group\Actions\UpdateGroup;
use App\Features\Group\Data\GroupFormData;
use App\Features\Group\JoinPolicy;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupImageTest extends TestCase
{
    use RefreshDatabase;

    private function fake(string $name = 'i.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    private function data(string $name = 'Group'): GroupFormData
    {
        return new GroupFormData($name, 'desc', JoinPolicy::Approval, null, true, TopicReadAccess::Everyone, TopicPostAuthority::Members);
    }

    private function groupWithAdmin(): array
    {
        $group = Group::factory()->create();
        $admin = Member::factory()->create();
        GroupMember::factory()->admin()->create(['group_id' => $group->getKey(), 'member_id' => $admin->getKey()]);

        return [$group, $admin];
    }

    public function test_creating_a_community_with_an_image_owns_the_file(): void
    {
        $creator = Member::factory()->create();

        $group = app(CreateGroup::class)($creator, $this->data('With Image'), $this->fake());

        $file = $group->image()->first();
        $this->assertNotNull($file);
        $this->assertSame('group', $file->related_entity_type);
        $this->assertSame($group->getKey(), $file->related_entity_id);
    }

    public function test_updating_with_a_new_image_replaces_and_purges_the_old_bytes(): void
    {
        [$group, $admin] = $this->groupWithAdmin();
        app(UpdateGroup::class)($admin, $group, $this->data(), $this->fake('old.png'));
        $old = $group->refresh()->image()->first();

        app(UpdateGroup::class)($admin, $group->refresh(), $this->data(), $this->fake('new.png'));

        $new = $group->refresh()->image()->first();
        $this->assertNotSame($old->getKey(), $new->getKey());
        $this->assertNull(File::find($old->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $old->getKey())->count());
    }

    public function test_removing_the_image_clears_the_link_and_purges_the_bytes(): void
    {
        [$group, $admin] = $this->groupWithAdmin();
        app(UpdateGroup::class)($admin, $group, $this->data(), $this->fake());
        $file = $group->refresh()->image()->first();

        app(UpdateGroup::class)($admin, $group->refresh(), $this->data(), null, removeImage: true);

        $this->assertNull($group->refresh()->file_id);
        $this->assertNull(File::find($file->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $file->getKey())->count());
    }

    public function test_a_stale_instance_still_purges_the_current_image_on_replace(): void
    {
        // Regression for the concurrency race: file_id is a mutable self-column, so an action handed
        // a stale community (its in-memory file_id already overwritten by a concurrent edit) must
        // read the *current* image under the lock, not the stale one, or the live image orphans.
        [$group, $admin] = $this->groupWithAdmin();
        app(UpdateGroup::class)($admin, $group, $this->data(), $this->fake('a.png'));

        // A concurrent edit replaces the image through a fresh instance; $group is now stale.
        $fresh = Group::findOrFail($group->getKey());
        app(UpdateGroup::class)($admin, $fresh, $this->data(), $this->fake('b.png'));
        $fileB = $fresh->refresh()->image()->first();

        // Replace again through the STALE $group (still carrying file_id = A in memory).
        app(UpdateGroup::class)($admin, $group, $this->data(), $this->fake('c.png'));

        $fileC = $group->refresh()->image()->first();
        $this->assertNotSame($fileB->getKey(), $fileC->getKey());
        // The live image (B) was purged, not missed; no orphan left behind.
        $this->assertNull(File::find($fileB->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $fileB->getKey())->count());
    }

    public function test_a_new_upload_wins_over_the_remove_flag(): void
    {
        [$group, $admin] = $this->groupWithAdmin();
        app(UpdateGroup::class)($admin, $group, $this->data(), $this->fake('first.png'));

        // remove_image set AND a new image given: the upload wins (the community keeps an image).
        app(UpdateGroup::class)($admin, $group->refresh(), $this->data(), $this->fake('second.png'), removeImage: true);

        $this->assertNotNull($group->refresh()->file_id);
    }

    public function test_deleting_a_community_purges_its_top_image_bytes(): void
    {
        [$group, $admin] = $this->groupWithAdmin();
        app(UpdateGroup::class)($admin, $group, $this->data(), $this->fake());
        $file = $group->refresh()->image()->first();

        app(DeleteGroup::class)($admin, $group->refresh());

        $this->assertNull(File::find($file->getKey()));
        $this->assertSame(0, DB::table('file_bin')->where('file_id', $file->getKey())->count());
    }

    public function test_the_top_image_is_visible_to_any_signed_in_member(): void
    {
        [$group, $admin] = $this->groupWithAdmin();
        app(UpdateGroup::class)($admin, $group, $this->data(), $this->fake());
        $file = $group->refresh()->image()->first();

        $this->actingAs(Member::factory()->create())->get($file->url())->assertOk();
    }

    public function test_the_show_page_renders_the_top_image_and_the_edit_form_offers_removal(): void
    {
        [$group, $admin] = $this->groupWithAdmin();
        app(UpdateGroup::class)($admin, $group, $this->data(), $this->fake());
        $file = $group->refresh()->image()->first();

        $this->actingAs($admin)->get(route('group.show', $group))
            ->assertOk()
            ->assertSee($file->thumbnailUrl(180, 180, square: true), escape: false); // OpenPNE 3's 180×180

        $this->actingAs($admin)->get(route('group.edit', ['id' => $group->getKey()]))
            ->assertOk()
            ->assertSee('name="remove_image"', escape: false);
    }

    public function test_a_non_image_attachment_is_rejected(): void
    {
        [$group, $admin] = $this->groupWithAdmin();

        $this->actingAs($admin)->post(route('group.save', ['id' => $group->getKey()]), [
            'name' => $group->name,
            'register_policy' => $group->register_policy->slug(),
            'topic_read_access' => $group->topic_read_access->slug(),
            'topic_post_authority' => $group->topic_post_authority->slug(),
            'image' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors('image');

        $this->assertNull($group->refresh()->file_id);
    }
}
