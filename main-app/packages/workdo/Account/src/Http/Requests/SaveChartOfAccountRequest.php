<?php

namespace Workdo\Account\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Workdo\Account\Models\ChartOfAccount;

class SaveChartOfAccountRequest extends FormRequest
{
    /** Permission checks live in the controller (create-/edit-chart-of-accounts). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-]+$/',
                Rule::unique('chart_of_accounts', 'code')->where('created_by', creatorId())->ignore($this->route('chart_of_account')?->id),
            ],
            'name' => 'required|string|max:255',
            'type' => ['required', Rule::in(ChartOfAccount::TYPES)],
            'is_bank' => 'boolean',
            'is_active' => 'boolean',
            'description' => 'nullable|string|max:5000',
        ];
    }

    /** Only asset accounts can be bank / cash accounts. */
    public function after(): array
    {
        return [
            function ($validator) {
                if ($this->boolean('is_bank') && $this->input('type') !== 'asset') {
                    $validator->errors()->add('is_bank', __('Only asset accounts can be bank or cash accounts.'));
                }
            },
        ];
    }
}
