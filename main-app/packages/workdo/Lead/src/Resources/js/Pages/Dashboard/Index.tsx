import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Bar, BarChart, CartesianGrid, Cell, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useTranslation } from 'react-i18next';

interface Props {
    pipelines: { id: number; name: string }[];
    pipeline: { id: number; name: string };
    kpis: Record<string, number>;
    leadsByStage: { name: string; value: number }[];
    dealsByStage: { name: string; count: number; value: number }[];
    leadsByMonth: { month: string; leads: number }[];
    bySource: { name: string; value: number }[];
    activity: { kind: 'lead' | 'deal'; id: number; title: string; remark: string; who: string | null; at: string }[];
}

const COLORS = ['#2563eb', '#16a34a', '#f59e0b', '#db2777', '#7c3aed', '#0891b2', '#64748b'];

function Kpi({ label, value, hint }: { label: string; value: string | number; hint?: string }) {
    return (
        <Card>
            <CardContent className="p-4">
                <div className="text-xs text-muted-foreground">{label}</div>
                <div className="text-2xl font-semibold">{value}</div>
                {hint && <div className="text-xs text-muted-foreground">{hint}</div>}
            </CardContent>
        </Card>
    );
}

export default function CrmDashboard({ pipelines, pipeline, kpis, leadsByStage, dealsByStage, leadsByMonth, bySource, activity }: Props) {
    const { t } = useTranslation();
    const money = useMoney();

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('CRM Dashboard')}</h2>}>
            <Head title={t('CRM Dashboard')} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Select value={String(pipeline.id)} onValueChange={(v) => router.get(route('crm.dashboard'), { pipeline: v }, { preserveState: true, replace: true })}>
                        <SelectTrigger className="w-52"><SelectValue /></SelectTrigger>
                        <SelectContent>{pipelines.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                    </Select>
                    <span className="text-xs text-muted-foreground">{t('This month, unless stated otherwise')}</span>
                </div>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Kpi label={t('Open leads')} value={kpis.leads_active} hint={`${kpis.leads_total} ${t('in total')}`} />
                    <Kpi label={t('New leads')} value={kpis.leads_new_month} hint={`${kpis.leads_converted_month} ${t('converted')}`} />
                    <Kpi label={t('Open deals')} value={kpis.open_deals} hint={money(kpis.open_value)} />
                    <Kpi label={t('Won')} value={money(kpis.won_month_value)} hint={`${kpis.won_month_count} ${t('deals')} · ${t('win rate')} ${kpis.win_rate}%`} />
                    <Kpi label={t('Lost')} value={money(kpis.lost_month_value)} />
                    <Kpi label={t('Tasks due today')} value={kpis.tasks_due} />
                    <Kpi label={t('Overdue tasks')} value={kpis.tasks_overdue} />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader><CardTitle className="text-base">{t('Leads by stage')}</CardTitle></CardHeader>
                        <CardContent className="h-64">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={leadsByStage}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="name" tick={{ fontSize: 12 }} />
                                    <YAxis allowDecimals={false} />
                                    <Tooltip />
                                    <Bar dataKey="value" fill="#2563eb" radius={[4, 4, 0, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle className="text-base">{t('Open deal value by stage')}</CardTitle></CardHeader>
                        <CardContent className="h-64">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={dealsByStage}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="name" tick={{ fontSize: 12 }} />
                                    <YAxis />
                                    <Tooltip formatter={(v: number) => money(v)} />
                                    <Bar dataKey="value" fill="#16a34a" radius={[4, 4, 0, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle className="text-base">{t('New leads, last 6 months')}</CardTitle></CardHeader>
                        <CardContent className="h-56">
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart data={leadsByMonth}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="month" />
                                    <YAxis allowDecimals={false} />
                                    <Tooltip />
                                    <Line type="monotone" dataKey="leads" stroke="#7c3aed" strokeWidth={2} />
                                </LineChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle className="text-base">{t('Leads by source, last 6 months')}</CardTitle></CardHeader>
                        <CardContent className="h-56">
                            {bySource.length === 0 ? <p className="text-sm text-muted-foreground">{t('No data yet.')}</p> : (
                                <ResponsiveContainer width="100%" height="100%">
                                    <PieChart>
                                        <Pie data={bySource} dataKey="value" nameKey="name" outerRadius={80} label>
                                            {bySource.map((_, i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}
                                        </Pie>
                                        <Tooltip />
                                    </PieChart>
                                </ResponsiveContainer>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader><CardTitle className="text-base">{t('Latest activity')}</CardTitle></CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        {activity.length === 0 && <p className="text-muted-foreground">{t('Nothing yet.')}</p>}
                        {activity.map((a, i) => (
                            <div key={i} className="flex flex-wrap items-baseline gap-2">
                                <Link href={route(a.kind === 'lead' ? 'crm.leads.index' : 'crm.deals.index')} className="font-medium hover:underline">{a.title}</Link>
                                <span>{a.remark}</span>
                                <span className="ml-auto text-xs text-muted-foreground">{a.who ?? '—'} · {a.at.slice(0, 16).replace('T', ' ')}</span>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
