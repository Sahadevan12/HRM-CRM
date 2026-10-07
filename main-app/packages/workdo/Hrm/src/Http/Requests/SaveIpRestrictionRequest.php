<?php

namespace Workdo\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveIpRestrictionRequest extends FormRequest
{
    /** Permission checks live in the controller (create-/edit-ip-restrictions). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ip_address' => ['required', 'ip', Rule::unique('ip_restrictions', 'ip_address')->where('created_by', creatorId())->ignore($this->route('ip_restriction')?->id)],
            'description' => 'nullable|string|max:255',
        ];
    }
}
