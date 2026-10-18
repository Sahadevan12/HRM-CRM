import { Dialog, DialogContent, DialogTitle } from '@/Components/ui/dialog';
import { NavItem } from '@/types';
import { router } from '@inertiajs/react';
import { CornerDownLeft, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Entry {
    title: string;
    group: string;
    href: string;
    icon?: NavItem['icon'];
}

/** Flattens the sidebar (already filtered by plan and permission) into jump targets. */
function flatten(items: NavItem[], group = ''): Entry[] {
    return items.flatMap((item) => {
        const own = item.href ? [{ title: item.title, group, href: item.href, icon: item.icon }] : [];

        return [...own, ...flatten(item.children ?? [], item.href ? group : item.title)];
    });
}

/** Ctrl/Cmd + K: jump to any page you may open. */
export default function CommandPalette({ items, open, onOpenChange }: { items: NavItem[]; open: boolean; onOpenChange: (open: boolean) => void }) {
    const { t } = useTranslation();
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const list = useRef<HTMLDivElement>(null);

    const entries = useMemo(() => flatten(items), [items]);
    const results = useMemo(() => {
        const q = query.trim().toLowerCase();

        return (q ? entries.filter((e) => `${e.group} ${e.title}`.toLowerCase().includes(q)) : entries).slice(0, 30);
    }, [entries, query]);

    useEffect(() => {
        if (open) {
            setQuery('');
            setActive(0);
        }
    }, [open]);

    useEffect(() => setActive(0), [query]);

    useEffect(() => {
        list.current?.querySelector<HTMLElement>(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    const go = (entry?: Entry) => {
        if (!entry) return;
        onOpenChange(false);
        router.visit(entry.href);
    };

    const onKey = (e: React.KeyboardEvent) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => Math.min(i + 1, results.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => Math.max(i - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            go(results[active]);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="top-[20%] max-w-xl translate-y-0 gap-0 overflow-hidden rounded-2xl p-0 [&>button]:hidden">
                <DialogTitle className="sr-only">{t('Search')}</DialogTitle>
                <div className="flex items-center gap-3 border-b px-4">
                    <Search className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <input
                        autoFocus
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        onKeyDown={onKey}
                        placeholder={t('Search pages…')}
                        className="h-14 w-full border-0 bg-transparent text-sm outline-none ring-0 placeholder:text-muted-foreground focus:border-0 focus:outline-none focus:ring-0"
                        aria-label={t('Search')}
                    />
                    <kbd className="rounded border bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground">Esc</kbd>
                </div>
                <div ref={list} className="max-h-80 overflow-y-auto p-2" role="listbox">
                    {results.length === 0 && <div className="py-10 text-center text-sm text-muted-foreground">{t('No results')}</div>}
                    {results.map((entry, i) => {
                        const Icon = entry.icon;

                        return (
                            <button
                                key={`${entry.href}-${i}`}
                                type="button"
                                role="option"
                                aria-selected={i === active}
                                data-index={i}
                                onMouseMove={() => setActive(i)}
                                onClick={() => go(entry)}
                                className={`flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm ${i === active ? 'bg-primary/10 text-primary' : ''}`}
                            >
                                {Icon ? <Icon className="h-4 w-4 shrink-0" /> : <span className="w-4" />}
                                <span className="flex-1 truncate font-medium">{entry.title}</span>
                                {entry.group && <span className="truncate text-xs text-muted-foreground">{entry.group}</span>}
                                {i === active && <CornerDownLeft className="h-3.5 w-3.5 shrink-0" />}
                            </button>
                        );
                    })}
                </div>
            </DialogContent>
        </Dialog>
    );
}
