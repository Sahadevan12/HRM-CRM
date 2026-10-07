<?php

namespace Workdo\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveLeaveTypeRequest extends FormRequest
{
    /** Permission checks live in the controller (create-/edit-leave-types). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'days_per_year' => 'required|integer|min:0|max:366', // 0 = no yearly limit
            'is_paid' => 'boolean',
            'description' => 'nullable|string|max:5000',
        ];
    }
}
