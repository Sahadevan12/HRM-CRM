import { coreMenu } from '@/menus/core-menu';
import { NavItem, PageProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

/**
 * Every add-on module may ship menus/company-menu.ts (and superadmin-menu.ts).
 * Vite bundles them all; we only USE the ones of the user's activated packages.
 */
const moduleMenus = import.meta.glob('../../../packages/workdo/*/src/Resources/js/menus/*.ts', { eager: true }) as Record<
    string,
    Record<string, unknown>
>;

type MenuT = (key: string) => string;

function loadModuleItems(packages: string[], menuFile: string, t: MenuT): NavItem[] {
    const items: NavItem[] = [];

    packages.forEach((pkg) => {
        const mod = moduleMenus[`../../../packages/workdo/${pkg}/src/Resources/js/menus/${menuFile}.ts`];
        if (!mod) return;

        Object.values(mod).forEach((exported) => {
            const result = typeof exported === 'function' ? (exported as (t: MenuT) => NavItem | NavItem[])(t) : exported;
            items.push(...(Array.isArray(result) ? result : [result as NavItem]));
        });
    });

    return items;
}

const byOrder = (a: NavItem, b: NavItem) => (a.order ?? 999) - (b.order ?? 999);

/** Nest items that declare `parent` under the core item with that `name`. */
function groupByParent(core: NavItem[], moduleItems: NavItem[], t: MenuT): NavItem[] {
    const result: NavItem[] = core.map((item) => ({ ...item, children: item.children ? [...item.children] : undefined }));

    moduleItems.forEach((item) => {
        const parent = item.parent ? result.find((r) => r.name === item.parent) : undefined;

        if (!parent) {
            result.push({ ...item, parent: undefined });
            return;
        }

        // A parent that was a plain link becomes a group; keep its own page as the first child.
        if (!parent.children) {
            parent.children = parent.href ? [{ title: t('Overview'), href: parent.href, permission: parent.permission, order: 0 }] : [];
            parent.href = undefined;
        }
        parent.children.push({ ...item, parent: undefined });
        parent.children.sort(byOrder);
    });

    return result;
}

/** Drop items the user may not see; drop groups left without visible children. */
function filterByPermission(items: NavItem[], permissions: string[]): NavItem[] {
    return items
        .filter((item) => !item.permission || permissions.includes(item.permission))
        .map((item) => (item.children ? { ...item, children: filterByPermission(item.children, permissions) } : item))
        .filter((item) => !item.children || item.children.length > 0)
        .sort(byOrder);
}

export function useMenuItems(): NavItem[] {
    const { auth } = usePage<PageProps>().props;
    const { t, i18n } = useTranslation();

    const permissions = auth.user.permissions ?? [];
    const packages = auth.user.activatedPackages ?? [];
    const isSuperadmin = auth.user.roles?.includes('superadmin');

    return useMemo(() => {
        const moduleItems = loadModuleItems(packages, isSuperadmin ? 'superadmin-menu' : 'company-menu', t);
        return filterByPermission(groupByParent(coreMenu(t), moduleItems, t), permissions);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [packages.join(','), permissions.join(','), isSuperadmin, i18n.language]);
}
