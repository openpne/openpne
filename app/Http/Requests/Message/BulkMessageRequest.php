<?php

namespace App\Http\Requests\Message;

use App\Features\Message\MessageBox;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A bulk action submitted from a message list (OpenPNE 3 MessageDeleteForm). `box` names the list
 * the checkboxes came from and `action` what to do with the selected `ids`. The per-row scoping
 * lives in the actions, so an id the viewer does not own simply matches nothing. `confirm` marks the
 * second step of a purge, which is gated behind a confirmation page.
 */
class BulkMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'box' => ['required', Rule::enum(MessageBox::class)],
            'action' => ['required', 'in:delete,restore,purge'],
            'ids' => ['array'],
            'ids.*' => ['integer'],
            'confirm' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // The trash box restores or purges; the other boxes only move to trash. Reject any other
        // pairing so a tampered form cannot, say, "purge" a sent message.
        $validator->after(function (Validator $validator): void {
            $box = $this->enum('box', MessageBox::class);
            if ($box === null) {
                return;
            }
            $allowed = $box === MessageBox::Trash ? ['restore', 'purge'] : ['delete'];
            if (! in_array($this->input('action'), $allowed, true)) {
                $validator->errors()->add('action', 'The action is not valid for this box.');
            }
        });
    }

    public function box(): MessageBox
    {
        return $this->enum('box', MessageBox::class);
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
