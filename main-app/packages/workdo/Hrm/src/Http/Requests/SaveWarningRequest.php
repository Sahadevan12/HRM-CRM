<?php

namespace Workdo\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveWarningRequest extends FormRequest
{
    /** Permission checks live in the controller (create-/edit-warnings). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('created_by', creatorId())],
            'subject' => 'required|string|max:255',
            'severity' => ['required', Rule::in(['low', 'medium', 'high'])],
            'warning_date' => 'required|date',
            'description' => 'nullable|string|max:5000',
        ];
    }
}
