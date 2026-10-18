import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

interface Widget {
    key: string;
    title: string;
    href: string | null;
    stats: { label: string; value: number | string; format?: 'number' | 'money'; hint?: string }[];
}

/** The company dashboard: one card per active module (the modules add their own widget, see App\Events\CollectDashboardWidgets). */
const ACCENTS = [
    { bar: 'from-violet-500 to-fuchsia-500', tint: 'bg-violet-50 dark:bg-violet-500/10' },
    { bar: 'from-sky-500 to-cyan-400', tint: 'bg-sky-50 dark:bg-sky-500/10' },
    { bar: 'from-emerald-500 to-lime-400', tint: 'bg-emerald-50 dark:bg-emerald-500/10' },
    { bar: 'from-orange-500 to-amber-400', tint: 'bg-orange-50 dark:bg-orange-500/10' },
    { bar: 'from-rose-500 to-pink-400', tint: 'bg-rose-50 dark:bg-rose-500/10' },
];

export default function Dashboard({ widgets }: PageProps<{ widgets: Widget[] }>) {
    const { t } = useTranslation();
    const money = useMoney();
    const { auth } = usePage<PageProps>().props;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Dashboard')}</h2>}>
            <Head title={t('Dashboard')} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="rounded-2xl bg-gradient-to-r from-violet-600 via-indigo-600 to-sky-500 p-6 text-white shadow-lg">
                    <div className="text-sm opacity-80">{new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</div>
                    <div className="mt-1 text-2xl font-bold">{t('Welcome back')}, {auth.user.name}!</div>
                    <div className="mt-1 text-sm opacity-90">{t("Here is what is happening in your business today.")}</div>
                </div>

                {widgets.length === 0 && (
                    <Card><CardContent className="py-8 text-center text-muted-foreground">{t('Nothing to show yet. Activate a module in your plan to see its numbers here.')}</CardContent></Card>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    {widgets.map((w, wi) => (
                        <Card key={w.key} className="overflow-hidden border-0 shadow-md">
                            <div className={`h-1.5 bg-gradient-to-r ${ACCENTS[wi % ACCENTS.length].bar}`} />
                            <CardHeader className="flex-row items-center justify-between space-y-0">
                                <CardTitle className="text-base">{t(w.title)}</CardTitle>
                                {w.href && <Link href={w.href} className="text-xs text-primary hover:underline">{t('Open')} →</Link>}
                            </CardHeader>
                            <CardContent className="grid grid-cols-2 gap-4">
                                {w.stats.map((s, si) => (
                                    <div key={s.label} className={`rounded-xl p-3 ${ACCENTS[(wi + si) % ACCENTS.length].tint}`}>
                                        <div className="text-xs text-muted-foreground">{t(s.label)}</div>
                                        <div className="text-2xl font-semibold">{s.format === 'money' ? money(Number(s.value)) : s.value}</div>
                                        {s.hint && <div className="text-xs text-muted-foreground">{s.hint}</div>}
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
