<?php

namespace App\Http\Requests\DirectMessage;

use App\Features\DirectMessage\DirectMessageBox;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A bulk action from a message list (OpenPNE 3 MessageDeleteForm). No ownership rule on `ids`: the
 * actions scope per row, so an id the viewer does not own matches nothing.
 */
class BulkDirectMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'box' => ['required', Rule::enum(DirectMessageBox::class)],
            'action' => ['required', 'in:delete,restore,purge'],
            'ids' => ['array'],
            'ids.*' => ['integer'],
            'confirm' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Any other box/action pairing is refused so a tampered form cannot purge a sent message.
        $validator->after(function (Validator $validator): void {
            $box = $this->enum('box', DirectMessageBox::class);
            if ($box === null) {
                return;
            }
            $allowed = $box === DirectMessageBox::Trash ? ['restore', 'purge'] : ['delete'];
            if (! in_array($this->input('action'), $allowed, true)) {
                $validator->errors()->add('action', 'The action is not valid for this box.');
            }
        });
    }

    public function box(): DirectMessageBox
    {
        return $this->enum('box', DirectMessageBox::class);
    }

    public function action(): string
    {
        return (string) $this->input('action');
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_values(array_map('intval', $this->input('ids', [])));
    }

    public function confirmed(): bool
    {
        return $this->boolean('confirm');
    }
}
