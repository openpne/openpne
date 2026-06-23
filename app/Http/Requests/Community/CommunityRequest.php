<?php

namespace App\Http\Requests\Community;

use App\Features\Community\Data\CommunityFormData;
use App\Features\Community\JoinPolicy;
use App\Http\Requests\Concerns\PostImageRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update a community. One request for both: OpenPNE 3 serves a single /community/edit,
 * so `?id=` (present on update) switches the unique-name ignore. Whether the actor may edit, and
 * whether the chosen category is member-creatable, are enforced in the controller/action.
 */
class CommunityRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->query('id');

        return [
            // OpenPNE 3 community.name is varchar(64) UNIQUE.
            'name' => ['required', 'string', 'max:64', Rule::unique('communities', 'name')->ignore($id)],
            'description' => ['nullable', 'string'],
            'register_policy' => ['required', Rule::in(array_map(static fn (JoinPolicy $p): int => $p->value, JoinPolicy::cases()))],
            'community_category_id' => ['nullable', 'integer', 'exists:community_categories,id'],
            // Single top image (OpenPNE 3 CommunityFileForm), with a remove toggle. The bytes are
            // handled in the action, not the DTO — same split as the topic/event image uploads.
            'image' => PostImageRules::single(),
            'remove_image' => ['boolean'],
        ];
    }

    public function toData(): CommunityFormData
    {
        $validated = $this->validated();

        return new CommunityFormData(
            name: $validated['name'],
            description: $validated['description'] ?? null,
            registerPolicy: JoinPolicy::from((int) $validated['register_policy']),
            categoryId: isset($validated['community_category_id']) ? (int) $validated['community_category_id'] : null,
        );
    }
}
