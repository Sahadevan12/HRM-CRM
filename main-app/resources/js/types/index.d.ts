import { LucideIcon } from 'lucide-react';

export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    type?: 'superadmin' | 'company' | 'staff' | 'client' | 'vendor';
    mobile_no?: string | null;
    is_enable_login?: boolean;
    created_at?: string;
    permissions?: string[];
    roles?: string[];
    activatedPackages?: string[];
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

/**
 * Sidebar item. Add-on modules export these from
 * packages/workdo/<Module>/src/Resources/js/menus/company-menu.ts
 *  - `name`   : lets other items attach to this one via `parent`
 *  - `parent` : name of the core item to nest under
 *  - `order`  : sort position (lower = higher up)
 */
export interface NavItem {
    name?: string;
    title: string;
    href?: string;
    icon?: LucideIcon;
    permission?: string;
    order?: number;
    parent?: string;
    children?: NavItem[];
}

export type Settings = Record<string, string | null | undefined>;

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
        lang: string;
    };
    flash: {
        success?: string | null;
        error?: string | null;
    };
    adminAllSetting: Settings;
    companyAllSetting: Settings;
    languages: Record<string, string>;
    translations: Record<string, string>;
};
