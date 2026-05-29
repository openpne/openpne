<?php

namespace App\Http\Requests\Diary;

use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\Visibility;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateDiaryRequest extends FormRequest
{
    /**
     * Abort 404 for non-owners before validation runs, so invalid payloads
     * from non-owners get the same 404 as valid ones and don't leak existence.
     */
    public function authorize(): bool
    {
        $diary = $this->route('diary');
        $viewer = $this->user();
        if (! $diary instanceof Diary || ! $viewer instanceof Member || ! $viewer->is($diary->member)) {
            abort(404);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // No max length: OpenPNE 3 diary.title/body are TEXT with no validator limit.
            // Capping here would lock out re-editing of long migrated content.
            'title' => ['required', 'string'],
            'body' => ['required', 'string'],
            'visibility' => ['required', (new Enum(Visibility::class))->except([Visibility::Open])],
        ];
    }

    public function toData(): DiaryFormData
    {
        $validated = $this->validated();

        return new DiaryFormData(
            title: $validated['title'],
            body: $validated['body'],
            visibility: Visibility::from($validated['visibility']),
        );
    }
}
