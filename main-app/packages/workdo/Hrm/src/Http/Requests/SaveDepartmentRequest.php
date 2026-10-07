<?php

namespace Workdo\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDepartmentRequest extends FormRequest
{
    /** Permission checks live in the controller (create-/edit-departments). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('created_by', creatorId())],
        ];
    }
}
