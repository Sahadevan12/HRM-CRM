import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { AlarmClock, CalendarCheck, CalendarClock, CheckCircle2, Circle, Inbox } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Task {
    key: string;
    kind: 'lead' | 'deal';
    id: number;
    parent_id: number;
    title: string | null;
    name: string;
    priority: string;
    due_date: string | null;
    due_time: string | null;
    toggle: string;
}

interface Props {
    today: string;
    overdue: Task[];
    dueToday: Task[];
    upcoming: Task[];
}

const PRIORITY: Record<string, string> = {
    high: 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300',
    medium: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
    low: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
};

function Group({ title, icon: Icon, tone, tasks, onDone, busy, empty }: {
    title: string; icon: typeof Inbox; tone: string; tasks: Task[]; onDone: (t: Task) => void; busy: string | null; empty: string;
}) {
    const { t } = useTranslation();

    return (
        <Card className="overflow-hidden border-0 shadow-md">
            <div className={`h-1.5 bg-gradient-to-r ${tone}`} />
            <CardHeader className="flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="flex items-center gap-2 text-base"><Icon className="h-4 w-4" />{title}</CardTitle>
                <Badge variant="secondary">{tasks.length}</Badge>
            </CardHeader>
            <CardContent className="space-y-2">
                {tasks.length === 0 && <div className="py-4 text-center text-sm text-muted-foreground">{empty}</div>}
                {tasks.map((task) => (
                    <div key={task.key} className="flex items-center gap-3 rounded-xl border bg-card p-3 transition hover:shadow-sm">
                        <button type="button" disabled={busy === task.key} onClick={() => onDone(task)} aria-label={t('Mark as done')} className="text-muted-foreground hover:text-emerald-600">
                            {busy === task.key ? <CheckCircle2 className="h-5 w-5 text-emerald-600" /> : <Circle className="h-5 w-5" />}
                        </button>
                        <div className="min-w-0 flex-1">
                            <div className="truncate text-sm font-medium">{task.name}</div>
                            <div className="truncate text-xs text-muted-foreground">
                                <Link href={route(task.kind === 'lead' ? 'crm.leads.index' : 'crm.deals.index', { open: task.parent_id })} className="hover:underline">
                                    {task.kind === 'lead' ? t('Lead') : t('Deal')}: {task.title}
                                </Link>
                                {task.due_date && <> · {task.due_date}{task.due_time ? ` ${task.due_time.slice(0, 5)}` : ''}</>}
                            </div>
                        </div>
                        <span className={`rounded-full px-2 py-0.5 text-xs font-medium capitalize ${PRIORITY[task.priority] ?? ''}`}>{t(task.priority)}</span>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}

export default function Index({ overdue, dueToday, upcoming }: Props) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState<string | null>(null);
    const total = overdue.length + dueToday.length + upcoming.length;

    const done = (task: Task) => {
        setBusy(task.key);
        axios.post(task.toggle, {}, { headers: { Accept: 'application/json' } })
            .finally(() => router.reload({ onFinish: () => setBusy(null) }));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('My Tasks')}</h2>}>
            <Head title={t('My Tasks')} />
            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="grid grid-cols-3 gap-3">
                    {[
                        [t('Overdue'), overdue.length, 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'],
                        [t('Due today'), dueToday.length, 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
                        [t('Upcoming'), upcoming.length, 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'],
                    ].map(([label, n, cls]) => (
                        <div key={String(label)} className={`rounded-2xl p-4 ${cls}`}>
                            <div className="text-xs font-medium opacity-80">{label}</div>
                            <div className="text-3xl font-bold">{n}</div>
                        </div>
                    ))}
                </div>

                {total === 0 && (
                    <Card><CardContent className="py-12 text-center text-muted-foreground">{t('All caught up. Add follow-up tasks on a lead or deal and they show up here.')}</CardContent></Card>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    <Group title={t('Overdue')} icon={AlarmClock} tone="from-rose-500 to-orange-400" tasks={overdue} onDone={done} busy={busy} empty={t('Nothing overdue')} />
                    <Group title={t('Due today')} icon={CalendarCheck} tone="from-amber-500 to-yellow-400" tasks={dueToday} onDone={done} busy={busy} empty={t('Nothing due today')} />
                    <Group title={t('Upcoming')} icon={CalendarClock} tone="from-[#533afd] to-[#665efd]" tasks={upcoming} onDone={done} busy={busy} empty={t('Nothing planned')} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
