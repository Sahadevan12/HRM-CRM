import { NavItem } from '@/types';
import { Handshake } from 'lucide-react';

// Auto-loaded by resources/js/utils/menu.ts for companies that have the Lead module.
export const leadCompanyMenu = (t: (key: string) => string): NavItem[] => [
    {
        title: t('CRM'),
        icon: Handshake,
        order: 460,
        children: [
            { title: t('CRM Dashboard'), href: route('crm.dashboard'), permission: 'view-crm-dashboard' },
            { title: t('My Tasks'), href: route('crm.tasks'), permission: 'manage-lead-tasks' },
            { title: t('Leads'), href: route('crm.leads.index'), permission: 'manage-leads' },
            { title: t('Deals'), href: route('crm.deals.index'), permission: 'manage-deals' },
            { title: t('CRM Reports'), href: route('crm.reports'), permission: 'view-crm-reports' },
            { title: t('Workflows'), href: route('crm.workflows.index'), permission: 'manage-pipelines' },
            { title: t('CRM Setup'), href: route('crm.setup.index'), permission: 'manage-pipelines' },
            // <menu-items>
        ],
    },
];
