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
        $body = $this->input('body');

        if ($body === null) {
            // ConvertEmptyStringsToNull has turned the composer's empty field into null; back to a
            // string so `body === ''` is the one shape "no words" takes and the write always receives a string.
            $this->merge(['body' => '']);
        } elseif (is_string($body)) {
            // Before the length check, so a CRLF body — which is what multipart puts on the wire — is
            // not measured a line-break longer than the one the member typed.
            $this->merge(['body' => MentionRules::normalizeNewlines($body)]);
        }
        // Anything else is left exactly as it came, for the `string` rule to refuse: coercing it
        // here would let an attachment carry a body of the wrong type past validation.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // The body may be empty when something is attached; `required_without` is implicit, so
            // `nullable` does not stop it being asked.
            'body' => ['nullable', 'string', 'max:'.self::MAX_BODY, 'required_without:images'],
            ...PostImageRules::rules(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return PostImageRules::attributes();
    }

    /**
     * The default names the upload field to say the body is required — a sentence about the wire,
     * not about what the member has to do.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['body.required_without' => __('Enter a message or attach an image.')];
    }

    /** @return array<int, UploadedFile> */
    public function pickedImages(): array
    {
        return $this->file('images', []);
    }
}
