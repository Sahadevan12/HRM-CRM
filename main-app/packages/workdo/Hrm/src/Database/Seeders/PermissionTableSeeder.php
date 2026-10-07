<?php

namespace Workdo\Hrm\Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionTableSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            ['name' => 'manage-branches', 'module' => 'branches', 'label' => 'Manage Branches'],
            ['name' => 'create-branches', 'module' => 'branches', 'label' => 'Create Branches'],
            ['name' => 'edit-branches', 'module' => 'branches', 'label' => 'Edit Branches'],
            ['name' => 'delete-branches', 'module' => 'branches', 'label' => 'Delete Branches'],
            ['name' => 'manage-departments', 'module' => 'departments', 'label' => 'Manage Departments'],
            ['name' => 'create-departments', 'module' => 'departments', 'label' => 'Create Departments'],
            ['name' => 'edit-departments', 'module' => 'departments', 'label' => 'Edit Departments'],
            ['name' => 'delete-departments', 'module' => 'departments', 'label' => 'Delete Departments'],
            ['name' => 'manage-designations', 'module' => 'designations', 'label' => 'Manage Designations'],
            ['name' => 'create-designations', 'module' => 'designations', 'label' => 'Create Designations'],
            ['name' => 'edit-designations', 'module' => 'designations', 'label' => 'Edit Designations'],
            ['name' => 'delete-designations', 'module' => 'designations', 'label' => 'Delete Designations'],
            ['name' => 'manage-employee-document-types', 'module' => 'employee-document-types', 'label' => 'Manage Employee Document Types'],
            ['name' => 'create-employee-document-types', 'module' => 'employee-document-types', 'label' => 'Create Employee Document Types'],
            ['name' => 'edit-employee-document-types', 'module' => 'employee-document-types', 'label' => 'Edit Employee Document Types'],
            ['name' => 'delete-employee-document-types', 'module' => 'employee-document-types', 'label' => 'Delete Employee Document Types'],
            ['name' => 'manage-employees', 'module' => 'employees', 'label' => 'Manage Employees'],
            ['name' => 'create-employees', 'module' => 'employees', 'label' => 'Create Employees'],
            ['name' => 'edit-employees', 'module' => 'employees', 'label' => 'Edit Employees'],
            ['name' => 'delete-employees', 'module' => 'employees', 'label' => 'Delete Employees'],
            ['name' => 'manage-employee-documents', 'module' => 'employees', 'label' => 'Manage Employee Documents'],
            ['name' => 'manage-shifts', 'module' => 'shifts', 'label' => 'Manage Shifts'],
            ['name' => 'create-shifts', 'module' => 'shifts', 'label' => 'Create Shifts'],
            ['name' => 'edit-shifts', 'module' => 'shifts', 'label' => 'Edit Shifts'],
            ['name' => 'delete-shifts', 'module' => 'shifts', 'label' => 'Delete Shifts'],
            ['name' => 'manage-holidays', 'module' => 'holidays', 'label' => 'Manage Holidays'],
            ['name' => 'create-holidays', 'module' => 'holidays', 'label' => 'Create Holidays'],
            ['name' => 'edit-holidays', 'module' => 'holidays', 'label' => 'Edit Holidays'],
            ['name' => 'delete-holidays', 'module' => 'holidays', 'label' => 'Delete Holidays'],
            ['name' => 'manage-leave-types', 'module' => 'leave-types', 'label' => 'Manage Leave Types'],
            ['name' => 'create-leave-types', 'module' => 'leave-types', 'label' => 'Create Leave Types'],
            ['name' => 'edit-leave-types', 'module' => 'leave-types', 'label' => 'Edit Leave Types'],
            ['name' => 'delete-leave-types', 'module' => 'leave-types', 'label' => 'Delete Leave Types'],
            ['name' => 'manage-ip-restrictions', 'module' => 'ip-restrictions', 'label' => 'Manage Ip Restrictions'],
            ['name' => 'create-ip-restrictions', 'module' => 'ip-restrictions', 'label' => 'Create Ip Restrictions'],
            ['name' => 'edit-ip-restrictions', 'module' => 'ip-restrictions', 'label' => 'Edit Ip Restrictions'],
            ['name' => 'delete-ip-restrictions', 'module' => 'ip-restrictions', 'label' => 'Delete Ip Restrictions'],
            ['name' => 'manage-attendances', 'module' => 'attendances', 'label' => 'Manage Attendances'],
            ['name' => 'create-attendances', 'module' => 'attendances', 'label' => 'Create Attendances'],
            ['name' => 'edit-attendances', 'module' => 'attendances', 'label' => 'Edit Attendances'],
            ['name' => 'delete-attendances', 'module' => 'attendances', 'label' => 'Delete Attendances'],
            ['name' => 'manage-leave-applications', 'module' => 'leave-applications', 'label' => 'Manage Leave Applications'],
            ['name' => 'delete-leave-applications', 'module' => 'leave-applications', 'label' => 'Delete Leave Applications'],
            ['name' => 'approve-leave-applications', 'module' => 'leave-applications', 'label' => 'Approve Leave Applications'],
            ['name' => 'manage-hrm-settings', 'module' => 'hrm-settings', 'label' => 'Manage HRM Settings'],
            ['name' => 'clock-attendance', 'module' => 'attendances', 'label' => 'Clock In / Out'],
            ['name' => 'apply-leave', 'module' => 'leave-applications', 'label' => 'Apply For Leave'],
            ['name' => 'manage-salary-components', 'module' => 'salary-components', 'label' => 'Manage Salary Components'],
            ['name' => 'create-salary-components', 'module' => 'salary-components', 'label' => 'Create Salary Components'],
            ['name' => 'edit-salary-components', 'module' => 'salary-components', 'label' => 'Edit Salary Components'],
            ['name' => 'delete-salary-components', 'module' => 'salary-components', 'label' => 'Delete Salary Components'],
            ['name' => 'manage-salary-setup', 'module' => 'salary-setup', 'label' => 'Manage Salary Setup'],
            ['name' => 'manage-loans', 'module' => 'loans', 'label' => 'Manage Loans'],
            ['name' => 'create-loans', 'module' => 'loans', 'label' => 'Create Loans'],
            ['name' => 'edit-loans', 'module' => 'loans', 'label' => 'Edit Loans'],
            ['name' => 'delete-loans', 'module' => 'loans', 'label' => 'Delete Loans'],
            ['name' => 'manage-payrolls', 'module' => 'payrolls', 'label' => 'Manage Payrolls'],
            ['name' => 'create-payrolls', 'module' => 'payrolls', 'label' => 'Create Payrolls'],
            ['name' => 'approve-payrolls', 'module' => 'payrolls', 'label' => 'Approve Payrolls'],
            ['name' => 'pay-payrolls', 'module' => 'payrolls', 'label' => 'Pay Payrolls'],
            ['name' => 'delete-payrolls', 'module' => 'payrolls', 'label' => 'Delete Payrolls'],
            ['name' => 'view-payslips', 'module' => 'payrolls', 'label' => 'View Own Payslips'],
            ['name' => 'manage-award-types', 'module' => 'award-types', 'label' => 'Manage Award Types'],
            ['name' => 'create-award-types', 'module' => 'award-types', 'label' => 'Create Award Types'],
            ['name' => 'edit-award-types', 'module' => 'award-types', 'label' => 'Edit Award Types'],
            ['name' => 'delete-award-types', 'module' => 'award-types', 'label' => 'Delete Award Types'],
            ['name' => 'manage-awards', 'module' => 'awards', 'label' => 'Manage Awards'],
            ['name' => 'create-awards', 'module' => 'awards', 'label' => 'Create Awards'],
            ['name' => 'edit-awards', 'module' => 'awards', 'label' => 'Edit Awards'],
            ['name' => 'delete-awards', 'module' => 'awards', 'label' => 'Delete Awards'],
            ['name' => 'manage-warnings', 'module' => 'warnings', 'label' => 'Manage Warnings'],
            ['name' => 'create-warnings', 'module' => 'warnings', 'label' => 'Create Warnings'],
            ['name' => 'edit-warnings', 'module' => 'warnings', 'label' => 'Edit Warnings'],
            ['name' => 'delete-warnings', 'module' => 'warnings', 'label' => 'Delete Warnings'],
            ['name' => 'manage-complaints', 'module' => 'complaints', 'label' => 'Manage Complaints'],
            ['name' => 'create-complaints', 'module' => 'complaints', 'label' => 'Create Complaints'],
            ['name' => 'edit-complaints', 'module' => 'complaints', 'label' => 'Edit Complaints'],
            ['name' => 'delete-complaints', 'module' => 'complaints', 'label' => 'Delete Complaints'],
            ['name' => 'manage-promotions', 'module' => 'promotions', 'label' => 'Manage Promotions'],
            ['name' => 'create-promotions', 'module' => 'promotions', 'label' => 'Create Promotions'],
            ['name' => 'manage-resignations', 'module' => 'resignations', 'label' => 'Manage Resignations'],
            ['name' => 'create-resignations', 'module' => 'resignations', 'label' => 'Create Resignations'],
            ['name' => 'approve-resignations', 'module' => 'resignations', 'label' => 'Approve Resignations'],
            ['name' => 'delete-resignations', 'module' => 'resignations', 'label' => 'Delete Resignations'],
            ['name' => 'manage-terminations', 'module' => 'terminations', 'label' => 'Manage Terminations'],
            ['name' => 'create-terminations', 'module' => 'terminations', 'label' => 'Create Terminations'],
            ['name' => 'approve-terminations', 'module' => 'terminations', 'label' => 'Approve Terminations'],
            ['name' => 'delete-terminations', 'module' => 'terminations', 'label' => 'Delete Terminations'],
            ['name' => 'manage-transfers', 'module' => 'transfers', 'label' => 'Manage Transfers'],
            ['name' => 'create-transfers', 'module' => 'transfers', 'label' => 'Create Transfers'],
            ['name' => 'approve-transfers', 'module' => 'transfers', 'label' => 'Approve Transfers'],
            ['name' => 'delete-transfers', 'module' => 'transfers', 'label' => 'Delete Transfers'],
            ['name' => 'manage-announcement-categories', 'module' => 'announcement-categories', 'label' => 'Manage Announcement Categories'],
            ['name' => 'create-announcement-categories', 'module' => 'announcement-categories', 'label' => 'Create Announcement Categories'],
            ['name' => 'edit-announcement-categories', 'module' => 'announcement-categories', 'label' => 'Edit Announcement Categories'],
            ['name' => 'delete-announcement-categories', 'module' => 'announcement-categories', 'label' => 'Delete Announcement Categories'],
            ['name' => 'manage-event-types', 'module' => 'event-types', 'label' => 'Manage Event Types'],
            ['name' => 'create-event-types', 'module' => 'event-types', 'label' => 'Create Event Types'],
            ['name' => 'edit-event-types', 'module' => 'event-types', 'label' => 'Edit Event Types'],
            ['name' => 'delete-event-types', 'module' => 'event-types', 'label' => 'Delete Event Types'],
            ['name' => 'manage-announcements', 'module' => 'announcements', 'label' => 'Manage Announcements'],
            ['name' => 'create-announcements', 'module' => 'announcements', 'label' => 'Create Announcements'],
            ['name' => 'edit-announcements', 'module' => 'announcements', 'label' => 'Edit Announcements'],
            ['name' => 'delete-announcements', 'module' => 'announcements', 'label' => 'Delete Announcements'],
            ['name' => 'view-announcements', 'module' => 'announcements', 'label' => 'View Announcements'],
            ['name' => 'manage-events', 'module' => 'events', 'label' => 'Manage Events'],
            ['name' => 'create-events', 'module' => 'events', 'label' => 'Create Events'],
            ['name' => 'edit-events', 'module' => 'events', 'label' => 'Edit Events'],
            ['name' => 'delete-events', 'module' => 'events', 'label' => 'Delete Events'],
            ['name' => 'view-events', 'module' => 'events', 'label' => 'View Events'],
            ['name' => 'manage-hrm-documents', 'module' => 'hrm-documents', 'label' => 'Manage HR Documents'],
            ['name' => 'create-hrm-documents', 'module' => 'hrm-documents', 'label' => 'Upload HR Documents'],
            ['name' => 'delete-hrm-documents', 'module' => 'hrm-documents', 'label' => 'Delete HR Documents'],
            ['name' => 'view-hrm-documents', 'module' => 'hrm-documents', 'label' => 'View HR Documents'],
            ['name' => 'view-hrm-dashboard', 'module' => 'hrm-dashboard', 'label' => 'View HRM Dashboard'],
            // <permissions>
        ];

        $company = Role::where('name', 'company')->first();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                ['module' => $perm['module'], 'label' => $perm['label'], 'add_on' => 'Hrm']
            );
        }

        // ONE call for all of them: givePermissionTo flushes spatie's permission cache every time,
        // so granting one by one gets very slow as the number of permissions grows
        $company?->givePermissionTo(array_column($permissions, 'name'));

        // every employee (system "staff" role) can clock in/out, apply for leave and read their own payslips
        Role::where('name', 'staff')->whereNull('created_by')->first()?->givePermissionTo(['clock-attendance', 'apply-leave', 'view-payslips', 'view-announcements', 'view-events', 'view-hrm-documents']);
    }
}
