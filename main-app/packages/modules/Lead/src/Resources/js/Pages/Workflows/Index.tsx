import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/Components/ui/alert-dialog';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { ArrowRight, Pencil, Play, Plus, Trash2, Workflow as WorkflowIcon, X, Zap } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

type Module = 'lead' | 'deal';
type Kind = 'text' | 'number' | 'id' | 'set';

interface Condition { field: string; operator: string; value: string | null }
interface Action { type: string; name?: string; due_days?: number; priority?: string; to?: string; email?: string; subject?: string; body?: string; stage_id?: number | string; user_id?: number | string; text?: string }
interface Rule { id: number; name: string; module: Module; trigger: string; match: 'all' | 'any'; conditions: Condition[]; actions: Action[]; is_active: boolean; run_count: number; last_run_at: string | null }
interface Run { id: number; status: 'ok' | 'failed'; message: string | null; record_type: string; record_id: number; created_at: string; workflow: { id: number; name: string } | null }
interface Named { id: number; name: string }

interface Props {
    workflows: Rule[];
    runs: Run[];
    pipelines: Named[];
    leadStages: (Named & { pipeline_id: number })[];
    dealStages: (Named & { pipeline_id: number })[];
    sources: Named[];
    labels: Named[];
    users: Named[];
    triggers: Record<Module, string[]>;
    fields: Record<Module, Record<string, Kind>>;
    can: { edit: boolean };
}

const OPERATORS: Record<Kind, string[]> = {
    text: ['eq', 'neq', 'contains', 'empty', 'not_empty'],
    number: ['eq', 'neq', 'gt', 'lt', 'empty', 'not_empty'],
    id: ['eq', 'neq'],
    set: ['eq', 'neq', 'empty', 'not_empty'],
};
const OPERATOR_LABEL: Record<string, string> = { eq: 'is', neq: 'is not', contains: 'contains', gt: 'is greater than', lt: 'is less than', empty: 'is empty', not_empty: 'is not empty' };
const TRIGGER_LABEL: Record<string, string> = { created: 'is created', stage_changed: 'moves to another stage', won: 'is won', lost: 'is lost' };
const ACTION_LABEL: Record<string, string> = { create_task: 'Create a task', send_email: 'Send an e-mail', change_stage: 'Move to a stage', assign_user: 'Assign a user', add_note: 'Add a note to the activity' };
const EMPTY_OPS = ['empty', 'not_empty'];

const blankRule = () => ({
    name: '', module: 'lead' as Module, trigger: 'created', match: 'all' as 'all' | 'any', is_active: true,
    conditions: [] as Condition[], actions: [{ type: 'create_task', name: '', due_days: 1, priority: 'medium' }] as Action[],
});

export default function Index({ workflows, runs, pipelines, leadStages, dealStages, sources, labels, users, triggers, fields, can }: Props) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<Rule | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Rule | null>(null);
    const form = useForm(blankRule());

    const stages = (m: Module) => (m === 'lead' ? leadStages : dealStages);
    const pipelineName = (id: number) => pipelines.find((p) => p.id === id)?.name ?? '';
    const options = (m: Module, field: string): Named[] => {
        if (field === 'stage') return stages(m).map((s) => ({ id: s.id, name: `${pipelineName(s.pipeline_id)} › ${s.name}` }));
        if (field === 'pipeline') return pipelines;
        if (field === 'source') return sources;
        return labels;
    };

    const open = (rule: Rule | 'new') => {
        form.clearErrors();
        form.setData(rule === 'new' ? blankRule() : {
            name: rule.name, module: rule.module, trigger: rule.trigger, match: rule.match, is_active: rule.is_active,
            conditions: rule.conditions.map((c) => ({ ...c, value: c.value == null ? '' : String(c.value) })), actions: rule.actions.map((a) => ({ ...a })),
        });
        setEditing(rule);
    };

    const save = () => {
        const opts = { preserveScroll: true, onSuccess: () => setEditing(null) };
        editing === 'new' || editing === null ? form.post(route('crm.workflows.store'), opts) : form.put(route('crm.workflows.update', editing.id), opts);
    };

    const setModule = (m: Module) => form.setData({ ...form.data, module: m, trigger: triggers[m][0], conditions: [], actions: form.data.actions.filter((a) => a.type !== 'change_stage') });
    const setCondition = (i: number, patch: Partial<Condition>) => form.setData('conditions', form.data.conditions.map((c, j) => (j === i ? { ...c, ...patch } : c)));
    const setAction = (i: number, patch: Partial<Action>) => form.setData('actions', form.data.actions.map((a, j) => (j === i ? { ...a, ...patch } : a)));
    const firstError = Object.values(form.errors)[0];

    const describe = (r: Rule) => {
        const when = `${r.module === 'lead' ? t('Lead') : t('Deal')} ${t(TRIGGER_LABEL[r.trigger] ?? r.trigger)}`;
        return when;
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Workflows')}</h2>}>
            <Head title={t('Workflows')} />
            <div className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="max-w-2xl text-sm text-muted-foreground">{t('Automate follow-ups: when something happens to a lead or deal, and your conditions match, the actions run by themselves.')}</p>
                    {can.edit && <Button onClick={() => open('new')}><Plus className="mr-1 h-4 w-4" />{t('New Workflow')}</Button>}
                </div>

                {workflows.length === 0 && (
                    <Card><CardContent className="flex flex-col items-center gap-2 py-14 text-center text-muted-foreground">
                        <WorkflowIcon className="h-10 w-10 text-primary/60" />
                        <div className="font-medium text-foreground">{t('No workflows yet')}</div>
                        <div className="text-sm">{t('Example: when a deal is won, create a "Send thank-you" task and e-mail the team.')}</div>
                    </CardContent></Card>
                )}

                <div className="space-y-3">
                    {workflows.map((r) => (
                        <Card key={r.id} className={r.is_active ? '' : 'opacity-70'}>
                            <CardContent className="flex flex-wrap items-center gap-4 p-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"><Zap className="h-5 w-5" /></div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2 font-semibold">{r.name} <Badge variant={r.is_active ? 'success' : 'secondary'}>{r.is_active ? t('Active') : t('Off')}</Badge></div>
                                    <div className="mt-1 flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground">
                                        <span>{t('When a')} {describe(r)}</span>
                                        {r.conditions.length > 0 && <span>· {r.conditions.length} {t('condition(s)')} ({t(r.match === 'all' ? 'all must match' : 'any can match')})</span>}
                                        <ArrowRight className="h-3.5 w-3.5" />
                                        <span>{r.actions.map((a) => t(ACTION_LABEL[a.type] ?? a.type)).join(', ')}</span>
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">{t('Ran')} {r.run_count} {t('time(s)')}{r.last_run_at ? ` · ${t('last')} ${new Date(r.last_run_at).toLocaleString()}` : ''}</div>
                                </div>
                                {can.edit && (
                                    <div className="flex items-center gap-1">
                                        <Button size="sm" variant="outline" onClick={() => router.post(route('crm.workflows.toggle', r.id), {}, { preserveScroll: true })}>
                                            <Play className="mr-1 h-3.5 w-3.5" />{r.is_active ? t('Switch off') : t('Switch on')}
                                        </Button>
                                        <Button size="icon" variant="ghost" onClick={() => open(r)} aria-label={t('Edit')}><Pencil className="h-4 w-4" /></Button>
                                        <Button size="icon" variant="ghost" className="text-destructive" onClick={() => setDeleting(r)} aria-label={t('Delete')}><Trash2 className="h-4 w-4" /></Button>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {runs.length > 0 && (
                    <div>
                        <h3 className="mb-2 text-sm font-semibold">{t('Recent runs')}</h3>
                        <Card><CardContent className="divide-y p-0">
                            {runs.map((run) => (
                                <div key={run.id} className="flex flex-wrap items-center gap-3 px-4 py-2.5 text-sm">
                                    <Badge variant={run.status === 'ok' ? 'success' : 'danger'}>{run.status === 'ok' ? t('ok') : t('failed')}</Badge>
                                    <span className="font-medium">{run.workflow?.name}</span>
                                    <span className="text-muted-foreground">{run.record_type} #{run.record_id}</span>
                                    <span className="min-w-0 flex-1 truncate text-muted-foreground">{run.message}</span>
                                    <span className="text-xs text-muted-foreground">{new Date(run.created_at).toLocaleString()}</span>
                                </div>
                            ))}
                        </CardContent></Card>
                    </div>
                )}
            </div>

            <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                    <DialogHeader><DialogTitle>{editing === 'new' ? t('New Workflow') : t('Edit Workflow')}</DialogTitle></DialogHeader>
                    <div className="space-y-5">
                        {firstError && <div className="rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{firstError}</div>}

                        <div className="space-y-1.5">
                            <Label>{t('Name')}</Label>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder={t('e.g. Thank every new customer')} />
                        </div>

                        <section className="space-y-2 rounded-xl bg-muted/40 p-4">
                            <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">1 · {t('When')}</div>
                            <div className="grid gap-2 sm:grid-cols-2">
                                <Select value={form.data.module} onValueChange={(v) => setModule(v as Module)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent><SelectItem value="lead">{t('a Lead')}</SelectItem><SelectItem value="deal">{t('a Deal')}</SelectItem></SelectContent>
                                </Select>
                                <Select value={form.data.trigger} onValueChange={(v) => form.setData('trigger', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{triggers[form.data.module].map((tr) => <SelectItem key={tr} value={tr}>{t(TRIGGER_LABEL[tr])}</SelectItem>)}</SelectContent>
                                </Select>
                            </div>
                        </section>

                        <section className="space-y-2 rounded-xl bg-muted/40 p-4">
                            <div className="flex items-center justify-between">
                                <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">2 · {t('Only if')} <span className="font-normal normal-case">({t('optional')})</span></div>
                                {form.data.conditions.length > 1 && (
                                    <Select value={form.data.match} onValueChange={(v) => form.setData('match', v as 'all' | 'any')}>
                                        <SelectTrigger className="h-8 w-44"><SelectValue /></SelectTrigger>
                                        <SelectContent><SelectItem value="all">{t('All conditions match')}</SelectItem><SelectItem value="any">{t('Any condition matches')}</SelectItem></SelectContent>
                                    </Select>
                                )}
                            </div>
                            {form.data.conditions.map((c, i) => {
                                const kind = fields[form.data.module][c.field] ?? 'text';
                                const pick = kind === 'id' || kind === 'set';
                                return (
                                    <div key={i} className="grid grid-cols-[1fr_1fr_1.4fr_auto] gap-2">
                                        <Select value={c.field} onValueChange={(v) => setCondition(i, { field: v, operator: 'eq', value: '' })}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>{Object.keys(fields[form.data.module]).map((f) => <SelectItem key={f} value={f} className="capitalize">{t(f)}</SelectItem>)}</SelectContent>
                                        </Select>
                                        <Select value={c.operator} onValueChange={(v) => setCondition(i, { operator: v })}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>{OPERATORS[kind].map((o) => <SelectItem key={o} value={o}>{t(OPERATOR_LABEL[o])}</SelectItem>)}</SelectContent>
                                        </Select>
                                        {EMPTY_OPS.includes(c.operator) ? <div /> : pick ? (
                                            <Select value={c.value ?? ''} onValueChange={(v) => setCondition(i, { value: v })}>
                                                <SelectTrigger><SelectValue placeholder={t('Choose')} /></SelectTrigger>
                                                <SelectContent>{options(form.data.module, c.field).map((o) => <SelectItem key={o.id} value={String(o.id)}>{o.name}</SelectItem>)}</SelectContent>
                                            </Select>
                                        ) : <Input value={c.value ?? ''} type={kind === 'number' ? 'number' : 'text'} onChange={(e) => setCondition(i, { value: e.target.value })} />}
                                        <Button type="button" size="icon" variant="ghost" onClick={() => form.setData('conditions', form.data.conditions.filter((_, j) => j !== i))} aria-label={t('Remove')}><X className="h-4 w-4" /></Button>
                                    </div>
                                );
                            })}
                            <Button type="button" size="sm" variant="outline" onClick={() => form.setData('conditions', [...form.data.conditions, { field: Object.keys(fields[form.data.module])[0], operator: 'eq', value: '' }])}>
                                <Plus className="mr-1 h-3.5 w-3.5" />{t('Add condition')}
                            </Button>
                        </section>

                        <section className="space-y-3 rounded-xl bg-muted/40 p-4">
                            <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">3 · {t('Then do')}</div>
                            {form.data.actions.map((a, i) => (
                                <div key={i} className="space-y-2 rounded-lg border bg-card p-3">
                                    <div className="flex gap-2">
                                        <Select value={a.type} onValueChange={(v) => setAction(i, { type: v })}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>{Object.entries(ACTION_LABEL).map(([k, label]) => <SelectItem key={k} value={k}>{t(label)}</SelectItem>)}</SelectContent>
                                        </Select>
                                        {form.data.actions.length > 1 && <Button type="button" size="icon" variant="ghost" onClick={() => form.setData('actions', form.data.actions.filter((_, j) => j !== i))} aria-label={t('Remove')}><X className="h-4 w-4" /></Button>}
                                    </div>
                                    {a.type === 'create_task' && (
                                        <div className="grid gap-2 sm:grid-cols-[2fr_1fr_1fr]">
                                            <Input value={a.name ?? ''} onChange={(e) => setAction(i, { name: e.target.value })} placeholder={t('Task, e.g. Call {{name}}')} />
                                            <Input type="number" min={0} max={365} value={a.due_days ?? 1} onChange={(e) => setAction(i, { due_days: Number(e.target.value) })} title={t('Due in (days)')} />
                                            <Select value={a.priority ?? 'medium'} onValueChange={(v) => setAction(i, { priority: v })}>
                                                <SelectTrigger><SelectValue /></SelectTrigger>
                                                <SelectContent><SelectItem value="low">{t('low')}</SelectItem><SelectItem value="medium">{t('medium')}</SelectItem><SelectItem value="high">{t('high')}</SelectItem></SelectContent>
                                            </Select>
                                        </div>
                                    )}
                                    {a.type === 'send_email' && (
                                        <div className="space-y-2">
                                            <div className="grid gap-2 sm:grid-cols-2">
                                                <Select value={a.to ?? 'assignees'} onValueChange={(v) => setAction(i, { to: v })}>
                                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="assignees">{t('The people assigned')}</SelectItem>
                                                        <SelectItem value="contact">{t('The lead / client')}</SelectItem>
                                                        <SelectItem value="custom">{t('A specific address')}</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                                {a.to === 'custom' && <Input type="email" value={a.email ?? ''} onChange={(e) => setAction(i, { email: e.target.value })} placeholder="name@example.com" />}
                                            </div>
                                            <Input value={a.subject ?? ''} onChange={(e) => setAction(i, { subject: e.target.value })} placeholder={t('Subject')} />
                                            <Textarea rows={3} value={a.body ?? ''} onChange={(e) => setAction(i, { body: e.target.value })} placeholder={t('Message, e.g. Hi, please follow up with {{name}}.')} />
                                        </div>
                                    )}
                                    {a.type === 'change_stage' && (
                                        <Select value={a.stage_id ? String(a.stage_id) : ''} onValueChange={(v) => setAction(i, { stage_id: Number(v) })}>
                                            <SelectTrigger><SelectValue placeholder={t('Choose a stage')} /></SelectTrigger>
                                            <SelectContent>{options(form.data.module, 'stage').map((o) => <SelectItem key={o.id} value={String(o.id)}>{o.name}</SelectItem>)}</SelectContent>
                                        </Select>
                                    )}
                                    {a.type === 'assign_user' && (
                                        <Select value={a.user_id ? String(a.user_id) : ''} onValueChange={(v) => setAction(i, { user_id: Number(v) })}>
                                            <SelectTrigger><SelectValue placeholder={t('Choose a user')} /></SelectTrigger>
                                            <SelectContent>{users.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}</SelectContent>
                                        </Select>
                                    )}
                                    {a.type === 'add_note' && <Input value={a.text ?? ''} onChange={(e) => setAction(i, { text: e.target.value })} placeholder={t('Note, e.g. Qualified, now {{stage}}')} />}
                                </div>
                            ))}
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <Button type="button" size="sm" variant="outline" onClick={() => form.setData('actions', [...form.data.actions, { type: 'create_task', name: '', due_days: 1, priority: 'medium' }])}>
                                    <Plus className="mr-1 h-3.5 w-3.5" />{t('Add action')}
                                </Button>
                                <span className="text-xs text-muted-foreground">{t('You can use')} {'{{name}} {{title}} {{stage}} {{price}}'}</span>
                            </div>
                        </section>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setEditing(null)}>{t('Cancel')}</Button>
                        <Button onClick={save} disabled={form.processing}>{t('Save')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete this workflow?')}</AlertDialogTitle>
                        <AlertDialogDescription>{deleting?.name}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('crm.workflows.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
