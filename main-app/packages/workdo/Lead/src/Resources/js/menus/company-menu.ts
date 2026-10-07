import { NavItem } from '@/types';
import { Handshake } from 'lucide-react';

// Auto-loaded by resources/js/utils/menu.ts for companies that have the Lead module.
export const leadCompanyMenu = (t: (key: string) => string): NavItem[] => [
    {
        title: t('CRM'),
        icon: Handshake,
        order: 460,
        children: [
            { title: t('CRM Setup'), href: route('crm.setup.index'), permission: 'manage-pipelines' },
            // <menu-items>
        ],
    },
];
