<?php

namespace App\Http\Requests\GroupTalk;

use App\Features\GroupTalk\TalkBody;
use App\Http\Requests\Concerns\MentionRules;
use App\Http\Requests\Concerns\PostImageRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreGroupMessageRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if ($body === null) {
            // What a picture-only message arrives as: the global ConvertEmptyStringsToNull turns the
            // composer's empty field into null, and TrimStrings has already made a whitespace-only
            // one empty. Back to a string, so `body === ''` is the one shape "no words" takes and
            // the write is handed a string either way; rules() decides whether that is allowed.
            $this->merge(['body' => '']);
        } elseif (is_string($body)) {
            // Before the length check, so a CRLF body is not measured a line-break longer than the
            // one the member typed — and so a stored body's newlines match the offsets mentions will
            // carry.
            $this->merge(['body' => TalkBody::normalize($body)]);
        }
        // Anything else is left exactly as it came, for the `string` rule to refuse: coercing it
        // here would let an attachment carry a body of the wrong type past validation.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // A picture is a message: the body may be empty when something is attached, whichever
            // wire named it. `required_without_all` is implicit, so it is still asked when `nullable`
            // would otherwise stop the chain.
            'body' => ['nullable', 'string', 'max:'.TalkBody::MAX, 'required_without_all:images,image'],
            // The shared `images[]` shape, capped at PostImages::MAX_IMAGES like every other post
            // with attachments. A refusal takes the whole message down, so nothing is half-sent.
            ...PostImageRules::rules(),
            // The single-image wire this endpoint spoke before it took three. A talk tab stays open
            // across a deploy and keeps sending; ignoring its `image` would 201 the body and
            // silently drop the file. Transitional: remove once no session predates images[].
            'image' => ['prohibits:images', ...PostImageRules::single()],
            // Only when the composer actually sent it: `sometimes` treats a key holding null as an
            // argument to check, and ConvertEmptyStringsToNull turns an empty field into exactly
            // that. Which message it names is resolved by the controller, against this group.
            'reply_to_message_id' => ['sometimes', 'integer', 'min:1'],
            ...MentionRules::rules(TalkBody::MAX),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...PostImageRules::attributes(), 'image' => __('Images')];
    }

    /**
     * The default names both upload fields to say the body is required — a sentence about the wire,
     * not about what the member has to do.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['body.required_without_all' => __('Enter a message or attach an image.')];
    }

    /**
     * The attachment set, whichever wire named it — `images[]`, or the legacy lone `image` from a
     * pre-deploy tab (see rules()).
     *
     * @return array<int, UploadedFile>
     */
    public function pickedImages(): array
    {
        $images = $this->file('images', []);
        $legacy = $this->file('image');

        return $images === [] && $legacy instanceof UploadedFile ? [$legacy] : $images;
    }

    /**
     * The picker's selection, not yet resolved against the body — that is the write's job
     * (App\Features\Timeline\Actions\ResolveMentions).
     *
     * @return list<array{member_id: int, offset: int, length: int}>
     */
    public function mentions(): array
    {
        return MentionRules::normalize($this->validated()['mentions'] ?? []);
    }

    /** The message being answered, as the composer named it; null when this answers nothing. */
    public function replyToMessageId(): ?int
    {
        $id = $this->validated()['reply_to_message_id'] ?? null;

        return $id === null ? null : (int) $id;
    }
}
