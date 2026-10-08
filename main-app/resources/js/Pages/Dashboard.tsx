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
export default function Dashboard({ widgets }: PageProps<{ widgets: Widget[] }>) {
    const { t } = useTranslation();
    const money = useMoney();
    const { auth } = usePage<PageProps>().props;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Dashboard')}</h2>}>
            <Head title={t('Dashboard')} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <p className="text-sm text-muted-foreground">{t('Welcome back')}, {auth.user.name}.</p>

                {widgets.length === 0 && (
                    <Card><CardContent className="py-8 text-center text-muted-foreground">{t('Nothing to show yet. Activate a module in your plan to see its numbers here.')}</CardContent></Card>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    {widgets.map((w) => (
                        <Card key={w.key}>
                            <CardHeader className="flex-row items-center justify-between space-y-0">
                                <CardTitle className="text-base">{t(w.title)}</CardTitle>
                                {w.href && <Link href={w.href} className="text-xs text-primary hover:underline">{t('Open')} →</Link>}
                            </CardHeader>
                            <CardContent className="grid grid-cols-2 gap-4">
                                {w.stats.map((s) => (
                                    <div key={s.label}>
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
