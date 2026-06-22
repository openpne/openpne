<?php

namespace App\Http\Requests\Message;

use App\Features\Message\MessageComposeData;
use App\Http\Requests\Concerns\PostImageRules;
use App\Models\Message;
use Closure;
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
            // A reply link must point at a message the viewer is a party to, not just any message id,
            // so a crafted parent_id/thread_id can never reference a stranger's message.
            'parent_id' => ['nullable', 'integer', $this->partyToMessage()],
            'thread_id' => ['nullable', 'integer', $this->partyToMessage()],
            ...PostImageRules::rules(),
        ];
    }

    /** Fails unless the value is a message the viewer sent or received. */
    private function partyToMessage(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $viewerId = $this->user()?->getKey();
            $isParty = Message::whereKey($value)
                ->where(fn ($q) => $q->where('sender_id', $viewerId)
                    ->orWhereHas('recipients', fn ($r) => $r->where('recipient_id', $viewerId)))
                ->exists();

            if (! $isParty) {
                $fail('The selected :attribute is invalid.');
            }
        };
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
