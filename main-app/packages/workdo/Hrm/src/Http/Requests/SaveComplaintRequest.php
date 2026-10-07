<?php

namespace Workdo\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveComplaintRequest extends FormRequest
{
    /** Permission checks live in the controller (create-/edit-complaints). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from_employee_id' => ['required', Rule::exists('employees', 'id')->where('created_by', creatorId())],
            'against_employee_id' => ['nullable', 'different:from_employee_id', Rule::exists('employees', 'id')->where('created_by', creatorId())],
            'subject' => 'required|string|max:255',
            'complaint_date' => 'required|date',
            'description' => 'required|string|max:5000',
            'status' => ['required', Rule::in(['open', 'resolved'])],
            'resolution' => 'nullable|string|max:5000',
        ];
    }
}
