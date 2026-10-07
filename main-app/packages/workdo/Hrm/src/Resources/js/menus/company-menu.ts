import { NavItem } from '@/types';
import { UserCog } from 'lucide-react';

// Auto-loaded by resources/js/utils/menu.ts for companies that have the Hrm module.
export const hrmCompanyMenu = (t: (key: string) => string): NavItem[] => [
    {
        title: t('HRM'),
        icon: UserCog,
        order: 450,
        children: [
            {
                title: t('Employees'),
                href: route('hrm.employees.index'),
                permission: 'manage-employees',
            },
            {
                title: t('Organization'),
                children: [
                    {
                        title: t('Branches'),
                        href: route('hrm.branches.index'),
                        permission: 'manage-branches',
                    },
                    {
                        title: t('Departments'),
                        href: route('hrm.departments.index'),
                        permission: 'manage-departments',
                    },
                    {
                        title: t('Designations'),
                        href: route('hrm.designations.index'),
                        permission: 'manage-designations',
                    },
                    {
                        title: t('Document Types'),
                        href: route('hrm.employee-document-types.index'),
                        permission: 'manage-employee-document-types',
                    },
                ],
            },
            {
                title: t('Shifts'),
                href: route('hrm.shifts.index'),
                permission: 'manage-shifts',
            },
            {
                title: t('Holidays'),
                href: route('hrm.holidays.index'),
                permission: 'manage-holidays',
            },
            {
                title: t('Leave Types'),
                href: route('hrm.leave-types.index'),
                permission: 'manage-leave-types',
            },
            {
                title: t('Ip Restrictions'),
                href: route('hrm.ip-restrictions.index'),
                permission: 'manage-ip-restrictions',
            },
            {
                title: t('Attendance'),
                children: [
                    { title: t('My Attendance'), href: route('hrm.attendances.my'), permission: 'clock-attendance' },
                    { title: t('Attendance Records'), href: route('hrm.attendances.index'), permission: 'manage-attendances' },
                    { title: t('Monthly Summary'), href: route('hrm.attendances.summary'), permission: 'manage-attendances' },
                ],
            },
            {
                title: t('Leave'),
                children: [
                    { title: t('Leave Applications'), href: route('hrm.leave-applications.index'), permission: 'manage-leave-applications' },
                    { title: t('My Leave'), href: route('hrm.leave-applications.index'), permission: 'apply-leave' },
                    { title: t('Leave Balance'), href: route('hrm.leave-applications.balance'), permission: 'apply-leave' },
                ],
            },
            {
                title: t('HRM Settings'),
                href: route('hrm.settings.edit'),
                permission: 'manage-hrm-settings',
            },
            {
                title: t('Payroll'),
                children: [
                    { title: t('Salary Setup'), href: route('hrm.salary-setup.index'), permission: 'manage-salary-setup' },
                    { title: t('Salary Components'), href: route('hrm.salary-components.index'), permission: 'manage-salary-components' },
                    { title: t('Loans'), href: route('hrm.loans.index'), permission: 'manage-loans' },
                    { title: t('Payrolls'), href: route('hrm.payrolls.index'), permission: 'manage-payrolls' },
                    { title: t('My Payslips'), href: route('hrm.payslips.my'), permission: 'view-payslips' },
                ],
            },
            // <menu-items>
        ],
    },
];