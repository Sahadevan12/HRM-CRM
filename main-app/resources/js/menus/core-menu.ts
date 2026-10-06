import { NavItem } from '@/types';
import { KeyRound, LayoutDashboard, Settings, Users } from 'lucide-react';

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
        title: t('Settings'),
        href: route('settings.index'),
        icon: Settings,
        permission: 'manage-settings',
        order: 900,
    },
];
