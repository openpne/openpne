<?php

namespace Tests\Feature\File;

use App\Models\File;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * FilePolicy::view, exercised through the Gate (so the morph map + policy
 * registration are covered too). The fail-closed cases are the point: anything that
 * does not resolve to a permitted owner must be denied, never served as public.
 */
class FilePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_their_own_member_image(): void
    {
        $owner = Member::factory()->create();
        $file = $this->memberImage($owner);

        $this->assertTrue(Gate::forUser($owner)->allows('view', $file));
    }

    public function test_another_member_can_view_a_member_image(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();

        $this->assertTrue(Gate::forUser($viewer)->allows('view', $this->memberImage($owner)));
    }

    public function test_a_member_blocked_by_the_owner_cannot_view_the_image(): void
    {
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $owner->blocksMade()->attach($viewer, ['created_at' => now()]);

        $this->assertFalse(Gate::forUser($viewer)->allows('view', $this->memberImage($owner)));
    }

    public function test_a_member_who_blocked_the_owner_can_still_view_it(): void
    {
        // ownerBlocksViewer is one-way: the viewer blocking the owner does not hide
        // the owner's content from the viewer.
        $owner = Member::factory()->create();
        $viewer = Member::factory()->create();
        $viewer->blocksMade()->attach($owner, ['created_at' => now()]);

        $this->assertTrue(Gate::forUser($viewer)->allows('view', $this->memberImage($owner)));
    }

    public function test_unlinked_file_is_denied(): void
    {
        $file = File::factory()->create(['related_entity_type' => null, 'related_entity_id' => null]);

        $this->assertFalse(Gate::forUser(Member::factory()->create())->allows('view', $file));
    }

    public function test_unknown_owner_type_is_denied(): void
    {
        $file = File::factory()->create(['related_entity_type' => 'widget', 'related_entity_id' => 1]);

        $this->assertFalse(Gate::forUser(Member::factory()->create())->allows('view', $file));
    }

    public function test_image_of_a_deleted_owner_is_denied(): void
    {
        $owner = Member::factory()->create();
        $file = $this->memberImage($owner);
        $owner->delete();

        $this->assertFalse(Gate::forUser(Member::factory()->create())->allows('view', $file));
    }

    private function memberImage(Member $owner): File
    {
        return File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'member',
            'related_entity_id' => $owner->getKey(),
        ]);
    }
}
