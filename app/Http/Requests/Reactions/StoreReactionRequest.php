<?php

namespace App\Http\Requests\Reactions;

use App\Features\Reactions\ReactionVocabulary;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReactionRequest extends FormRequest
{
    /**
     * What may be added is the site's vocabulary and nothing else — the picker offers exactly this
     * set, so anything else is a client that made it up. What may be *removed* is deliberately not
     * bounded by it (RemoveReaction).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'emoji' => ['required', 'string', Rule::in(ReactionVocabulary::all())],
        ];
    }
}
