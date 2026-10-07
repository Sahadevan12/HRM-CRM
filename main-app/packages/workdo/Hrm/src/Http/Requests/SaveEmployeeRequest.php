<?php

namespace Workdo\Hrm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use Workdo\Hrm\Models\Employee;

class SaveEmployeeRequest extends FormRequest
{
    /** Permission checks live in the controller (create-/edit-employees). */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = creatorId();
        $employee = $this->route('employee');          // null on create
        $owned = fn (string $table) => Rule::exists($table, 'id')->where('created_by', $tenant);

        return [
            // login
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($employee?->user_id)],
            'password' => [$employee ? 'nullable' : 'required', Password::defaults()],
            'role' => ['nullable', 'string', Rule::in($this->assignableRoles($tenant))],
            'is_enable_login' => 'boolean',

            // profile
            'employee_code' => ['nullable', 'string', 'max:30', Rule::unique('employees', 'employee_code')->where('created_by', $tenant)->ignore($employee?->id)],
            'branch_id' => ['nullable', $owned('branches')],
            'department_id' => ['nullable', $owned('departments')],
            'designation_id' => ['nullable', $owned('designations')],
            'date_of_birth' => 'nullable|date|before:today',
            'gender' => ['nullable', Rule::in(Employee::GENDERS)],
            'date_of_joining' => 'nullable|date',
            'employment_type' => ['required', Rule::in(Employee::EMPLOYMENT_TYPES)],
            'status' => ['nullable', Rule::in(Employee::STATUSES)],
            'phone' => 'nullable|string|max:30',
            'address_line' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'emergency_name' => 'nullable|string|max:255',
            'emergency_relationship' => 'nullable|string|max:255',
            'emergency_phone' => 'nullable|string|max:30',
            'bank_name' => 'nullable|string|max:255',
            'account_holder' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'bank_code' => 'nullable|string|max:255',
            'tax_id' => 'nullable|string|max:255',
            'basic_salary' => 'nullable|numeric|min:0|max:9999999999',
            'hourly_rate' => 'nullable|numeric|min:0|max:9999999999',
            'notes' => 'nullable|string|max:5000',
        ];
    }

    /** Employees can be given the built-in staff role or any role this company created. */
    private function assignableRoles(int $tenant): array
    {
        return Role::where('created_by', $tenant)->orWhere(fn ($q) => $q->whereNull('created_by')->where('name', 'staff'))->pluck('name')->all();
    }

    /** Empty strings from the form become null / defaults so nothing odd is stored. */
    protected function prepareForValidation(): void
    {
        $nullable = ['branch_id', 'department_id', 'designation_id', 'date_of_birth', 'gender', 'date_of_joining', 'status', 'role', 'employee_code', 'basic_salary', 'hourly_rate'];

        $this->merge(collect($nullable)->mapWithKeys(fn ($key) => [$key => $this->input($key) === '' ? null : $this->input($key)])->all());
    }

    /** Numeric columns are NOT NULL with a default: store 0 instead of null. */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);

        if ($key === null) {
            $data['basic_salary'] = $data['basic_salary'] ?? 0;
            $data['hourly_rate'] = $data['hourly_rate'] ?? 0;
            if ($this->route('employee')) {
                $data['status'] = $data['status'] ?? $this->route('employee')->status; // an update never resets the status by accident
            } else {
                $data['status'] = $data['status'] ?? 'active';
            }
            $data['employee_code'] = $data['employee_code'] ?? null;
        }

        return $data;
    }
}
