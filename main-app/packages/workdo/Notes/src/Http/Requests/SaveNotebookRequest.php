<?php

namespace Workdo\Notes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveNotebookRequest extends FormRequest
{
    /** Permission checks live in the controller (create-/edit-notebooks). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'is_active' => 'boolean',
        ];
    }
}
