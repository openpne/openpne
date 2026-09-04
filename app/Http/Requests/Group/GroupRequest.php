<?php

namespace App\Http\Requests\Group;

use App\Features\Group\Data\GroupFormData;
use App\Features\Group\JoinPolicy;
use App\Features\GroupTopic\TopicPostAuthority;
use App\Features\GroupTopic\TopicReadAccess;
use App\Http\Requests\Concerns\PostImageRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One request for create and update, as OpenPNE 3 serves a single /community/edit; `?id=` switches
 * the unique-name ignore. Whether the actor may edit, and whether the category is member-creatable,
 * are enforced in the action.
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
            // Single top image (OpenPNE 3 CommunityFileForm); the bytes are handled in the action, not the DTO.
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
            // Default on, as OpenPNE 3 treats an absent value: both forms always submit the field, so
            // an absent value is a non-form caller that should still get the default, not a silent off.
            isJoinNotificationEnabled: $this->boolean('is_join_notification_enabled', true),
            topicReadAccess: TopicReadAccess::fromSlug($validated['topic_read_access']),
            topicPostAuthority: TopicPostAuthority::fromSlug($validated['topic_post_authority']),
        );
    }
}
