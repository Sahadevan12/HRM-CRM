import { NavItem } from '@/types';
import { cn } from '@/lib/utils';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';

const pathOf = (href?: string) => {
    if (!href) return '';
    try {
        return new URL(href, window.location.origin).pathname;
    } catch {
        return href;
    }
};

const isActive = (item: NavItem, currentPath: string): boolean => {
    const own = pathOf(item.href);
    if (own && (currentPath === own || currentPath.startsWith(own + '/'))) return true;
    return !!item.children?.some((child) => isActive(child, currentPath));
};

function Item({ item, depth = 0 }: { item: NavItem; depth?: number }) {
    const { url } = usePage();
    const currentPath = url.split('?')[0];
    const active = isActive(item, currentPath);
    const [open, setOpen] = useState(active);

    const Icon = item.icon;
    const base = cn(
        'flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
        depth > 0 && 'pl-9',
        active ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
    );

    if (item.children?.length) {
        return (
            <div>
                <button type="button" className={base} onClick={() => setOpen(!open)} aria-expanded={open}>
                    {Icon && <Icon className="h-4 w-4 shrink-0" />}
                    <span className="flex-1 text-left">{item.title}</span>
                    <ChevronDown className={cn('h-4 w-4 transition-transform', open && 'rotate-180')} />
                </button>
                {open && (
                    <div className="mt-1 space-y-1">
                        {item.children.map((child, i) => (
                            <Item key={`${child.title}-${i}`} item={child} depth={depth + 1} />
                        ))}
                    </div>
                )}
            </div>
        );
    }

    return (
        <Link href={item.href ?? '#'} className={base}>
            {Icon && <Icon className="h-4 w-4 shrink-0" />}
            <span>{item.title}</span>
        </Link>
    );
}

export default function AppSidebar({ items, title }: { items: NavItem[]; title: string }) {
    return (
        <div className="flex h-full flex-col">
            <div className="flex h-16 shrink-0 items-center border-b px-5">
                <Link href={route('dashboard')} className="truncate text-lg font-bold text-primary">
                    {title}
                </Link>
            </div>
            <nav className="flex-1 space-y-1 overflow-y-auto p-3">
                {items.map((item, i) => (
                    <Item key={`${item.title}-${i}`} item={item} />
                ))}
            </nav>
        </div>
    );
}
