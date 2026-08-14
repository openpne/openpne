<?php

namespace App\Http\Requests\DirectMessage;

use App\Http\Requests\Concerns\MentionRules;
use App\Http\Requests\Concerns\PostImageRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * A message written on the chat screen: a body, some images, and nothing else — no subject, no reply
 * links, and no mentions (a direct message parses none; docs/internals/direct-messages.md).
 *
 * The mailbox forms are untouched by this: they require a subject and cap nothing, because those are
 * OpenPNE 3's screens and their rules are OpenPNE 3's.
 */
class StoreChatMessageRequest extends FormRequest
{
    /**
     * Code points, not bytes: `max` measures a string with mb_strlen, the unit JavaScript's
     * Array.from() agrees with. Well inside the TEXT column even at four bytes a point.
     */
    public const MAX_BODY = 5000;

    protected function prepareForValidation(): void
    {
        // Before the length check, so a CRLF body — which is what multipart puts on the wire — is not
        // measured a line-break longer than the one the member typed.
        if (is_string($this->input('body'))) {
            $this->merge(['body' => MentionRules::normalizeNewlines($this->input('body'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
            // The shared `images[]` shape, capped at PostImages::MAX_IMAGES like every other post with
            // attachments. A refusal takes the whole message down, so nothing is half-sent.
            ...PostImageRules::rules(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return PostImageRules::attributes();
    }

    /** @return array<int, UploadedFile> */
    public function pickedImages(): array
    {
        return $this->file('images', []);
    }
}
