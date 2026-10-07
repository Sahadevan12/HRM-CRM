<?php

namespace Workdo\Hrm\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Workdo\Hrm\Models\Employee;

/** An employee is two rows: the login (users, type staff) and the HR profile (employees). They always change together. */
class EmployeeService
{
    /** Columns of the HR profile that the form may set. */
    private const PROFILE = [
        'branch_id', 'department_id', 'designation_id', 'shift_id', 'date_of_birth', 'gender', 'date_of_joining', 'employment_type', 'status',
        'phone', 'address_line', 'city', 'state', 'country', 'postal_code', 'emergency_name', 'emergency_relationship',
        'emergency_phone', 'bank_name', 'account_holder', 'account_number', 'bank_code', 'tax_id', 'basic_salary', 'hourly_rate', 'notes',
    ];

    /** @param array<string, mixed> $data */
    public function create(array $data, int $tenantId, ?int $actorId): Employee
    {
        return DB::transaction(function () use ($data, $tenantId, $actorId) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'mobile_no' => $data['phone'] ?? null,
                'password' => $data['password'],
                'type' => 'staff',
                'email_verified_at' => now(),
                'is_enable_login' => $data['is_enable_login'] ?? true,
                'creator_id' => $actorId,
                'created_by' => $tenantId,
            ]);
            $user->assignRole($data['role'] ?? 'staff');

            return Employee::create(array_intersect_key($data, array_flip(self::PROFILE)) + [
                'user_id' => $user->id,
                'employee_code' => $data['employee_code'] ?: $this->nextCode($tenantId),
                'creator_id' => $actorId,
                'created_by' => $tenantId,
            ])->load('user');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Employee $employee, array $data): Employee
    {
        return DB::transaction(function () use ($employee, $data) {
            $login = ['name' => $data['name'], 'email' => $data['email'], 'mobile_no' => $data['phone'] ?? null];
            if (array_key_exists('is_enable_login', $data)) {
                $login['is_enable_login'] = $data['is_enable_login'];
            }
            if (!empty($data['password'])) {
                $login['password'] = $data['password'];
            }
            $employee->user->update($login);

            if (!empty($data['role'])) {
                $employee->user->syncRoles([$data['role']]);
            }

            $employee->update(array_intersect_key($data, array_flip(self::PROFILE)) + ($data['employee_code'] ? ['employee_code' => $data['employee_code']] : []));

            return $employee->refresh()->load('user');
        });
    }

    /** Removes the employee, the login and the uploaded files. */
    public function delete(Employee $employee): void
    {
        $paths = $employee->documents()->pluck('file_path')->all();

        DB::transaction(fn () => $employee->user->delete()); // employee + documents cascade

        Storage::disk('local')->delete($paths);
    }

    /** EMP-0001, EMP-0002 ... per company (the unique index guards against two requests racing). */
    public function nextCode(int $tenantId): string
    {
        $last = Employee::where('created_by', $tenantId)->where('employee_code', 'like', 'EMP-%')->orderByDesc('id')->value('employee_code');

        return sprintf('EMP-%04d', $last ? ((int) substr($last, 4)) + 1 : 1);
    }
}
