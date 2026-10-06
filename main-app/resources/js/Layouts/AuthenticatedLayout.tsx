import AppSidebar from '@/Components/AppSidebar';
import Dropdown from '@/Components/Dropdown';
import { Toaster } from '@/Components/ui/sonner';
import { useAppearance } from '@/hooks/useAppearance';
import { syncTranslations } from '@/i18n';
import { PageProps } from '@/types';
import { useMenuItems } from '@/utils/menu';
import { router, usePage } from '@inertiajs/react';
import { Globe, Menu, Monitor, Moon, Sun, X } from 'lucide-react';
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

    // Keep i18next in step with the language/translations the server sent for this visit.
    syncTranslations(auth.lang, translations);

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.error) toast.error(flash.error);
    }, [flash?.success, flash?.error]);

    // Close the mobile drawer after navigating.
    useEffect(() => router.on('navigate', () => setMobileOpen(false)), []);

    const appTitle = companyAllSetting.titleText || adminAllSetting.titleText || 'ERP';
    const ModeIcon = MODE_ICON[mode];

    return (
        <div className="min-h-screen bg-background text-foreground">
            {/* desktop sidebar */}
            <aside className="fixed inset-y-0 left-0 z-30 hidden w-64 border-r bg-card print:hidden lg:block">
                <AppSidebar items={menu} title={appTitle} />
            </aside>

            {/* mobile drawer */}
            {mobileOpen && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="absolute inset-0 bg-black/50" onClick={() => setMobileOpen(false)} />
                    <aside className="absolute inset-y-0 left-0 w-64 border-r bg-card">
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
                <header className="sticky top-0 z-20 flex h-16 items-center justify-between border-b bg-card px-4 print:hidden sm:px-6">
                    <button
                        type="button"
                        className="rounded p-2 text-muted-foreground hover:bg-accent lg:hidden"
                        onClick={() => setMobileOpen(true)}
                        aria-label="Open menu"
                    >
                        <Menu className="h-5 w-5" />
                    </button>

                    <div className="ml-auto flex items-center gap-1">
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
                                        className="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 hover:bg-gray-100"
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
                                <button type="button" className="rounded px-3 py-2 text-sm font-medium hover:bg-accent">
                                    {auth.user.name}
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
                        <div className="px-4 py-5 sm:px-6">{header}</div>
                    </div>
                )}

                <main>{children}</main>
            </div>
            <Toaster position="top-center" richColors />
        </div>
    );
}
