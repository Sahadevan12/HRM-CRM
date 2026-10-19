import AppSidebar from '@/Components/AppSidebar';
import CommandPalette from '@/Components/CommandPalette';
import Dropdown from '@/Components/Dropdown';
import { Toaster } from '@/Components/ui/sonner';
import { useAppearance } from '@/hooks/useAppearance';
import { syncTranslations } from '@/i18n';
import { PageProps } from '@/types';
import { useMenuItems } from '@/utils/menu';
import { router, usePage } from '@inertiajs/react';
import { Globe, Menu, Monitor, Moon, Search, Sun, X } from 'lucide-react';
import { PropsWithChildren, ReactNode, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

const MODE_CYCLE = { light: 'dark', dark: 'system', system: 'light' } as const;
const MODE_ICON = { light: Sun, dark: Moon, system: Monitor };

export default function Authenticated({ header, children }: PropsWithChildren<{ header?: ReactNode }>) {
    const { auth, flash, languages, translations, adminAllSetting, companyAllSetting } = usePage<PageProps>().props;
    const { t } = useTranslation();
    const { mode, setMode } = useAppearance();
    const menu = useMenuItems();
    const [mobileOpen, setMobileOpen] = useState(false);
    const [paletteOpen, setPaletteOpen] = useState(false);

    // Ctrl/Cmd + K opens the page finder from anywhere
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setPaletteOpen((o) => !o);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    // Keep i18next in step with the language/translations the server sent for this visit.
    syncTranslations(auth.lang, translations);

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash?.success, flash?.error]);

    // Close the mobile drawer after navigating.
    useEffect(() => router.on('navigate', () => setMobileOpen(false)), []);

    const appTitle = companyAllSetting.titleText || adminAllSetting.titleText || 'ERP Suite';
    const ModeIcon = MODE_ICON[mode];

    return (
        <div className="min-h-screen bg-background text-foreground">
            {/* desktop sidebar */}
            <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 bg-sidebar shadow-xl print:hidden lg:block">
                <AppSidebar items={menu} title={appTitle} />
            </aside>

            {/* mobile drawer */}
            {mobileOpen && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="absolute inset-0 bg-black/50" onClick={() => setMobileOpen(false)} />
                    <aside className="absolute inset-y-0 left-0 w-64 bg-sidebar">
                        <button
                            type="button"
                            className="absolute right-2 top-4 rounded p-1 text-muted-foreground"
                            onClick={() => setMobileOpen(false)}
                            aria-label="Close menu"
                        >
                            <X className="h-5 w-5" />
                        </button>
                        <AppSidebar items={menu} title={appTitle} />
                    </aside>
                </div>
            )}

            <div className="lg:pl-64 print:pl-0">
                <header className="sticky top-0 z-20 flex h-16 items-center justify-between border-b bg-card/90 px-4 shadow-sm backdrop-blur print:hidden sm:px-6">
                    <button
                        type="button"
                        className="rounded p-2 text-muted-foreground hover:bg-accent lg:hidden"
                        onClick={() => setMobileOpen(true)}
                        aria-label="Open menu"
                    >
                        <Menu className="h-5 w-5" />
                    </button>

                    <button
                        type="button"
                        onClick={() => setPaletteOpen(true)}
                        className="ml-2 hidden h-10 w-72 items-center gap-2 rounded-lg border bg-muted/50 px-3 text-sm text-muted-foreground transition hover:bg-muted md:flex"
                    >
                        <Search className="h-4 w-4" />
                        <span className="flex-1 text-left">{t('Search pages…')}</span>
                        <kbd className="rounded border bg-card px-1.5 py-0.5 text-[10px]">Ctrl K</kbd>
                    </button>

                    <div className="ml-auto flex items-center gap-1">
                        <button type="button" className="rounded-lg p-2 text-muted-foreground hover:bg-accent md:hidden" onClick={() => setPaletteOpen(true)} aria-label={t('Search')}>
                            <Search className="h-4 w-4" />
                        </button>
                        <Dropdown>
                            <Dropdown.Trigger>
                                <button
                                    type="button"
                                    className="flex items-center gap-1 rounded p-2 text-sm text-muted-foreground hover:bg-accent"
                                    aria-label="Language"
                                >
                                    <Globe className="h-4 w-4" />
                                    <span className="uppercase">{auth.lang}</span>
                                </button>
                            </Dropdown.Trigger>
                            <Dropdown.Content>
                                {Object.entries(languages).map(([code, name]) => (
                                    <button
                                        key={code}
                                        type="button"
                                        className="block w-full px-4 py-2 text-start text-sm leading-5 text-foreground hover:bg-accent"
                                        onClick={() => router.post(route('languages.change'), { lang: code }, { preserveScroll: true })}
                                    >
                                        {name}
                                    </button>
                                ))}
                            </Dropdown.Content>
                        </Dropdown>

                        <button
                            type="button"
                            className="rounded p-2 text-muted-foreground hover:bg-accent"
                            onClick={() => setMode(MODE_CYCLE[mode])}
                            aria-label={`Theme: ${mode}`}
                            title={`Theme: ${mode}`}
                        >
                            <ModeIcon className="h-4 w-4" />
                        </button>

                        <Dropdown>
                            <Dropdown.Trigger>
                                <button type="button" className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium hover:bg-accent">
                                    <span className="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-[#533afd] to-[#f96bee] text-xs font-bold text-white">
                                        {auth.user.name.split(' ').map((w: string) => w[0]).slice(0, 2).join('').toUpperCase()}
                                    </span>
                                    <span className="hidden sm:inline">{auth.user.name}</span>
                                </button>
                            </Dropdown.Trigger>
                            <Dropdown.Content>
                                <Dropdown.Link href={route('profile.edit')}>{t('Profile')}</Dropdown.Link>
                                <Dropdown.Link href={route('logout')} method="post" as="button">
                                    {t('Log Out')}
                                </Dropdown.Link>
                            </Dropdown.Content>
                        </Dropdown>
                    </div>
                </header>

                {header && (
                    <div className="border-b bg-card/50 print:hidden">
                        <div className="px-4 py-5 sm:px-6 [&_h2]:text-2xl [&_h2]:font-light [&_h2]:tracking-tight">{header}</div>
                    </div>
                )}

                <main className="page-enter">{children}</main>
                <CommandPalette items={menu} open={paletteOpen} onOpenChange={setPaletteOpen} />
            </div>
            <Toaster position="top-center" richColors />
        </div>
    );
}
