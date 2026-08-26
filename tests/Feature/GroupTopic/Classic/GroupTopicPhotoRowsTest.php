<?php

namespace Tests\Feature\GroupTopic\Classic;

use App\Features\Group\GroupRole;
use App\Features\GroupTopic\Actions\CreateTopic;
use App\Features\GroupTopic\Data\GroupTopicFormData;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * OpenPNE 3 embedded three photo forms, one labelled row each with the input inside
 * ul#community_topic_photo_N; an occupied slot showed the photo over a replacement input and a
 * remove checkbox.
 */
class GroupTopicPhotoRowsTest extends TestCase
{
    use RefreshDatabase;

    private function joined(Group $group): Member
    {
        $member = Member::factory()->create();
        GroupMember::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $member->getKey(),
            'role' => GroupRole::Member,
        ]);

        return $member;
    }

    public function test_the_create_form_has_one_labelled_row_per_slot(): void
    {
        $group = Group::factory()->create();
        $member = $this->joined($group);

        $response = $this->actingAs($member)->get(route('group.topics.new', $group));

        $response->assertOk();
        foreach ([1, 2, 3] as $n) {
            $response->assertSeeInOrder([
                '<label for="community_topic_photo_'.$n.'_photo">Photo '.$n.'</label>',
                '<ul id="community_topic_photo_'.$n.'">',
                '<input type="file" name="images[]" id="community_topic_photo_'.$n.'_photo"',
            ], false);
        }
        $response->assertDontSee('name="remove_images[]"', false);
    }

    public function test_an_occupied_slot_shows_the_photo_and_its_remove_checkbox_and_no_input(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = app(CreateTopic::class)($author, $group, new GroupTopicFormData(name: 'T', body: 'B'), [UploadedFile::fake()->image('i.png', 20, 20)]);
        $image = $topic->images()->with('file')->first();

        $response = $this->actingAs($author)->get(route('group.topics.edit', $topic));

        $response->assertOk();
        $response->assertSeeInOrder([
            '<ul id="community_topic_photo_1">',
            $image->file->thumbnailUrl(120, 120, square: true),
            '<input type="checkbox" name="remove_images[]" value="'.$image->id.'"',
            '<ul id="community_topic_photo_2">',
            '<input type="file" name="images[]" id="community_topic_photo_2_photo"',
            '<ul id="community_topic_photo_3">',
            '<input type="file" name="images[]" id="community_topic_photo_3_photo"',
        ], false);
        $this->assertSame(2, substr_count((string) $response->getContent(), 'name="images[]"'));
        $this->assertSame(1, substr_count((string) $response->getContent(), 'name="remove_images[]"'));
    }

    public function test_rows_follow_the_persisted_slot_numbers_so_a_freed_slot_stays_free(): void
    {
        $group = Group::factory()->create();
        $author = $this->joined($group);
        $topic = app(CreateTopic::class)($author, $group, new GroupTopicFormData(name: 'T', body: 'B'), [
            UploadedFile::fake()->image('a.png', 20, 20), UploadedFile::fake()->image('b.png', 20, 20), UploadedFile::fake()->image('c.png', 20, 20),
        ]);
        $second = $topic->images()->where('number', 2)->firstOrFail();
        $this->actingAs($author)->post(route('group.topics.update', $topic), [
            'name' => 'T', 'body' => 'B', 'remove_images' => [$second->id],
        ])->assertRedirect();

        $response = $this->actingAs($author)->get(route('group.topics.edit', $topic));

        $response->assertOk();
        $response->assertSeeInOrder([
            '<ul id="community_topic_photo_1">', 'name="remove_images[]"',
            '<ul id="community_topic_photo_2">', '<input type="file" name="images[]" id="community_topic_photo_2_photo"',
            '<ul id="community_topic_photo_3">', 'name="remove_images[]"',
        ], false);
        $this->assertSame(1, substr_count((string) $response->getContent(), 'name="images[]"'));
    }
}
