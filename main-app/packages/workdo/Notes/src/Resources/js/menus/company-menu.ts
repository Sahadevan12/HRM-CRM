import { NavItem } from '@/types';
import { Puzzle } from 'lucide-react';

// Auto-loaded by resources/js/utils/menu.ts for companies that have the Notes module.
export const notesCompanyMenu = (t: (key: string) => string): NavItem[] => [
    {
        title: t('Notes'),
        icon: Puzzle,
        order: 450,
        children: [
            {
                title: t('Notes'),
                href: route('notes.notes.index'),
                permission: 'manage-notes',
            },
            {
                title: t('Notebooks'),
                href: route('notes.notebooks.index'),
                permission: 'manage-notebooks',
            },
            // <menu-items>
        ],
    },
];
