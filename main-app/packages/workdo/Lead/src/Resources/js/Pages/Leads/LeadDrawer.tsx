import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import axios from 'axios';
import { Check, Download, Trash2 } from 'lucide-react';
import { FormEvent, useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Named { id: number; name: string }
interface Creator { creator?: { name: string } | null; creator_id: number | null; created_at: string }
interface Detail {
    id: number;
    subject: string;
    name: string;
    email: string | null;
    phone: string | null;
    notes: string | null;
    stage: { name: string };
    pipeline: { name: string };
    users: Named[];
    sources: Named[];
    labels: (Named & { color: string })[];
    products: (Named & { sku: string })[];
    tasks: (Creator & { id: number; name: string; due_date: string; due_time: string | null; priority: string; status: string })[];
    calls: (Creator & { id: number; subject: string; call_type: string; duration_minutes: number; description: string | null; result: string | null })[];
    emails: (Creator & { id: number; to: string; subject: string; description: string | null })[];
    discussions: (Creator & { id: number; comment: string })[];
    files: (Creator & { id: number; file_name: string; file_size: number })[];
    activities: { id: number; type: string; remark: string; created_at: string; user: { name: string } | null }[];
}

interface Props {
    leadId: number | null;
    onClose: () => void;
    sourceOptions: Named[];
    labelOptions: (Named & { color: string })[];
    productOptions: (Named & { sku: string })[];
    can: { edit: boolean; delete: boolean };
    canDetail: Record<'task' | 'call' | 'email' | 'discussion' | 'file', boolean>;
}

type Tab = 'overview' | 'tasks' | 'calls' | 'emails' | 'discussion' | 'files' | 'activity';
const TABS: [Tab, string][] = [['overview', 'Overview'], ['tasks', 'Tasks'], ['calls', 'Calls'], ['emails', 'Emails'], ['discussion', 'Discussion'], ['files', 'Files'], ['activity', 'Activity']];
const day = (d: string) => d.slice(0, 10);
const size = (b: number) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);

/** The lead drawer: one JSON endpoint per action, every answer is the refreshed lead. */
export default function LeadDrawer({ leadId, onClose, sourceOptions, labelOptions, productOptions, can, canDetail }: Props) {
    const { t } = useTranslation();
    const [lead, setLead] = useState<Detail | null>(null);
    const [me, setMe] = useState<number | null>(null);
    const [tab, setTab] = useState<Tab>('overview');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [productSearch, setProductSearch] = useState('');

    const call = useCallback(async (fn: () => Promise<{ data: { lead: Detail; me: number } }>) => {
        setBusy(true);
        setErrors({});
        setMessage(null);
        try {
            const { data } = await fn();
            setLead(data.lead);
            setMe(data.me);
            return true;
        } catch (e) {
            const err = e as { response?: { status: number; data: { message?: string; errors?: Record<string, string[]> } } };
            if (err.response?.status === 422) setErrors(Object.fromEntries(Object.entries(err.response.data.errors ?? {}).map(([k, v]) => [k, v[0]])));
            else setMessage(err.response?.data?.message ?? t('Something went wrong.'));
            return false;
        } finally {
            setBusy(false);
        }
    }, [t]);

    useEffect(() => {
        setLead(null);
        setTab('overview');
        if (leadId) call(() => axios.get(route('crm.leads.detail', leadId), { headers: { Accept: 'application/json' } }));
    }, [leadId, call]);

    const base = (name: string, extra: unknown[] = []) => route(name, [leadId, ...extra]);
    const add = (kind: string, data: Record<string, unknown>) => call(() => axios.post(base('crm.leads.items.add', [kind]), data));
    const remove = (kind: string, id: number) => call(() => axios.delete(base('crm.leads.items.remove', [kind, id])));
    const sync = (patch: Record<string, number[]>) => call(() => axios.put(base('crm.leads.sync'), { source_ids: lead!.sources.map((s) => s.id), label_ids: lead!.labels.map((l) => l.id), product_ids: lead!.products.map((p) => p.id), ...patch }));

    const toggle = (current: Named[], id: number, on: boolean) => (on ? [...current.map((c) => c.id), id] : current.filter((c) => c.id !== id).map((c) => c.id));
    const mine = (item: Creator) => item.creator_id === me || can.delete;
    const err = (k: string) => errors[k] && <p className="text-sm text-destructive">{errors[k]}</p>;
    const by = (item: Creator) => `${item.creator?.name ?? '—'} · ${day(item.created_at)}`;

    // small local forms
    const [task, setTask] = useState({ name: '', due_date: '', due_time: '', priority: 'medium' });
    const [callForm, setCallForm] = useState({ subject: '', call_type: 'outbound', duration_minutes: '5', description: '', result: '' });
    const [mail, setMail] = useState({ to: '', subject: '', description: '' });
    const [comment, setComment] = useState('');
    const [file, setFile] = useState<File | null>(null);

    const submit = (kind: string, data: Record<string, unknown>, reset: () => void) => async (e: FormEvent) => {
        e.preventDefault();
        if (await add(kind, data)) reset();
    };

    const upload = async (e: FormEvent) => {
        e.preventDefault();
        if (!file) return;
        const fd = new FormData();
        fd.append('file', file);
        if (await call(() => axios.post(base('crm.leads.files.upload'), fd, { headers: { Accept: 'application/json' } }))) setFile(null);
    };

    const products = productOptions.filter((p) => !productSearch || `${p.name} ${p.sku}`.toLowerCase().includes(productSearch.toLowerCase())).slice(0, 30);

    return (
        <Dialog open={leadId !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[92vh] max-w-3xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{lead ? lead.subject : t('Lead')}</DialogTitle>
                </DialogHeader>

                {message && <p className="rounded bg-destructive/10 p-2 text-sm text-destructive">{message}</p>}
                {!lead && !message && <p className="py-8 text-center text-sm text-muted-foreground">{t('Loading...')}</p>}

                {lead && (
                    <div className="space-y-4">
                        <div className="flex flex-wrap gap-1 border-b pb-2">
                            {TABS.map(([k, label]) => <Button key={k} size="sm" variant={tab === k ? 'default' : 'ghost'} onClick={() => setTab(k)}>{t(label)}</Button>)}
                        </div>

                        {tab === 'overview' && (
                            <div className="space-y-5 text-sm">
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <div><span className="text-muted-foreground">{t('Name')}:</span> {lead.name}</div>
                                    <div><span className="text-muted-foreground">{t('Stage')}:</span> {lead.pipeline.name} / {lead.stage.name}</div>
                                    <div><span className="text-muted-foreground">{t('Email')}:</span> {lead.email ?? '—'}</div>
                                    <div><span className="text-muted-foreground">{t('Phone')}:</span> {lead.phone ?? '—'}</div>
                                    <div className="sm:col-span-2"><span className="text-muted-foreground">{t('Assigned to')}:</span> {lead.users.map((u) => u.name).join(', ') || '—'}</div>
                                    {lead.notes && <p className="whitespace-pre-line sm:col-span-2">{lead.notes}</p>}
                                </div>

                                <div className="space-y-2">
                                    <Label>{t('Sources')}</Label>
                                    <div className="flex flex-wrap gap-3">
                                        {sourceOptions.map((s) => (
                                            <label key={s.id} className="flex items-center gap-1.5"><Checkbox disabled={!can.edit || busy} checked={lead.sources.some((x) => x.id === s.id)} onCheckedChange={(v) => sync({ source_ids: toggle(lead.sources, s.id, v === true) })} />{s.name}</label>
                                        ))}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label>{t('Labels')}</Label>
                                    <div className="flex flex-wrap gap-3">
                                        {labelOptions.map((l) => (
                                            <label key={l.id} className="flex items-center gap-1.5">
                                                <Checkbox disabled={!can.edit || busy} checked={lead.labels.some((x) => x.id === l.id)} onCheckedChange={(v) => sync({ label_ids: toggle(lead.labels, l.id, v === true) })} />
                                                <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: l.color }} />{l.name}
                                            </label>
                                        ))}
                                    </div>
                                    {err('label_ids.0')}
                                </div>

                                <div className="space-y-2">
                                    <Label>{t('Products')}</Label>
                                    <div className="flex flex-wrap gap-1">
                                        {lead.products.length === 0 && <span className="text-muted-foreground">—</span>}
                                        {lead.products.map((p) => (
                                            <Badge key={p.id} variant="secondary" className="gap-1">
                                                {p.name}
                                                {can.edit && <button type="button" aria-label={t('Remove')} onClick={() => sync({ product_ids: lead.products.filter((x) => x.id !== p.id).map((x) => x.id) })}>×</button>}
                                            </Badge>
                                        ))}
                                    </div>
                                    {can.edit && (
                                        <>
                                            <Input value={productSearch} onChange={(e) => setProductSearch(e.target.value)} placeholder={t('Search products...')} className="max-w-xs" />
                                            <div className="flex max-h-28 flex-wrap gap-1 overflow-y-auto">
                                                {products.filter((p) => !lead.products.some((x) => x.id === p.id)).map((p) => (
                                                    <Button key={p.id} type="button" size="sm" variant="outline" disabled={busy} onClick={() => sync({ product_ids: [...lead.products.map((x) => x.id), p.id] })}>+ {p.name}</Button>
                                                ))}
                                            </div>
                                        </>
                                    )}
                                </div>
                            </div>
                        )}

                        {tab === 'tasks' && (
                            <div className="space-y-3">
                                {canDetail.task && (
                                    <form onSubmit={submit('task', { ...task, due_time: task.due_time || null }, () => setTask({ name: '', due_date: '', due_time: '', priority: 'medium' }))} className="grid gap-2 sm:grid-cols-5">
                                        <div className="sm:col-span-2"><Input placeholder={t('Task')} value={task.name} onChange={(e) => setTask({ ...task, name: e.target.value })} />{err('name')}</div>
                                        <div><Input type="date" value={task.due_date} onChange={(e) => setTask({ ...task, due_date: e.target.value })} />{err('due_date')}</div>
                                        <Select value={task.priority} onValueChange={(v) => setTask({ ...task, priority: v })}>
                                            <SelectTrigger><SelectValue /></SelectTrigger>
                                            <SelectContent>{['low', 'medium', 'high'].map((p) => <SelectItem key={p} value={p}>{t(p)}</SelectItem>)}</SelectContent>
                                        </Select>
                                        <Button type="submit" disabled={busy}>{t('Add')}</Button>
                                    </form>
                                )}
                                {lead.tasks.length === 0 && <p className="text-sm text-muted-foreground">{t('No tasks yet.')}</p>}
                                {lead.tasks.map((x) => (
                                    <div key={x.id} className="flex items-center gap-2 rounded border p-2 text-sm">
                                        {canDetail.task && <Button size="icon" variant={x.status === 'completed' ? 'default' : 'outline'} className="h-6 w-6" disabled={busy} onClick={() => call(() => axios.post(base('crm.leads.tasks.toggle', [x.id])))}><Check className="h-3.5 w-3.5" /></Button>}
                                        <div className={`flex-1 ${x.status === 'completed' ? 'text-muted-foreground line-through' : ''}`}>{x.name}<div className="text-xs text-muted-foreground">{day(x.due_date)}{x.due_time ? ` ${x.due_time.slice(0, 5)}` : ''} · {t(x.priority)} · {by(x)}</div></div>
                                        {mine(x) && canDetail.task && <Button size="icon" variant="ghost" className="h-7 w-7" onClick={() => remove('task', x.id)}><Trash2 className="h-3.5 w-3.5 text-destructive" /></Button>}
                                    </div>
                                ))}
                            </div>
                        )}

                        {tab === 'calls' && (
                            <div className="space-y-3">
                                {canDetail.call && (
                                    <form onSubmit={submit('call', { ...callForm, duration_minutes: Number(callForm.duration_minutes) }, () => setCallForm({ ...callForm, subject: '', description: '', result: '' }))} className="space-y-2">
                                        <div className="grid gap-2 sm:grid-cols-4">
                                            <div className="sm:col-span-2"><Input placeholder={t('Subject')} value={callForm.subject} onChange={(e) => setCallForm({ ...callForm, subject: e.target.value })} />{err('subject')}</div>
                                            <Select value={callForm.call_type} onValueChange={(v) => setCallForm({ ...callForm, call_type: v })}>
                                                <SelectTrigger><SelectValue /></SelectTrigger>
                                                <SelectContent><SelectItem value="outbound">{t('Outbound')}</SelectItem><SelectItem value="inbound">{t('Inbound')}</SelectItem></SelectContent>
                                            </Select>
                                            <Input type="number" min={0} title={t('Minutes')} value={callForm.duration_minutes} onChange={(e) => setCallForm({ ...callForm, duration_minutes: e.target.value })} />
                                        </div>
                                        <Textarea placeholder={t('What was discussed')} value={callForm.description} onChange={(e) => setCallForm({ ...callForm, description: e.target.value })} />
                                        <div className="flex gap-2"><Input placeholder={t('Result')} value={callForm.result} onChange={(e) => setCallForm({ ...callForm, result: e.target.value })} /><Button type="submit" disabled={busy}>{t('Log call')}</Button></div>
                                    </form>
                                )}
                                {lead.calls.map((x) => (
                                    <div key={x.id} className="flex items-start gap-2 rounded border p-2 text-sm">
                                        <div className="flex-1"><b>{x.subject}</b> <Badge variant="outline">{t(x.call_type)}</Badge> <span className="text-xs text-muted-foreground">{x.duration_minutes} {t('min')} · {by(x)}</span>
                                            {x.description && <p className="whitespace-pre-line">{x.description}</p>}{x.result && <p className="text-muted-foreground">{t('Result')}: {x.result}</p>}</div>
                                        {mine(x) && canDetail.call && <Button size="icon" variant="ghost" className="h-7 w-7" onClick={() => remove('call', x.id)}><Trash2 className="h-3.5 w-3.5 text-destructive" /></Button>}
                                    </div>
                                ))}
                                {lead.calls.length === 0 && <p className="text-sm text-muted-foreground">{t('No calls yet.')}</p>}
                            </div>
                        )}

                        {tab === 'emails' && (
                            <div className="space-y-3">
                                {canDetail.email && (
                                    <form onSubmit={submit('email', mail, () => setMail({ to: mail.to, subject: '', description: '' }))} className="space-y-2">
                                        <p className="text-xs text-muted-foreground">{t('This keeps a record of an e-mail; it does not send anything.')}</p>
                                        <div className="grid gap-2 sm:grid-cols-2">
                                            <div><Input type="email" placeholder={t('To')} value={mail.to} onChange={(e) => setMail({ ...mail, to: e.target.value })} />{err('to')}</div>
                                            <div><Input placeholder={t('Subject')} value={mail.subject} onChange={(e) => setMail({ ...mail, subject: e.target.value })} />{err('subject')}</div>
                                        </div>
                                        <Textarea placeholder={t('Message')} value={mail.description} onChange={(e) => setMail({ ...mail, description: e.target.value })} />
                                        <Button type="submit" disabled={busy}>{t('Save e-mail')}</Button>
                                    </form>
                                )}
                                {lead.emails.map((x) => (
                                    <div key={x.id} className="flex items-start gap-2 rounded border p-2 text-sm">
                                        <div className="flex-1"><b>{x.subject}</b> <span className="text-xs text-muted-foreground">→ {x.to} · {by(x)}</span>{x.description && <p className="whitespace-pre-line">{x.description}</p>}</div>
                                        {mine(x) && canDetail.email && <Button size="icon" variant="ghost" className="h-7 w-7" onClick={() => remove('email', x.id)}><Trash2 className="h-3.5 w-3.5 text-destructive" /></Button>}
                                    </div>
                                ))}
                                {lead.emails.length === 0 && <p className="text-sm text-muted-foreground">{t('No e-mails yet.')}</p>}
                            </div>
                        )}

                        {tab === 'discussion' && (
                            <div className="space-y-3">
                                {canDetail.discussion && (
                                    <form onSubmit={submit('discussion', { comment }, () => setComment(''))} className="space-y-2">
                                        <Textarea placeholder={t('Write a comment')} value={comment} onChange={(e) => setComment(e.target.value)} />{err('comment')}
                                        <Button type="submit" disabled={busy}>{t('Post')}</Button>
                                    </form>
                                )}
                                {lead.discussions.map((x) => (
                                    <div key={x.id} className="flex items-start gap-2 rounded border p-2 text-sm">
                                        <div className="flex-1"><div className="text-xs text-muted-foreground">{by(x)}</div><p className="whitespace-pre-line">{x.comment}</p></div>
                                        {mine(x) && canDetail.discussion && <Button size="icon" variant="ghost" className="h-7 w-7" onClick={() => remove('discussion', x.id)}><Trash2 className="h-3.5 w-3.5 text-destructive" /></Button>}
                                    </div>
                                ))}
                                {lead.discussions.length === 0 && <p className="text-sm text-muted-foreground">{t('No comments yet.')}</p>}
                            </div>
                        )}

                        {tab === 'files' && (
                            <div className="space-y-3">
                                {canDetail.file && (
                                    <form onSubmit={upload} className="flex flex-wrap items-center gap-2">
                                        <Input type="file" className="max-w-xs" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                                        <Button type="submit" disabled={busy || !file}>{t('Upload')}</Button>
                                        {err('file')}
                                    </form>
                                )}
                                {lead.files.map((x) => (
                                    <div key={x.id} className="flex items-center gap-2 rounded border p-2 text-sm">
                                        <div className="flex-1">{x.file_name} <span className="text-xs text-muted-foreground">{size(x.file_size)} · {by(x)}</span></div>
                                        <Button size="icon" variant="ghost" className="h-7 w-7" asChild><a href={base('crm.leads.files.download', [x.id])}><Download className="h-3.5 w-3.5" /></a></Button>
                                        {mine(x) && canDetail.file && <Button size="icon" variant="ghost" className="h-7 w-7" onClick={() => remove('file', x.id)}><Trash2 className="h-3.5 w-3.5 text-destructive" /></Button>}
                                    </div>
                                ))}
                                {lead.files.length === 0 && <p className="text-sm text-muted-foreground">{t('No files yet.')}</p>}
                            </div>
                        )}

                        {tab === 'activity' && (
                            <ol className="space-y-2 border-l pl-4 text-sm">
                                {lead.activities.map((a) => (
                                    <li key={a.id}><div>{a.remark}</div><div className="text-xs text-muted-foreground">{a.user?.name ?? '—'} · {a.created_at.slice(0, 16).replace('T', ' ')}</div></li>
                                ))}
                            </ol>
                        )}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
