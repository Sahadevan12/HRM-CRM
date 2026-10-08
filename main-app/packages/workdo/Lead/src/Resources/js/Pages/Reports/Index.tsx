import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useTranslation } from 'react-i18next';

interface Slice { name: string; value: number }

interface Props {
    pipelines: { id: number; name: string }[];
    pipeline: { id: number; name: string };
    from: string;
    to: string;
    leads: {
        totals: { leads: number; converted: number; conversion_rate: number };
        byStage: Slice[];
        bySource: Slice[];
        byUser: Slice[];
        byMonth: { month: string; leads: number }[];
    };
    deals: {
        totals: { won_count: number; won_value: number; lost_count: number; lost_value: number; open_count: number; open_value: number; win_rate: number };
        byStage: { name: string; count: number; value: number }[];
        byUser: { name: string; count: number; value: number }[];
        byMonth: { month: string; won: number; lost: number }[];
    };
}

export default function CrmReports({ pipelines, pipeline, from, to, leads, deals }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const [f, setF] = useState({ from, to });

    const go = (params: Record<string, string | number>) => router.get(route('crm.reports'), params, { preserveState: true, replace: true });

    const slices = (title: string, rows: Slice[]) => (
        <Card>
            <CardHeader><CardTitle className="text-base">{title}</CardTitle></CardHeader>
            <CardContent className="p-0">
                <Table>
                    <TableBody>
                        {rows.length === 0 && <TableRow><TableCell className="py-6 text-center text-muted-foreground">{t('No data yet.')}</TableCell></TableRow>}
                        {rows.map((r) => <TableRow key={r.name}><TableCell>{r.name}</TableCell><TableCell className="text-right font-medium">{r.value}</TableCell></TableRow>)}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );

    const kpi = (label: string, value: string | number, hint?: string) => (
        <Card><CardContent className="p-4"><div className="text-xs text-muted-foreground">{label}</div><div className="text-xl font-semibold">{value}</div>{hint && <div className="text-xs text-muted-foreground">{hint}</div>}</CardContent></Card>
    );

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('CRM Reports')}</h2>}>
            <Head title={t('CRM Reports')} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-end gap-2">
                    <Select value={String(pipeline.id)} onValueChange={(v) => go({ pipeline: v, from, to })}>
                        <SelectTrigger className="w-48"><SelectValue /></SelectTrigger>
                        <SelectContent>{pipelines.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                    </Select>
                    <div className="space-y-1"><Label>{t('From')}</Label><Input type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></div>
                    <div className="space-y-1"><Label>{t('To')}</Label><Input type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></div>
                    <Button variant="outline" onClick={() => go({ pipeline: pipeline.id, from: f.from, to: f.to })}>{t('Apply')}</Button>
                </div>

                <section className="space-y-3">
                    <h3 className="text-lg font-semibold">{t('Leads')}</h3>
                    <div className="grid grid-cols-3 gap-3">
                        {kpi(t('Leads created'), leads.totals.leads)}
                        {kpi(t('Converted'), leads.totals.converted)}
                        {kpi(t('Conversion rate'), `${leads.totals.conversion_rate}%`)}
                    </div>
                    <Card>
                        <CardHeader><CardTitle className="text-base">{t('Leads created per month')}</CardTitle></CardHeader>
                        <CardContent className="h-60">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={leads.byMonth}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="month" tick={{ fontSize: 12 }} />
                                    <YAxis allowDecimals={false} />
                                    <Tooltip />
                                    <Bar dataKey="leads" fill="#7c3aed" radius={[4, 4, 0, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                    <div className="grid gap-4 md:grid-cols-3">
                        {slices(t('By stage'), leads.byStage)}
                        {slices(t('By source'), leads.bySource)}
                        {slices(t('By user'), leads.byUser)}
                    </div>
                </section>

                <section className="space-y-3">
                    <h3 className="text-lg font-semibold">{t('Deals')}</h3>
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        {kpi(t('Won'), money(deals.totals.won_value), `${deals.totals.won_count} ${t('deals')}`)}
                        {kpi(t('Lost'), money(deals.totals.lost_value), `${deals.totals.lost_count} ${t('deals')}`)}
                        {kpi(t('Win rate'), `${deals.totals.win_rate}%`)}
                        {kpi(t('Open pipeline'), money(deals.totals.open_value), `${deals.totals.open_count} ${t('deals')} · ${t('today')}`)}
                    </div>
                    <Card>
                        <CardHeader><CardTitle className="text-base">{t('Won and lost value per month')}</CardTitle></CardHeader>
                        <CardContent className="h-60">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={deals.byMonth}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="month" tick={{ fontSize: 12 }} />
                                    <YAxis />
                                    <Tooltip formatter={(v: number) => money(v)} />
                                    <Legend />
                                    <Bar dataKey="won" name={t('Won')} fill="#16a34a" radius={[4, 4, 0, 0]} />
                                    <Bar dataKey="lost" name={t('Lost')} fill="#dc2626" radius={[4, 4, 0, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                    <div className="grid gap-4 md:grid-cols-2">
                        <Card>
                            <CardHeader><CardTitle className="text-base">{t('Open deals by stage')} ({t('today')})</CardTitle></CardHeader>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader><TableRow><TableHead>{t('Stage')}</TableHead><TableHead className="text-right">{t('Deals')}</TableHead><TableHead className="text-right">{t('Value')}</TableHead></TableRow></TableHeader>
                                    <TableBody>
                                        {deals.byStage.map((r) => <TableRow key={r.name}><TableCell>{r.name}</TableCell><TableCell className="text-right">{r.count}</TableCell><TableCell className="text-right font-medium">{money(r.value)}</TableCell></TableRow>)}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader><CardTitle className="text-base">{t('Won by user')}</CardTitle></CardHeader>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader><TableRow><TableHead>{t('User')}</TableHead><TableHead className="text-right">{t('Deals')}</TableHead><TableHead className="text-right">{t('Value')}</TableHead></TableRow></TableHeader>
                                    <TableBody>
                                        {deals.byUser.length === 0 && <TableRow><TableCell colSpan={3} className="py-6 text-center text-muted-foreground">{t('No data yet.')}</TableCell></TableRow>}
                                        {deals.byUser.map((r) => <TableRow key={r.name}><TableCell>{r.name}</TableCell><TableCell className="text-right">{r.count}</TableCell><TableCell className="text-right font-medium">{money(r.value)}</TableCell></TableRow>)}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
