<?php

namespace App\Http\Requests\Message;

use App\Files\PostImages;
use App\Http\Requests\Concerns\PostImageRules;
use App\Models\Member;
use App\Models\Message;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edit one of the viewer's own drafts. Ownership + draft state are gated in authorize() (before
 * validation) so any other message id gets a uniform 404. `action=draft` keeps it a draft,
 * otherwise it sends.
 */
class UpdateDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $draft = Message::find($this->route('message'));
        $viewer = $this->user();
        // The viewer's own, still a draft, and not trashed/purged (a trashed draft is editable only
        // after restoring it). OpenPNE 3 isDraftOwner rejects a deleted draft.
        if (! $draft instanceof Message || ! $viewer instanceof Member
            || (int) $draft->sender_id !== (int) $viewer->getKey() || ! $draft->is_draft
            || $draft->sender_deleted_at !== null || $draft->sender_purged_at !== null) {
            abort(404);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['subject', 'body'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => rtrim($this->input($field))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string'],
            'body' => ['required', 'string'],
            'remove_images' => ['array'],
            'remove_images.*' => ['integer'],
            ...PostImageRules::rules(),
        ];
    }

    /**
     * Cross-field cap: the images kept (current minus the ones being removed) plus the new uploads
     * may not exceed MAX_IMAGES, so an ordinary add-without-removing past the cap is a validation
     * error rather than a 404 from the action. remove_images ids that aren't this draft's are
     * ignored, so a bogus id cannot inflate the kept count downwards.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $draft = Message::find($this->route('message'));
            if (! $draft instanceof Message) {
                return;
            }

            $currentIds = $draft->files()->pluck('id')->all();
            $removing = array_unique(array_intersect(array_map('intval', (array) $this->input('remove_images', [])), $currentIds));
            $kept = count($currentIds) - count($removing);

            if ($kept + count($this->file('images', [])) > PostImages::MAX_IMAGES) {
                $validator->errors()->add('images', __('A message can have at most :max images.', ['max' => PostImages::MAX_IMAGES]));
            }
        });
    }

    public function asDraft(): bool
    {
        return $this->input('action') === 'draft';
    }
}
