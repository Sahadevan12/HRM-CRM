import { NavItem } from '@/types';
import { Store } from 'lucide-react';

// Auto-loaded by resources/js/utils/menu.ts for companies that have the Pos module.
export const posCompanyMenu = (t: (key: string) => string): NavItem[] => [
    {
        title: t('Point of Sale'),
        icon: Store,
        order: 440,
        children: [
            {
                title: t('POS Terminal'),
                href: route('pos.terminal'),
                permission: 'manage-pos',
            },
            {
                title: t('POS Orders'),
                href: route('pos.orders.index'),
                permission: 'manage-pos-orders',
            },
            {
                title: t('POS Reports'),
                href: route('pos.reports.index'),
                permission: 'manage-pos-reports',
            },
            // <menu-items>
        ],
    },
];