import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Bar, BarChart, CartesianGrid, Cell, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { useTranslation } from 'react-i18next';

interface Slice {
    name: string;
    value: number;
}

interface Props {
    kpis: { employees: number; active: number; present_today: number; on_leave_today: number; new_this_month: number; pending_leaves: number; pending_requests: number };
    byDepartment: Slice[];
    byGender: Slice[];
    byType: Slice[];
    attendanceTrend: { date: string; present: number }[];
    joiners: { month: string; joined: number }[];
    lastPayroll: { month: string; total_net: number; status: string } | null;
    events: { id: number; title: string; start_date: string; type: { color: string } | null }[];
    announcements: { id: number; title: string; start_date: string }[];
    birthdays: { name: string; date: string; in_days: number }[];
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

export default function HrmDashboard(p: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const { kpis } = p;

    const pie = (title: string, data: Slice[]) => (
        <Card>
            <CardHeader><CardTitle className="text-base">{title}</CardTitle></CardHeader>
            <CardContent className="h-56">
                {data.length === 0 ? <p className="text-sm text-muted-foreground">{t('No data yet.')}</p> : (
                    <ResponsiveContainer width="100%" height="100%">
                        <PieChart>
                            <Pie data={data.map((d) => ({ ...d, name: t(d.name) }))} dataKey="value" nameKey="name" outerRadius={80} label>
                                {data.map((_, i) => <Cell key={i} fill={COLORS[i % COLORS.length]} />)}
                            </Pie>
                            <Tooltip />
                        </PieChart>
                    </ResponsiveContainer>
                )}
            </CardContent>
        </Card>
    );

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('HRM Dashboard')}</h2>}>
            <Head title={t('HRM Dashboard')} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Kpi label={t('Employees')} value={kpis.employees} hint={`${kpis.active} ${t('active')}`} />
                    <Kpi label={t('Present today')} value={kpis.present_today} hint={`${kpis.on_leave_today} ${t('on leave')}`} />
                    <Kpi label={t('New this month')} value={kpis.new_this_month} />
                    <Kpi label={t('Pending approvals')} value={kpis.pending_leaves + kpis.pending_requests} hint={`${kpis.pending_leaves} ${t('leave')} · ${kpis.pending_requests} ${t('other')}`} />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader><CardTitle className="text-base">{t('Headcount by department')}</CardTitle></CardHeader>
                        <CardContent className="h-64">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={p.byDepartment}>
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
                        <CardHeader><CardTitle className="text-base">{t('Attendance, last 7 days')}</CardTitle></CardHeader>
                        <CardContent className="h-64">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={p.attendanceTrend}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="date" tick={{ fontSize: 12 }} />
                                    <YAxis allowDecimals={false} />
                                    <Tooltip />
                                    <Bar dataKey="present" fill="#16a34a" radius={[4, 4, 0, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle className="text-base">{t('New joiners, last 6 months')}</CardTitle></CardHeader>
                        <CardContent className="h-56">
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart data={p.joiners}>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="month" />
                                    <YAxis allowDecimals={false} />
                                    <Tooltip />
                                    <Line type="monotone" dataKey="joined" stroke="#7c3aed" strokeWidth={2} />
                                </LineChart>
                            </ResponsiveContainer>
                        </CardContent>
                    </Card>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {pie(t('By gender'), p.byGender)}
                        {pie(t('By employment type'), p.byType)}
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card>
                        <CardHeader><CardTitle className="text-base">{t('Upcoming events')}</CardTitle></CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {p.events.length === 0 && <p className="text-muted-foreground">{t('No events in this period.')}</p>}
                            {p.events.map((e) => (
                                <div key={e.id} className="flex items-center gap-2"><span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: e.type?.color ?? '#64748b' }} />{e.title}<span className="ml-auto text-muted-foreground">{e.start_date.slice(0, 10)}</span></div>
                            ))}
                            <Link href={route('hrm.events.index')} className="text-xs text-primary underline">{t('Open calendar')}</Link>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle className="text-base">{t('Birthdays, next 30 days')}</CardTitle></CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {p.birthdays.length === 0 && <p className="text-muted-foreground">{t('No birthdays soon.')}</p>}
                            {p.birthdays.map((b) => (
                                <div key={b.name + b.date} className="flex justify-between"><span>{b.name}</span><span className="text-muted-foreground">{b.in_days === 0 ? t('Today') : b.date.slice(5)}</span></div>
                            ))}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader><CardTitle className="text-base">{t('Announcements & payroll')}</CardTitle></CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {p.announcements.map((a) => <div key={a.id} className="truncate">{a.title}</div>)}
                            {p.announcements.length === 0 && <p className="text-muted-foreground">{t('No announcements right now.')}</p>}
                            {p.lastPayroll && <div className="border-t pt-2">{t('Last payroll')} {p.lastPayroll.month}: <b>{money(p.lastPayroll.total_net)}</b> ({t(p.lastPayroll.status)})</div>}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
