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
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash: {
        success?: string | null;
        error?: string | null;
    };
};
