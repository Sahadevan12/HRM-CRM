<?php

namespace Workdo\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSalaryComponentRequest extends FormRequest
{
    /** Permission checks live in the controller (create-/edit-salary-components). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(['allowance', 'deduction'])],
            'calc' => ['required', Rule::in(['fixed', 'percent'])],
            'amount' => ['required', 'numeric', 'min:0', $this->input('calc') === 'percent' ? 'max:100' : 'max:9999999999'], // percent of the basic salary
            'description' => 'nullable|string|max:5000',
        ];
    }
}
