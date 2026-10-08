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
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { DragDropContext, Draggable, Droppable, DropResult } from '@hello-pangea/dnd';
import { Head, router, useForm } from '@inertiajs/react';
import { GripVertical, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Named { id: number; name: string }
interface Stage extends Named { pipeline_id: number; order: number }
interface LabelRow extends Named { color: string; pipeline_id: number }
type Kind = 'pipelines' | 'lead-stages' | 'deal-stages' | 'labels' | 'sources';
type Perm = { create: boolean; edit: boolean; delete: boolean };

interface Props {
    pipelines: Named[];
    leadStages: Stage[];
    dealStages: Stage[];
    labels: LabelRow[];
    sources: Named[];
    tabs: Kind[];
    can: Record<Kind, Perm>;
    automation: { draftProposalOnWin: boolean; proposalAvailable: boolean; webToLeadUrl: string | null; canEdit: boolean };
}

const TITLES: Record<Kind, string> = { pipelines: 'Pipelines', 'lead-stages': 'Lead Stages', 'deal-stages': 'Deal Stages', labels: 'Labels', sources: 'Sources' };
const SINGULAR: Record<Kind, string> = { pipelines: 'Pipeline', 'lead-stages': 'Lead Stage', 'deal-stages': 'Deal Stage', labels: 'Label', sources: 'Source' };

export default function SystemSetup({ pipelines, leadStages, dealStages, labels, sources, tabs, can, automation }: Props) {
    const { t } = useTranslation();
    const [kind, setKind] = useState<Kind>(tabs[0]);
    const [pipelineId, setPipelineId] = useState<number>(pipelines[0]?.id ?? 0);
    const [editing, setEditing] = useState<{ id: number; name: string; color?: string } | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<Named | null>(null);
    const form = useForm({ name: '', color: '#64748b', pipeline_id: 0 });

    const needsPipeline = kind !== 'pipelines' && kind !== 'sources';
    const stageSource = kind === 'lead-stages' ? leadStages : dealStages;
    const [order, setOrder] = useState<Stage[]>([]);

    // the server list is the truth: the local copy only exists to show the new order while the request runs
    useEffect(() => {
        setOrder(stageSource.filter((s) => s.pipeline_id === pipelineId));
    }, [stageSource, pipelineId, kind]);

    const rows: Named[] = kind === 'pipelines' ? pipelines : kind === 'sources' ? sources : kind === 'labels' ? labels.filter((l) => l.pipeline_id === pipelineId) : order;
    const perm = can[kind];

    const openCreate = () => {
        setEditing(null);
        form.setData({ name: '', color: '#64748b', pipeline_id: pipelineId });
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (r: Named & { color?: string }) => {
        setEditing(r);
        form.setData({ name: r.name, color: r.color ?? '#64748b', pipeline_id: pipelineId });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('crm.setup.update', [kind, editing.id]), options);
        else form.post(route('crm.setup.store', kind), options);
    };

    const onDragEnd = (r: DropResult) => {
        if (!r.destination || r.destination.index === r.source.index) return;
        const next = [...order];
        const [moved] = next.splice(r.source.index, 1);
        next.splice(r.destination.index, 0, moved);
        setOrder(next);
        router.post(route('crm.setup.reorder', kind), { pipeline_id: pipelineId, ids: next.map((s) => s.id) }, { preserveScroll: true, onError: () => setOrder(stageSource.filter((s) => s.pipeline_id === pipelineId)) });
    };

    const isStage = kind === 'lead-stages' || kind === 'deal-stages';
    const snippet = automation.webToLeadUrl
        ? `<form id="lead-form">\n  <input name="name" placeholder="Name" required>\n  <input name="email" type="email" placeholder="Email">\n  <input name="phone" placeholder="Phone">\n  <textarea name="message" placeholder="Message"></textarea>\n  <input name="website_url" style="display:none" tabindex="-1" autocomplete="off">\n  <button>Send</button>\n</form>\n<script>\ndocument.getElementById('lead-form').addEventListener('submit', async (e) => {\n  e.preventDefault();\n  const r = await fetch('${automation.webToLeadUrl}', { method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(e.target) });\n  alert(r.ok ? 'Thank you!' : 'Please check the form.');\n});\n</script>`
        : '';

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('CRM Setup')}</h2>}>
            <Head title={t('CRM Setup')} />

            <div className="mx-auto max-w-4xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <Card>
                    <CardContent className="space-y-4 p-4 text-sm">
                        <div className="font-semibold">{t('Automation')}</div>
                        <label className="flex items-start gap-2">
                            <Checkbox
                                checked={automation.draftProposalOnWin}
                                disabled={!automation.canEdit || !automation.proposalAvailable}
                                onCheckedChange={(v) => router.put(route('crm.setup.automation.update'), { draft_proposal_on_win: v === true }, { preserveScroll: true })}
                            />
                            <span>
                                {t('When a deal is won, draft a sales proposal for its client')}
                                <span className="block text-xs text-muted-foreground">
                                    {automation.proposalAvailable ? t('Needs a client and products on the deal. Only a draft is created, nothing is sent.') : t('Needs the Sales module.')}
                                </span>
                            </span>
                        </label>

                        <div className="space-y-2 border-t pt-3">
                            <div className="font-medium">{t('Website form (web to lead)')}</div>
                            {automation.webToLeadUrl ? (
                                <>
                                    <p className="text-xs text-muted-foreground">{t('Post a form to this secret address and a lead is created in your first pipeline.')}</p>
                                    <Input readOnly value={automation.webToLeadUrl} onFocus={(e) => e.target.select()} />
                                    <textarea readOnly rows={8} value={snippet} onFocus={(e) => e.target.select()} className="w-full rounded-md border bg-muted/30 p-2 font-mono text-xs" />
                                </>
                            ) : (
                                <p className="text-xs text-muted-foreground">{t('The form is switched off.')}</p>
                            )}
                            {automation.canEdit && (
                                <div className="flex gap-2">
                                    <Button size="sm" variant="outline" onClick={() => router.post(route('crm.setup.automation.token'), {}, { preserveScroll: true })}>{automation.webToLeadUrl ? t('Renew address') : t('Switch on')}</Button>
                                    {automation.webToLeadUrl && <Button size="sm" variant="outline" onClick={() => router.delete(route('crm.setup.automation.token.disable'), { preserveScroll: true })}>{t('Switch off')}</Button>}
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <div className="flex flex-wrap gap-2">
                    {tabs.map((k) => <Button key={k} variant={k === kind ? 'default' : 'outline'} size="sm" onClick={() => setKind(k)}>{t(TITLES[k])}</Button>)}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    {needsPipeline ? (
                        <Select value={String(pipelineId)} onValueChange={(v) => setPipelineId(Number(v))}>
                            <SelectTrigger className="w-56"><SelectValue /></SelectTrigger>
                            <SelectContent>{pipelines.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                        </Select>
                    ) : <span />}
                    {perm.create && <Button onClick={openCreate}><Plus className="mr-1 h-4 w-4" />{t('Add')} {t(SINGULAR[kind])}</Button>}
                </div>
                {isStage && perm.edit && <p className="text-xs text-muted-foreground">{t('Drag the handle to change the order.')}</p>}

                <Card>
                    <CardContent className="p-0">
                        {rows.length === 0 && <p className="py-8 text-center text-sm text-muted-foreground">{t('No records found.')}</p>}
                        {isStage ? (
                            <DragDropContext onDragEnd={onDragEnd}>
                                <Droppable droppableId={`${kind}-${pipelineId}`}>
                                    {(drop) => (
                                        <div ref={drop.innerRef} {...drop.droppableProps}>
                                            {order.map((s, i) => (
                                                <Draggable key={s.id} draggableId={String(s.id)} index={i} isDragDisabled={!perm.edit}>
                                                    {(drag, snap) => (
                                                        <div ref={drag.innerRef} {...drag.draggableProps} className={`flex items-center gap-3 border-b bg-background px-4 py-3 ${snap.isDragging ? 'shadow-lg' : ''}`}>
                                                            <span {...drag.dragHandleProps} className={perm.edit ? 'cursor-grab text-muted-foreground' : 'hidden'}><GripVertical className="h-4 w-4" /></span>
                                                            <span className="w-6 text-sm text-muted-foreground">{i + 1}</span>
                                                            <span className="flex-1">{s.name}</span>
                                                            {perm.edit && <Button size="icon" variant="ghost" onClick={() => openEdit(s)}><Pencil className="h-4 w-4" /></Button>}
                                                            {perm.delete && <Button size="icon" variant="ghost" onClick={() => setDeleting(s)}><Trash2 className="h-4 w-4 text-destructive" /></Button>}
                                                        </div>
                                                    )}
                                                </Draggable>
                                            ))}
                                            {drop.placeholder}
                                        </div>
                                    )}
                                </Droppable>
                            </DragDropContext>
                        ) : (
                            rows.map((r) => (
                                <div key={r.id} className="flex items-center gap-3 border-b px-4 py-3">
                                    {kind === 'labels' && <span className="h-4 w-4 rounded-full" style={{ backgroundColor: (r as LabelRow).color }} />}
                                    <span className="flex-1">{r.name}</span>
                                    {perm.edit && <Button size="icon" variant="ghost" onClick={() => openEdit(r as LabelRow)}><Pencil className="h-4 w-4" /></Button>}
                                    {perm.delete && <Button size="icon" variant="ghost" onClick={() => setDeleting(r)}><Trash2 className="h-4 w-4 text-destructive" /></Button>}
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? t('Edit') : t('Add')} {t(SINGULAR[kind])}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1">
                            <Label>{t('Name')}</Label>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            {form.errors.name && <p className="text-sm text-destructive">{form.errors.name}</p>}
                        </div>
                        {kind === 'labels' && (
                            <div className="space-y-1">
                                <Label>{t('Color')}</Label>
                                <Input type="color" className="h-10 w-20 p-1" value={form.data.color} onChange={(e) => form.setData('color', e.target.value)} />
                                {form.errors.color && <p className="text-sm text-destructive">{form.errors.color}</p>}
                            </div>
                        )}
                        {form.errors.pipeline_id && <p className="text-sm text-destructive">{form.errors.pipeline_id}</p>}
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
                        <AlertDialogDescription>{kind === 'pipelines' ? t('Its stages and labels are deleted with it.') : t('This action cannot be undone.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('crm.setup.destroy', [kind, deleting.id]), { preserveScroll: true, onFinish: () => setDeleting(null) })}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
