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

    public function test_an_occupied_slot_shows_the_photo_a_replacement_input_and_a_remove_checkbox(): void
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
            '<input type="file" name="images[]" id="community_topic_photo_1_photo"',
            '<input type="checkbox" name="remove_images[]" value="'.$image->id.'">',
            '<ul id="community_topic_photo_2">',
            '<input type="file" name="images[]" id="community_topic_photo_2_photo"',
        ], false);
        $this->assertSame(1, substr_count((string) $response->getContent(), 'name="remove_images[]"'));
    }
}
