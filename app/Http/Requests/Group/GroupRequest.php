<?php

namespace App\Http\Requests\Group;

use App\Features\Group\Data\GroupFormData;
use App\Features\Group\JoinPolicy;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;
use App\Http\Requests\Concerns\PostImageRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update a community. One request for both: OpenPNE 3 serves a single /community/edit,
 * so `?id=` (present on update) switches the unique-name ignore. Whether the actor may edit, and
 * whether the chosen category is member-creatable, are enforced in the controller/action.
 */
class GroupRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Modern update routes carry the group in the path ({group}); Classic uses ?id=.
        $id = $this->route('group') ?? $this->query('id');

        return [
            // OpenPNE 3 community.name is varchar(64) UNIQUE.
            'name' => ['required', 'string', 'max:64', Rule::unique('groups', 'name')->ignore($id)],
            'description' => ['nullable', 'string'],
            // Enum fields travel as slugs (the enums' own rule: never the raw int) and are
            // REQUIRED: an absent-means-default here would let a partial payload silently reopen
            // a group that was members-only — both forms always submit all three.
            'register_policy' => ['required', 'string', Rule::in(array_map(static fn (JoinPolicy $p): string => $p->slug(), JoinPolicy::cases()))],
            'topic_read_access' => ['required', 'string', Rule::in(array_map(static fn (TopicReadAccess $a): string => $a->slug(), TopicReadAccess::cases()))],
            'topic_post_authority' => ['required', 'string', Rule::in(array_map(static fn (TopicPostAuthority $a): string => $a->slug(), TopicPostAuthority::cases()))],
            'group_category_id' => ['nullable', 'integer', 'exists:group_categories,id'],
            'is_join_notification_enabled' => ['boolean'],
            // Single top image (OpenPNE 3 CommunityFileForm), with a remove toggle. The bytes are
            // handled in the action, not the DTO — same split as the topic/event image uploads.
            'image' => PostImageRules::single(),
            'remove_image' => ['boolean'],
        ];
    }

    public function toData(): GroupFormData
    {
        $validated = $this->validated();

        return new GroupFormData(
            name: $validated['name'],
            description: $validated['description'] ?? null,
            registerPolicy: JoinPolicy::fromSlug($validated['register_policy']),
            categoryId: isset($validated['group_category_id']) ? (int) $validated['group_category_id'] : null,
            // Default on (OpenPNE 3 treats an absent value as on): both edit forms always submit the
            // field (Modern sends the boolean, Classic via a hidden 0), so an absent value is a non-form
            // caller, which should still get the default rather than a silent off.
            isJoinNotificationEnabled: $this->boolean('is_join_notification_enabled', true),
            topicReadAccess: TopicReadAccess::fromSlug($validated['topic_read_access']),
            topicPostAuthority: TopicPostAuthority::fromSlug($validated['topic_post_authority']),
        );
    }
}
