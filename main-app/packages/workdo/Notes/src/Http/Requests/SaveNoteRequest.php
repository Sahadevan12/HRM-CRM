<?php

namespace Workdo\Notes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveNoteRequest extends FormRequest
{
    /** Permission checks live in the controller (create-/edit-notes). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'body' => 'nullable|string|max:5000',
            'priority' => 'required|integer|min:0|max:2147483647',
            'budget' => 'nullable|numeric|min:0|max:9999999999',
            'due_on' => 'nullable|date',
            'is_pinned' => 'boolean',
        ];
    }
}
