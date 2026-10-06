import { NavItem } from '@/types';
import { Boxes } from 'lucide-react';

// Auto-loaded by resources/js/utils/menu.ts for companies that have the ProductService module.
export const productServiceCompanyMenu = (t: (key: string) => string): NavItem[] => [
    {
        title: t('Products & Services'),
        icon: Boxes,
        order: 410,
        children: [
            {
                title: t('Products'),
                href: route('productservice.products.index'),
                permission: 'manage-products',
            },
            {
                title: t('Product Categories'),
                href: route('productservice.product-categories.index'),
                permission: 'manage-product-categories',
            },
            {
                title: t('Product Units'),
                href: route('productservice.product-units.index'),
                permission: 'manage-product-units',
            },
            {
                title: t('Product Taxes'),
                href: route('productservice.product-taxes.index'),
                permission: 'manage-product-taxes',
            },
            {
                title: t('Warehouses'),
                href: route('productservice.warehouses.index'),
                permission: 'manage-warehouses',
            },
            {
                title: t('Stock Transfers'),
                href: route('productservice.stock-transfers.index'),
                permission: 'manage-transfers',
            },
            // <menu-items>
        ],
    },
];
