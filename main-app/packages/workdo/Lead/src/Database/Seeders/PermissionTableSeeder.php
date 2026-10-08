<?php

namespace Workdo\Lead\Database\Seeders;

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
            ['name' => 'manage-pipelines', 'module' => 'pipelines', 'label' => 'Manage Pipelines'],
            ['name' => 'create-pipelines', 'module' => 'pipelines', 'label' => 'Create Pipelines'],
            ['name' => 'edit-pipelines', 'module' => 'pipelines', 'label' => 'Edit Pipelines'],
            ['name' => 'delete-pipelines', 'module' => 'pipelines', 'label' => 'Delete Pipelines'],
            ['name' => 'manage-lead-stages', 'module' => 'lead-stages', 'label' => 'Manage Lead Stages'],
            ['name' => 'create-lead-stages', 'module' => 'lead-stages', 'label' => 'Create Lead Stages'],
            ['name' => 'edit-lead-stages', 'module' => 'lead-stages', 'label' => 'Edit Lead Stages'],
            ['name' => 'delete-lead-stages', 'module' => 'lead-stages', 'label' => 'Delete Lead Stages'],
            ['name' => 'manage-deal-stages', 'module' => 'deal-stages', 'label' => 'Manage Deal Stages'],
            ['name' => 'create-deal-stages', 'module' => 'deal-stages', 'label' => 'Create Deal Stages'],
            ['name' => 'edit-deal-stages', 'module' => 'deal-stages', 'label' => 'Edit Deal Stages'],
            ['name' => 'delete-deal-stages', 'module' => 'deal-stages', 'label' => 'Delete Deal Stages'],
            ['name' => 'manage-labels', 'module' => 'labels', 'label' => 'Manage Labels'],
            ['name' => 'create-labels', 'module' => 'labels', 'label' => 'Create Labels'],
            ['name' => 'edit-labels', 'module' => 'labels', 'label' => 'Edit Labels'],
            ['name' => 'delete-labels', 'module' => 'labels', 'label' => 'Delete Labels'],
            ['name' => 'manage-sources', 'module' => 'sources', 'label' => 'Manage Sources'],
            ['name' => 'create-sources', 'module' => 'sources', 'label' => 'Create Sources'],
            ['name' => 'edit-sources', 'module' => 'sources', 'label' => 'Edit Sources'],
            ['name' => 'delete-sources', 'module' => 'sources', 'label' => 'Delete Sources'],
            ['name' => 'manage-leads', 'module' => 'leads', 'label' => 'Manage Leads'],
            ['name' => 'create-leads', 'module' => 'leads', 'label' => 'Create Leads'],
            ['name' => 'edit-leads', 'module' => 'leads', 'label' => 'Edit Leads'],
            ['name' => 'delete-leads', 'module' => 'leads', 'label' => 'Delete Leads'],
            ['name' => 'move-leads', 'module' => 'leads', 'label' => 'Move Leads'],
            ['name' => 'view-all-leads', 'module' => 'leads', 'label' => 'View All Leads'],
            ['name' => 'manage-lead-tasks', 'module' => 'leads', 'label' => 'Manage Lead Tasks'],
            ['name' => 'manage-lead-calls', 'module' => 'leads', 'label' => 'Manage Lead Calls'],
            ['name' => 'manage-lead-emails', 'module' => 'leads', 'label' => 'Manage Lead Emails'],
            ['name' => 'manage-lead-discussions', 'module' => 'leads', 'label' => 'Manage Lead Discussions'],
            ['name' => 'manage-lead-files', 'module' => 'leads', 'label' => 'Manage Lead Files'],
            ['name' => 'convert-leads', 'module' => 'leads', 'label' => 'Convert Leads'],
            ['name' => 'manage-deals', 'module' => 'deals', 'label' => 'Manage Deals'],
            ['name' => 'create-deals', 'module' => 'deals', 'label' => 'Create Deals'],
            ['name' => 'edit-deals', 'module' => 'deals', 'label' => 'Edit Deals'],
            ['name' => 'delete-deals', 'module' => 'deals', 'label' => 'Delete Deals'],
            ['name' => 'move-deals', 'module' => 'deals', 'label' => 'Move Deals'],
            ['name' => 'view-all-deals', 'module' => 'deals', 'label' => 'View All Deals'],
            ['name' => 'change-deal-status', 'module' => 'deals', 'label' => 'Change Deal Status'],
            ['name' => 'manage-deal-tasks', 'module' => 'deals', 'label' => 'Manage Deal Tasks'],
            ['name' => 'manage-deal-calls', 'module' => 'deals', 'label' => 'Manage Deal Calls'],
            ['name' => 'manage-deal-emails', 'module' => 'deals', 'label' => 'Manage Deal Emails'],
            ['name' => 'manage-deal-discussions', 'module' => 'deals', 'label' => 'Manage Deal Discussions'],
            ['name' => 'manage-deal-files', 'module' => 'deals', 'label' => 'Manage Deal Files'],
            ['name' => 'view-crm-dashboard', 'module' => 'crm-reports', 'label' => 'View CRM Dashboard'],
            ['name' => 'view-crm-reports', 'module' => 'crm-reports', 'label' => 'View CRM Reports'],
            // <permissions>
        ];

        $company = Role::where('name', 'company')->first();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                ['module' => $perm['module'], 'label' => $perm['label'], 'add_on' => 'Lead']
            );
        }

        // ONE call for all of them: givePermissionTo flushes spatie's permission cache every time,
        // so granting one by one gets very slow as the number of permissions grows
        $company?->givePermissionTo(array_column($permissions, 'name'));
    }
}
