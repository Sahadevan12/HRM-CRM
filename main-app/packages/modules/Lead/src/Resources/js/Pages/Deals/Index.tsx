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
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Paginated } from '@/types';
import { DragDropContext, Draggable, Droppable, DropResult } from '@hello-pangea/dnd';
import { Head, router, useForm } from '@inertiajs/react';
import { LayoutGrid, List, Pencil, Plus, RotateCcw, ThumbsDown, ThumbsUp, Trash2 } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import LeadDrawer from '../Leads/LeadDrawer';

interface Named { id: number; name: string }
interface Deal {
    id: number;
    name: string;
    price: number;
    phone: string | null;
    notes: string | null;
    deal_stage_id: number;
    status: 'active' | 'won' | 'lost';
    is_active: boolean;
    users: Named[];
    clients: Named[];
    stage?: { name: string };
}

interface Props {
    pipelines: Named[];
    pipeline: Named;
    stages: Named[];
    view: 'kanban' | 'list';
    deals: Deal[] | Paginated<Deal>;
    users: Named[];
    clients: Named[];
    sourceOptions: Named[];
    labelOptions: (Named & { color: string })[];
    productOptions: (Named & { sku: string })[];
    filters: { search?: string; stage?: string; status?: string };
    can: { create: boolean; edit: boolean; delete: boolean; move: boolean; status: boolean };
    can_detail: Record<'task' | 'call' | 'email' | 'discussion' | 'file', boolean>;
}

const empty = { name: '', price: '', phone: '', notes: '', deal_stage_id: '', user_ids: [] as number[], client_ids: [] as number[], is_active: true };
const ALL = 'all';
const tone = { active: 'outline', won: 'default', lost: 'destructive' } as const;

export default function DealsIndex({ pipelines, pipeline, stages, view, deals, users, clients, sourceOptions, labelOptions, productOptions, filters, can, can_detail }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const list = (Array.isArray(deals) ? deals : deals.data) as Deal[];

    const [board, setBoard] = useState<Record<number, Deal[]>>({});
    useEffect(() => {
        if (view !== 'kanban') return;
        setBoard(Object.fromEntries(stages.map((s) => [s.id, list.filter((d) => d.deal_stage_id === s.id)])));
    }, [deals, stages, view]);

    const [editing, setEditing] = useState<Deal | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<Deal | null>(null);
    const [detailId, setDetailId] = useState<number | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const form = useForm(empty);

    const go = (params: Record<string, string | number | undefined>) =>
        router.get(route('crm.deals.index'), Object.fromEntries(Object.entries({ pipeline: pipeline.id, view, ...params }).filter(([, v]) => v !== undefined && v !== '')), { preserveState: true, replace: true });

    const openCreate = (stageId?: number) => {
        setEditing(null);
        form.setData({ ...empty, deal_stage_id: stageId ? String(stageId) : '' });
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (d: Deal) => {
        setEditing(d);
        form.setData({ name: d.name, price: String(d.price), phone: d.phone ?? '', notes: d.notes ?? '', deal_stage_id: String(d.deal_stage_id), user_ids: d.users.map((u) => u.id), client_ids: d.clients.map((c) => c.id), is_active: d.is_active });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) {
            form.transform((d) => ({ ...d, deal_stage_id: undefined }));
            form.put(route('crm.deals.update', editing.id), options);
        } else {
            form.transform((d) => ({ ...d, pipeline_id: pipeline.id, deal_stage_id: d.deal_stage_id || null }));
            form.post(route('crm.deals.store'), options);
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
        next[to].splice(r.destination.index, 0, { ...moved, deal_stage_id: to });
        setBoard(next);

        router.post(route('crm.deals.move'), { deal_id: moved.id, deal_stage_id: to, ids: next[to].map((d) => d.id) }, { preserveScroll: true, onError: () => router.reload() });
    };

    const setStatus = (d: Deal, status: string) => router.post(route('crm.deals.status', d.id), { status }, { preserveScroll: true });
    const toggle = (key: 'user_ids' | 'client_ids', id: number, on: boolean) => form.setData(key, on ? [...form.data[key], id] : form.data[key].filter((x) => x !== id));
    const err = (k: string) => (form.errors as Record<string, string>)[k] && <p className="text-sm text-destructive">{(form.errors as Record<string, string>)[k]}</p>;

    const actions = (d: Deal) => (
        <span className="flex shrink-0" onClick={(e) => e.stopPropagation()}>
            {can.status && d.status === 'active' && (
                <>
                    <Button size="icon" variant="ghost" className="h-7 w-7" title={t('Won')} onClick={() => setStatus(d, 'won')}><ThumbsUp className="h-3.5 w-3.5 text-green-600" /></Button>
                    <Button size="icon" variant="ghost" className="h-7 w-7" title={t('Lost')} onClick={() => setStatus(d, 'lost')}><ThumbsDown className="h-3.5 w-3.5 text-destructive" /></Button>
                </>
            )}
            {can.status && d.status !== 'active' && <Button size="icon" variant="ghost" className="h-7 w-7" title={t('Reopen')} onClick={() => setStatus(d, 'active')}><RotateCcw className="h-3.5 w-3.5" /></Button>}
            {can.edit && <Button size="icon" variant="ghost" className="h-7 w-7" onClick={() => openEdit(d)}><Pencil className="h-3.5 w-3.5" /></Button>}
            {can.delete && <Button size="icon" variant="ghost" className="h-7 w-7" onClick={() => setDeleting(d)}><Trash2 className="h-3.5 w-3.5 text-destructive" /></Button>}
        </span>
    );

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Deals')}</h2>}>
            <Head title={t('Deals')} />

            <div className="space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={String(pipeline.id)} onValueChange={(v) => go({ pipeline: v, search: undefined, stage: undefined, status: undefined })}>
                            <SelectTrigger className="w-48"><SelectValue /></SelectTrigger>
                            <SelectContent>{pipelines.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                        </Select>
                        <div className="flex rounded-md border">
                            <Button size="icon" variant={view === 'kanban' ? 'default' : 'ghost'} title={t('Board')} onClick={() => go({ view: 'kanban', search: undefined, stage: undefined, status: undefined })}><LayoutGrid className="h-4 w-4" /></Button>
                            <Button size="icon" variant={view === 'list' ? 'default' : 'ghost'} title={t('List')} onClick={() => go({ view: 'list' })}><List className="h-4 w-4" /></Button>
                        </div>
                        {view === 'list' && (
                            <>
                                <form onSubmit={(e) => { e.preventDefault(); go({ search, stage: filters.stage, status: filters.status }); }}>
                                    <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search...')} className="w-48" />
                                </form>
                                <Select value={filters.stage ?? ALL} onValueChange={(v) => go({ stage: v === ALL ? undefined : v, search: filters.search, status: filters.status })}>
                                    <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
                                    <SelectContent><SelectItem value={ALL}>{t('All stages')}</SelectItem>{stages.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}</SelectContent>
                                </Select>
                                <Select value={filters.status ?? ALL} onValueChange={(v) => go({ status: v === ALL ? undefined : v, search: filters.search, stage: filters.stage })}>
                                    <SelectTrigger className="w-36"><SelectValue /></SelectTrigger>
                                    <SelectContent><SelectItem value={ALL}>{t('All statuses')}</SelectItem>{['active', 'won', 'lost'].map((s) => <SelectItem key={s} value={s}>{t(s)}</SelectItem>)}</SelectContent>
                                </Select>
                            </>
                        )}
                    </div>
                    {can.create && <Button onClick={() => openCreate()}><Plus className="mr-1 h-4 w-4" />{t('Add Deal')}</Button>}
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
                                                {(board[s.id] ?? []).map((d, i) => (
                                                    <Draggable key={d.id} draggableId={String(d.id)} index={i} isDragDisabled={!can.move || d.status !== 'active'}>
                                                        {(drag, dragSnap) => (
                                                            <div ref={drag.innerRef} {...drag.draggableProps} {...drag.dragHandleProps}>
                                                                <Card className={`cursor-pointer ${dragSnap.isDragging ? 'shadow-lg' : ''} ${d.is_active ? '' : 'opacity-60'}`} onClick={() => setDetailId(d.id)}>
                                                                    <CardContent className="space-y-1 p-3 text-sm">
                                                                        <div className="flex items-start justify-between gap-1"><div className="font-medium">{d.name}</div>{actions(d)}</div>
                                                                        <div className="font-semibold">{money(d.price)}</div>
                                                                        {d.clients.length > 0 && <div className="text-xs text-muted-foreground">{d.clients.map((c) => c.name).join(', ')}</div>}
                                                                        {d.users.length > 0 && <div className="flex flex-wrap gap-1 pt-1">{d.users.map((u) => <Badge key={u.id} variant="secondary" className="text-[10px]">{u.name}</Badge>)}</div>}
                                                                        {d.status !== 'active' && <Badge variant={tone[d.status]}>{t(d.status)}</Badge>}
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
                                            <TableHead>{t('Name')}</TableHead>
                                            <TableHead>{t('Price')}</TableHead>
                                            <TableHead>{t('Clients')}</TableHead>
                                            <TableHead>{t('Stage')}</TableHead>
                                            <TableHead>{t('Status')}</TableHead>
                                            <TableHead>{t('Assigned to')}</TableHead>
                                            <TableHead className="text-right">{t('Actions')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {list.length === 0 && <TableRow><TableCell colSpan={7} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell></TableRow>}
                                        {list.map((d) => (
                                            <TableRow key={d.id} className={d.is_active ? '' : 'opacity-60'}>
                                                <TableCell className="font-medium"><button type="button" className="text-left hover:underline" onClick={() => setDetailId(d.id)}>{d.name}</button></TableCell>
                                                <TableCell>{money(d.price)}</TableCell>
                                                <TableCell>{d.clients.map((c) => c.name).join(', ') || '—'}</TableCell>
                                                <TableCell><Badge variant="outline">{d.stage?.name}</Badge></TableCell>
                                                <TableCell><Badge variant={tone[d.status]}>{t(d.status)}</Badge></TableCell>
                                                <TableCell>{d.users.map((u) => u.name).join(', ') || '—'}</TableCell>
                                                <TableCell className="text-right">{actions(d)}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                        {!Array.isArray(deals) && <Pagination meta={deals} />}
                    </>
                )}
            </div>

            <LeadDrawer entity="deal" leadId={detailId} onClose={() => setDetailId(null)} sourceOptions={sourceOptions} labelOptions={labelOptions} productOptions={productOptions} can={{ edit: can.edit, delete: can.delete }} canDetail={can_detail} />

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogHeader><DialogTitle>{editing ? t('Edit Deal') : t('Add Deal')}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1"><Label>{t('Name')}</Label><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />{err('name')}</div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1"><Label>{t('Price')}</Label><Input type="number" step="0.01" min="0" value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} />{err('price')}</div>
                            <div className="space-y-1"><Label>{t('Phone')}</Label><Input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />{err('phone')}</div>
                        </div>
                        {!editing && (
                            <div className="space-y-1">
                                <Label>{t('Stage')}</Label>
                                <Select value={form.data.deal_stage_id || String(stages[0]?.id ?? '')} onValueChange={(v) => form.setData('deal_stage_id', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{stages.map((s) => <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>)}</SelectContent>
                                </Select>
                                {err('deal_stage_id')}
                            </div>
                        )}
                        <div className="space-y-1"><Label>{t('Notes')}</Label><Textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />{err('notes')}</div>
                        <div className="space-y-2">
                            <Label>{t('Clients')}</Label>
                            {clients.length === 0 && <p className="text-xs text-muted-foreground">{t('No clients yet. Add users of type client, or create one when converting a lead.')}</p>}
                            <div className="grid grid-cols-2 gap-2">{clients.map((c) => <label key={c.id} className="flex items-center gap-2 text-sm"><Checkbox checked={form.data.client_ids.includes(c.id)} onCheckedChange={(v) => toggle('client_ids', c.id, v === true)} />{c.name}</label>)}</div>
                            {err('client_ids.0')}
                        </div>
                        <div className="space-y-2">
                            <Label>{t('Assigned to')}</Label>
                            <div className="grid grid-cols-2 gap-2">{users.map((u) => <label key={u.id} className="flex items-center gap-2 text-sm"><Checkbox checked={form.data.user_ids.includes(u.id)} onCheckedChange={(v) => toggle('user_ids', u.id, v === true)} />{u.name}</label>)}</div>
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
                        <AlertDialogTitle>{t('Delete')} {deleting?.name}?</AlertDialogTitle>
                        <AlertDialogDescription>{t('The deal and its history are deleted for good. A lead it came from can be converted again.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('crm.deals.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
