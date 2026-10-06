import { NavItem } from '@/types';
import { Banknote, Building2, KeyRound, LayoutDashboard, Package, Puzzle, Receipt, Settings, Ticket, Users } from 'lucide-react';

/** Core (always available) sidebar items. Titles go through t() so they are translatable. */
export const coreMenu = (t: (key: string) => string): NavItem[] => [
    {
        name: 'dashboard',
        title: t('Dashboard'),
        href: route('dashboard'),
        icon: LayoutDashboard,
        permission: 'manage-dashboard',
        order: 10,
    },
    {
        title: t('Companies'),
        href: route('companies.index'),
        icon: Building2,
        permission: 'manage-companies',
        order: 90,
    },
    {
        title: t('Users'),
        href: route('users.index'),
        icon: Users,
        permission: 'manage-users',
        order: 100,
    },
    {
        title: t('Roles'),
        href: route('roles.index'),
        icon: KeyRound,
        permission: 'manage-roles',
        order: 110,
    },
    {
        title: t('Plans'),
        href: route('plans.index'),
        icon: Package,
        permission: 'manage-plans',
        order: 300,
    },
    {
        title: t('Orders'),
        href: route('orders.index'),
        icon: Receipt,
        permission: 'manage-orders',
        order: 310,
    },
    {
        title: t('Bank Transfers'),
        href: route('bank-transfers.index'),
        icon: Banknote,
        permission: 'manage-bank-transfers',
        order: 320,
    },
    {
        title: t('Coupons'),
        href: route('coupons.index'),
        icon: Ticket,
        permission: 'manage-coupons',
        order: 330,
    },
    {
        title: t('Add-ons'),
        href: route('add-ons.index'),
        icon: Puzzle,
        permission: 'manage-add-ons',
        order: 340,
    },
    {
        title: t('Settings'),
        href: route('settings.index'),
        icon: Settings,
        permission: 'manage-settings',
        order: 900,
    },
];
