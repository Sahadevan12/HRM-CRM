import { NavItem } from '@/types';
import { Calculator } from 'lucide-react';

// Auto-loaded by resources/js/utils/menu.ts for companies that have the Account module.
export const accountCompanyMenu = (t: (key: string) => string): NavItem[] => [
    {
        title: t('Accounting'),
        icon: Calculator,
        order: 430,
        children: [
            {
                title: t('Chart of Accounts'),
                href: route('account.chart-of-accounts.index'),
                permission: 'manage-chart-of-accounts',
            },
            {
                title: t('Journal Entries'),
                href: route('account.journal-entries.index'),
                permission: 'manage-journal-entries',
            },
            {
                title: t('Customer Payments'),
                href: route('account.customer-payments.index'),
                permission: 'manage-customer-payments',
            },
            {
                title: t('Vendor Payments'),
                href: route('account.vendor-payments.index'),
                permission: 'manage-vendor-payments',
            },
            {
                title: t('Reports'),
                href: route('account.reports.index'),
                permission: 'manage-account-reports',
            },
            // <menu-items>
        ],
    },
];