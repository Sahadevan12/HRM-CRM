import { NavItem } from '@/types';
import { ShoppingCart } from 'lucide-react';

// Auto-loaded by resources/js/utils/menu.ts for companies that have the SalesPurchase module.
export const salesPurchaseCompanyMenu = (t: (key: string) => string): NavItem[] => [
    {
        title: t('Sales & Purchases'),
        icon: ShoppingCart,
        order: 420,
        children: [
            {
                title: t('Sales Proposals'),
                href: route('salespurchase.sales-proposals.index'),
                permission: 'manage-sales-proposals',
            },
            {
                title: t('Sales Invoices'),
                href: route('salespurchase.sales-invoices.index'),
                permission: 'manage-sales-invoices',
            },
            {
                title: t('Sales Returns'),
                href: route('salespurchase.sales-returns.index'),
                permission: 'manage-sales-returns',
            },
            {
                title: t('Purchase Invoices'),
                href: route('salespurchase.purchase-invoices.index'),
                permission: 'manage-purchase-invoices',
            },
            {
                title: t('Purchase Returns'),
                href: route('salespurchase.purchase-returns.index'),
                permission: 'manage-purchase-returns',
            },
            // <menu-items>
        ],
    },
];
