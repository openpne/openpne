<?php

namespace App\Http\Requests\Message;

use App\Features\Message\MessageComposeData;
use App\Http\Requests\Concerns\PostImageRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Compose a new message (compose or reply). The recipient (`to`) and the reply links
 * (parent_id / thread_id) come as hidden fields; the action re-checks the recipient and the
 * send gate. `action=draft` saves a draft, otherwise it sends.
 */
class ComposeMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** OpenPNE 3 right-trims subject/body before validating, so whitespace-only is rejected as empty. */
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
            'to' => ['required', 'integer', 'exists:members,id'],
            // No max length: OpenPNE 3 subject/body are TEXT with no validator limit (required, trimmed).
            'subject' => ['required', 'string'],
            'body' => ['required', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:messages,id'],
            'thread_id' => ['nullable', 'integer', 'exists:messages,id'],
            ...PostImageRules::rules(),
        ];
    }

    public function asDraft(): bool
    {
        return $this->input('action') === 'draft';
    }

    public function toData(): MessageComposeData
    {
        $v = $this->validated();

        return new MessageComposeData(
            recipientId: (int) $v['to'],
            subject: $v['subject'],
            body: $v['body'],
            parentId: isset($v['parent_id']) ? (int) $v['parent_id'] : null,
            threadId: isset($v['thread_id']) ? (int) $v['thread_id'] : null,
        );
    }
}
