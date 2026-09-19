<?php

namespace App\Http\Requests\Reactions;

use App\Features\Reactions\ReactionVocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReactionRequest extends FormRequest
{
    /**
     * What may be added is the site's vocabulary and nothing else — the picker offers exactly this
     * set, so anything else is a client that made it up. Not injected: each surface validates these
     * rules after its own gate, so a refused request cannot tell an invalid payload from a valid one.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'emoji' => ['required', 'string', Rule::in(ReactionVocabulary::all())],
        ];
    }

    /**
     * Bounded by the column, not the vocabulary, so an emoji the site withdrew can still be removed.
     *
     * @return array<string, mixed>
     */
    public static function removeRules(): array
    {
        return ['emoji' => ['required', 'string', 'max:32']];
    }
}
