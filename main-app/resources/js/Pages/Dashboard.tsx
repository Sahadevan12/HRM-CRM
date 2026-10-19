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
    { bar: 'from-[#533afd] to-[#f96bee]', tint: 'bg-[#533afd]/[0.06]' },
    { bar: 'from-[#665efd] to-[#38bdf8]', tint: 'bg-[#665efd]/[0.07]' },
    { bar: 'from-[#0ea5a4] to-[#84cc16]', tint: 'bg-emerald-500/[0.07]' },
    { bar: 'from-[#9b6829] to-[#f59e0b]', tint: 'bg-amber-500/[0.08]' },
    { bar: 'from-[#ea2261] to-[#f96bee]', tint: 'bg-[#ea2261]/[0.06]' },
];

export default function Dashboard({ widgets }: PageProps<{ widgets: Widget[] }>) {
    const { t } = useTranslation();
    const money = useMoney();
    const { auth } = usePage<PageProps>().props;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Dashboard')}</h2>}>
            <Head title={t('Dashboard')} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="rounded-xl bg-mesh p-7 text-white shadow-float">
                    <div className="text-sm opacity-80">{new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</div>
                    <div className="mt-1 text-3xl font-light tracking-tight">{t('Welcome back')}, {auth.user.name}!</div>
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
                                        <div className="tnum text-2xl font-light tracking-tight">{s.format === 'money' ? money(Number(s.value)) : s.value}</div>
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
