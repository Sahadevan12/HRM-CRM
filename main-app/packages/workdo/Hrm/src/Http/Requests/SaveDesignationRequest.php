<?php

namespace Workdo\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveDesignationRequest extends FormRequest
{
    /** Permission checks live in the controller (create-/edit-designations). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('created_by', creatorId())],
        ];
    }
}
