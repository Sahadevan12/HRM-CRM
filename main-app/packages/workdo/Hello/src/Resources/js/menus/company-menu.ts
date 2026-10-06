import { NavItem } from '@/types';
import { Smile } from 'lucide-react';

// Proof that add-on menus are auto-loaded and nested under a core item via `parent`.
export const helloCompanyMenu = (t: (key: string) => string): NavItem[] => [
    {
        title: t('Hello Module'),
        href: route('hello.index'),
        icon: Smile,
        permission: 'manage-dashboard',
        parent: 'dashboard',
        order: 20,
    },
];
