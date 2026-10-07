<?php

namespace Workdo\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAwardRequest extends FormRequest
{
    /** Permission checks live in the controller (create-/edit-awards). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('created_by', creatorId())],
            'award_type_id' => ['required', Rule::exists('award_types', 'id')->where('created_by', creatorId())],
            'award_date' => 'required|date',
            'gift' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
        ];
    }
}
