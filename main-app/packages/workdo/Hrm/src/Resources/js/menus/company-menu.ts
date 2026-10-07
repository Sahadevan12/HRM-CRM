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
            // <menu-items>
        ],
    },
];