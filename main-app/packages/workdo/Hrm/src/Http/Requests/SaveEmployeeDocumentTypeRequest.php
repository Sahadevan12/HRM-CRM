<?php

namespace Workdo\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEmployeeDocumentTypeRequest extends FormRequest
{
    /** Permission checks live in the controller (create-/edit-employee-document-types). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'is_required' => 'boolean',
        ];
    }
}
