import Pagination from '@/Components/Pagination';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Components/ui/alert-dialog';
import { Card, CardContent } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Paginated } from '@/types';
import { DragDropContext, Draggable, Droppable, DropResult } from '@hello-pangea/dnd';
import { Head, router, useForm } from '@inertiajs/react';
import { CalendarClock, LayoutGrid, List, Mail, Pencil, Phone, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';
import LeadDrawer from './LeadDrawer';
import { useTranslation } from 'react-i18next';

interface Lead {
    id: number;
    subject: string;
    name: string;
    email: string | null;
    phone: string | null;
    notes: string | null;
    follow_up_date: string | null;
    lead_stage_id: number;
    is_active: boolean;
    is_converted: boolean;
    users: { id: number; name: string }[];
    stage?: { name: string };
}

interface Props {
    pipelines: { id: number; name: string }[];
    pipeline: { id: number; name: string };
    stages: { id: number; name: string }[];
    view: 'kanban' | 'list';
    leads: Lead[] | Paginated<Lead>;
    users: { id: number; name: string }[];
    sourceOptions: { id: number; name: string }[];
    labelOptions: { id: number; name: string; color: string }[];
    productOptions: { id: number; name: string; sku: string }[];
    can_detail: Record<'task' | 'call' | 'email' | 'discussion' | 'file', boolean>;
    filters: { search?: string; stage?: string };
    can: { create: boolean; edit: boolean; delete: boolean; move: boolean };
}

const empty = { subject: '', name: '', email: '', phone: '', notes: '', follow_up_date: '', lead_stage_id: '', user_ids: [] as number[], is_active: true };
const ALL = 'all';

export default function LeadsIndex({ pipelines, pipeline, stages, view, leads, users, sourceOptions, labelOptions, productOptions, can_detail, filters, can }: Props) {
    const { t } = useTranslation();
    const list = (Array.isArray(leads) ? leads : leads.data) as Lead[];

    // local copy of the board so a drop shows at once; the server list wins whenever it changes
    const [board, setBoard] = useState<Record<number, Lead[]>>({});
    useEffect(() => {
        if (view !== 'kanban') return;
        setBoard(Object.fromEntries(stages.map((s) => [s.id, list.filter((l) => l.lead_stage_id === s.id)])));
    }, [leads, stages, view]);

    const [editing, setEditing] = useState<Lead | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<Lead | null>(null);
    const [detailId, setDetailId] = useState<number | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const form = useForm(empty);

    const go = (params: Record<string, string | number | undefined>) =>
        router.get(route('crm.leads.index'), Object.fromEntries(Object.entries({ pipeline: pipeline.id, view, ...params }).filter(([, v]) => v !== undefined && v !== '')), { preserveState: true, replace: true });

    const openCreate = (stageId?: number) => {
        setEditing(null);
        form.setData({ ...empty, lead_stage_id: stageId ? String(stageId) : '' });
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (l: Lead) => {
        setEditing(l);
        form.setData({
            subject: l.subject, name: l.name, email: l.email ?? '', phone: l.phone ?? '', notes: l.notes ?? '', follow_up_date: l.follow_up_date ? l.follow_up_date.slice(0, 10) : '',
            lead_stage_id: String(l.lead_stage_id), user_ids: l.users.map((u) => u.id), is_active: l.is_active,
        });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) {
            form.transform((d) => ({ ...d, lead_stage_id: undefined, follow_up_date: d.follow_up_date || null }));
            form.put(route('crm.leads.update', editing.id), options);
        } else {
            form.transform((d) => ({ ...d, pipeline_id: pipeline.id, lead_stage_id: d.lead_stage_id || null, follow_up_date: d.follow_up_date || null }));
            form.post(route('crm.leads.store'), options);
        }
    };

    const onDragEnd = (r: DropResult) => {
        if (!r.destination) return;
        const from = Number(r.source.droppableId);
        const to = Number(r.destination.droppableId);
        if (from === to && r.destination.index === r.source.index) return;

        const next = { ...board, [from]: [...board[from]] };
        const [moved] = next[from].splice(r.source.index, 1);
        next[to] = from === to ? next[from] : [...board[to]];
        next[to].splice(r.destination.index, 0, { ...moved, lead_stage_id: to });
        setBoard(next);

        router.post(route('crm.leads.move'), { lead_id: moved.id, lead_stage_id: to, ids: next[to].map((l) => l.id) }, { preserveScroll: true, onError: () => router.reload(), onFinish: () => undefined });
    };

    const toggleUser = (id: number, on: boolean) => form.setData('user_ids', on ? [...form.data.user_ids, id] : form.data.user_ids.filter((u) => u !== id));
    const err = (k: string) => (form.errors as Record<string, string>)[k] && <p className="text-sm text-destructive">{(form.errors as Record<string, string>)[k]}</p>;

    const actions = (l: Lead) => (
        <span className="flex shrink-0" onClick={(e) => e.stopPropagation()}>
            {can.edit && <Button size="icon" variant="ghost" className="h-7 w-7" onClick={() => openEdit(l)}><Pencil className="h-3.5 w-3.5" /></Button>}
            {can.delete && <Button size="icon" variant="ghost" className="h-7 w-7" onClick={() => setDeleting(l)}><Trash2 className="h-3.5 w-3.5 text-destructive" /></Button>}
        </span>
    );

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Leads')}</h2>}>
            <Head title={t('Leads')} />

            <div className="space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={String(pipeline.id)} onValueChange={(v) => go({ pipeline: v, search: undefined, stage: undefined })}>
                            <SelectTrigger className="w-48"><SelectValue /></SelectTrigger>
                            <SelectContent>{pipelines.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                        </Select>
                        <div className="flex rounded-md border">
                            <Button size="icon" variant={view === 'kanban' ? 'default' : 'ghost'} title={t('Board')} onClick={() => go({ view: 'kanban', search: undefined, stage: undefined })}><LayoutGrid className="h-4 w-4" /></Button>
                            <Button size="icon" variant={view === 'list' ? 'default' : 'ghost'} title={t('List')} onClick={() => go({ view: 'list' })}><List className="h-4 w-4" /></Button>
                        </div>
                        {view === 'list' && (
                            <>
                                <form onSubmit={(e) => { e.preventDefault(); go({ search }); }} className="flex gap-2">
                                    <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search...')} className="w-52" />
                                </form>
                                <Select value={filters.stage ?? ALL} onValueChange={(v) => go({ stage: v === ALL ? undefined : v, search: filters.search })}>
                                    <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL}>{t('All stages')}</SelectItem>
                                        {stages.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </>
                        )}
                    </div>
                    {can.create && <Button onClick={() => openCreate()}><Plus className="mr-1 h-4 w-4" />{t('Add Lead')}</Button>}
                </div>

                {view === 'kanban' ? (
                    <DragDropContext onDragEnd={onDragEnd}>
                        <div className="flex gap-3 overflow-x-auto pb-4">
                            {stages.map((s) => (
                                <div key={s.id} className="w-72 shrink-0 rounded-lg bg-muted/50 p-2">
                                    <div className="mb-2 flex items-center justify-between px-1">
                                        <span className="text-sm font-semibold">{s.name} <Badge variant="outline" className="ml-1">{board[s.id]?.length ?? 0}</Badge></span>
                                        {can.create && <Button size="icon" variant="ghost" className="h-7 w-7" onClick={() => openCreate(s.id)}><Plus className="h-4 w-4" /></Button>}
                                    </div>
                                    <Droppable droppableId={String(s.id)} isDropDisabled={!can.move}>
                                        {(drop, snap) => (
                                            <div ref={drop.innerRef} {...drop.droppableProps} className={`min-h-24 space-y-2 rounded-md p-1 ${snap.isDraggingOver ? 'bg-primary/5' : ''}`}>
                                                {(board[s.id] ?? []).map((l, i) => (
                                                    <Draggable key={l.id} draggableId={String(l.id)} index={i} isDragDisabled={!can.move || l.is_converted}>
                                                        {(drag, dragSnap) => (
                                                            <div ref={drag.innerRef} {...drag.draggableProps} {...drag.dragHandleProps}>
                                                                <Card className={`cursor-pointer ${dragSnap.isDragging ? 'shadow-lg' : ''} ${l.is_active ? '' : 'opacity-60'}`} onClick={() => setDetailId(l.id)}>
                                                                    <CardContent className="space-y-1 p-3 text-sm">
                                                                        <div className="flex items-start justify-between gap-1">
                                                                            <div className="font-medium">{l.subject}</div>
                                                                            {actions(l)}
                                                                        </div>
                                                                        <div className="text-muted-foreground">{l.name}</div>
                                                                        {l.email && <div className="flex items-center gap-1 truncate text-xs text-muted-foreground"><Mail className="h-3 w-3" />{l.email}</div>}
                                                                        {l.phone && <div className="flex items-center gap-1 text-xs text-muted-foreground"><Phone className="h-3 w-3" />{l.phone}</div>}
                                                                        {l.follow_up_date && <div className="flex items-center gap-1 text-xs text-muted-foreground"><CalendarClock className="h-3 w-3" />{l.follow_up_date.slice(0, 10)}</div>}
                                                                        {l.users.length > 0 && <div className="flex flex-wrap gap-1 pt-1">{l.users.map((u) => <Badge key={u.id} variant="secondary" className="text-[10px]">{u.name}</Badge>)}</div>}
                                                                        {l.is_converted && <Badge>{t('Converted')}</Badge>}
                                                                    </CardContent>
                                                                </Card>
                                                            </div>
                                                        )}
                                                    </Draggable>
                                                ))}
                                                {drop.placeholder}
                                            </div>
                                        )}
                                    </Droppable>
                                </div>
                            ))}
                        </div>
                    </DragDropContext>
                ) : (
                    <>
                        <Card>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t('Subject')}</TableHead>
                                            <TableHead>{t('Name')}</TableHead>
                                            <TableHead>{t('Email')}</TableHead>
                                            <TableHead>{t('Stage')}</TableHead>
                                            <TableHead>{t('Assigned to')}</TableHead>
                                            <TableHead>{t('Follow up')}</TableHead>
                                            <TableHead className="text-right">{t('Actions')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {list.length === 0 && <TableRow><TableCell colSpan={7} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell></TableRow>}
                                        {list.map((l) => (
                                            <TableRow key={l.id} className={l.is_active ? '' : 'opacity-60'}>
                                                <TableCell className="font-medium"><button type="button" className="text-left hover:underline" onClick={() => setDetailId(l.id)}>{l.subject}</button></TableCell>
                                                <TableCell>{l.name}</TableCell>
                                                <TableCell>{l.email ?? '—'}</TableCell>
                                                <TableCell><Badge variant="outline">{l.stage?.name}</Badge></TableCell>
                                                <TableCell>{l.users.map((u) => u.name).join(', ') || '—'}</TableCell>
                                                <TableCell>{l.follow_up_date ? l.follow_up_date.slice(0, 10) : '—'}</TableCell>
                                                <TableCell className="text-right">{actions(l)}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                        {!Array.isArray(leads) && <Pagination meta={leads} />}
                    </>
                )}
            </div>

            <LeadDrawer leadId={detailId} onClose={() => setDetailId(null)} sourceOptions={sourceOptions} labelOptions={labelOptions} productOptions={productOptions} can={{ edit: can.edit, delete: can.delete }} canDetail={can_detail} />

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogHeader><DialogTitle>{editing ? t('Edit Lead') : t('Add Lead')}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1"><Label>{t('Subject')}</Label><Input value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />{err('subject')}</div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1"><Label>{t('Name')}</Label><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />{err('name')}</div>
                            <div className="space-y-1"><Label>{t('Phone')}</Label><Input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />{err('phone')}</div>
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1"><Label>{t('Email')}</Label><Input type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />{err('email')}</div>
                            <div className="space-y-1"><Label>{t('Follow up')}</Label><Input type="date" value={form.data.follow_up_date} onChange={(e) => form.setData('follow_up_date', e.target.value)} />{err('follow_up_date')}</div>
                        </div>
                        {!editing && (
                            <div className="space-y-1">
                                <Label>{t('Stage')}</Label>
                                <Select value={form.data.lead_stage_id || String(stages[0]?.id ?? '')} onValueChange={(v) => form.setData('lead_stage_id', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{stages.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}</SelectContent>
                                </Select>
                                {err('lead_stage_id')}
                            </div>
                        )}
                        <div className="space-y-1"><Label>{t('Notes')}</Label><Textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />{err('notes')}</div>
                        <div className="space-y-2">
                            <Label>{t('Assigned to')}</Label>
                            <div className="grid grid-cols-2 gap-2">
                                {users.map((u) => (
                                    <label key={u.id} className="flex items-center gap-2 text-sm"><Checkbox checked={form.data.user_ids.includes(u.id)} onCheckedChange={(v) => toggleUser(u.id, v === true)} />{u.name}</label>
                                ))}
                            </div>
                            {err('user_ids.0')}
                        </div>
                        {editing && <label className="flex items-center gap-2 text-sm"><Checkbox checked={form.data.is_active} onCheckedChange={(v) => form.setData('is_active', v === true)} />{t('Active')}</label>}
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{editing ? t('Update') : t('Create')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete')} {deleting?.subject}?</AlertDialogTitle>
                        <AlertDialogDescription>{t('The lead and its history are deleted for good.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('crm.leads.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
