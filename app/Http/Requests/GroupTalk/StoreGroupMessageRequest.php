<?php

namespace App\Http\Requests\GroupTalk;

use App\Http\Requests\Concerns\MentionRules;
use App\Http\Requests\Concerns\PostImageRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreGroupMessageRequest extends FormRequest
{
    /**
     * Code points, not bytes: `max` measures a string with mb_strlen, the same unit the mention
     * ranges will be recorded in and the one JavaScript's Array.from() agrees with. Well inside the
     * TEXT column even at four bytes a point.
     */
    public const MAX_BODY = 5000;

    protected function prepareForValidation(): void
    {
        // Before the length check, so a CRLF body is not measured a line-break longer than the one
        // the member typed — and so a stored body's newlines match the offsets mentions will carry.
        if (is_string($this->input('body'))) {
            $this->merge(['body' => MentionRules::normalizeNewlines($this->input('body'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.self::MAX_BODY],
            // The shared `images[]` shape, capped at PostImages::MAX_IMAGES like every other post
            // with attachments. A refusal takes the whole message down, so nothing is half-sent.
            ...PostImageRules::rules(),
            // The single-image wire this endpoint spoke before it took three. A talk tab stays open
            // across a deploy and keeps sending; ignoring its `image` would 201 the body and
            // silently drop the file. Transitional: remove once no session predates images[].
            'image' => ['prohibits:images', ...PostImageRules::single()],
            ...MentionRules::rules(self::MAX_BODY),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [...PostImageRules::attributes(), 'image' => __('Images')];
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
}
