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
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Check, Plus, Trash2, X } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Field {
    name: string;
    label: string;
    type: 'select' | 'date' | 'text' | 'textarea';
    required: boolean;
    options?: { value: string; label: string }[];
}

interface Row {
    id: number;
    cells: Record<string, string | null>;
    status: 'pending' | 'approved' | 'rejected' | 'applied';
    applied: boolean;
    comment: string | null;
}

interface Props {
    kind: string;
    title: string;
    workflow: boolean;
    columns: { key: string; label: string }[];
    fields: Field[];
    rows: Paginated<Row>;
    can: { create: boolean; approve: boolean; delete: boolean };
    filters: { status?: string };
}

const NONE = 'none';
const ALL = 'all';
const tone = { pending: 'secondary', approved: 'default', rejected: 'destructive', applied: 'default' } as const;

/** One page for promotions, resignations, terminations and transfers: the server describes the form and the table. */
export default function LifecycleIndex({ kind, title, workflow, columns, fields, rows, can, filters }: Props) {
    const { t } = useTranslation();
    const initial = () => Object.fromEntries(fields.map((f) => [f.name, f.type === 'select' && !f.required ? NONE : ''])) as Record<string, string>;
    const form = useForm<Record<string, string>>(initial());

    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<Row | null>(null);
    const [deciding, setDeciding] = useState<{ row: Row; action: 'approve' | 'reject' } | null>(null);
    const [comment, setComment] = useState('');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === NONE ? null : v])) as never);
        form.post(route('hrm.lifecycle.store', kind), { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    const decide = () => {
        if (!deciding) return;
        router.post(route(`hrm.lifecycle.${deciding.action}`, [kind, deciding.row.id]), { decision_comment: comment }, {
            preserveScroll: true,
            onFinish: () => {
                setDeciding(null);
                setComment('');
            },
        });
    };

    const input = (f: Field) => {
        if (f.type === 'select') {
            return (
                <Select value={form.data[f.name]} onValueChange={(v) => form.setData(f.name, v)}>
                    <SelectTrigger><SelectValue placeholder={t('Select')} /></SelectTrigger>
                    <SelectContent>
                        {!f.required && <SelectItem value={NONE}>{t('No change')}</SelectItem>}
                        {f.options?.map((o) => <SelectItem key={o.value} value={o.value}>{t(o.label)}</SelectItem>)}
                    </SelectContent>
                </Select>
            );
        }
        if (f.type === 'textarea') return <Textarea value={form.data[f.name]} onChange={(e) => form.setData(f.name, e.target.value)} />;
        return <Input type={f.type === 'date' ? 'date' : 'text'} value={form.data[f.name]} onChange={(e) => form.setData(f.name, e.target.value)} />;
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t(title)}</h2>}>
            <Head title={t(title)} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    {workflow ? (
                        <Select value={filters.status ?? ALL} onValueChange={(v) => router.get(route('hrm.lifecycle.index', kind), v === ALL ? {} : { status: v }, { preserveState: true, replace: true })}>
                            <SelectTrigger className="w-44"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>{t('All statuses')}</SelectItem>
                                {['pending', 'approved', 'rejected'].map((s) => <SelectItem key={s} value={s}>{t(s)}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    ) : <span />}
                    {can.create && <Button onClick={() => { form.setData(initial()); form.clearErrors(); setOpen(true); }}><Plus className="mr-1 h-4 w-4" />{workflow ? t('New Request') : t('Add')}</Button>}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {columns.map((c) => <TableHead key={c.key}>{t(c.label)}</TableHead>)}
                                    {workflow && <TableHead>{t('Status')}</TableHead>}
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.data.length === 0 && <TableRow><TableCell colSpan={columns.length + 2} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell></TableRow>}
                                {rows.data.map((r) => (
                                    <TableRow key={r.id}>
                                        {columns.map((c) => <TableCell key={c.key} className="max-w-64 truncate">{c.key === 'type' && r.cells[c.key] ? t(r.cells[c.key]!) : (r.cells[c.key] ?? '—')}</TableCell>)}
                                        {workflow && (
                                            <TableCell>
                                                <Badge variant={tone[r.status]}>{t(r.status)}</Badge>
                                                {r.status === 'approved' && !r.applied && <div className="text-xs text-muted-foreground">{t('Applies on the effective date')}</div>}
                                                {r.comment && <div className="text-xs text-muted-foreground">{r.comment}</div>}
                                            </TableCell>
                                        )}
                                        <TableCell className="text-right">
                                            {can.approve && r.status === 'pending' && (
                                                <>
                                                    <Button size="icon" variant="ghost" title={t('Approve')} onClick={() => setDeciding({ row: r, action: 'approve' })}><Check className="h-4 w-4 text-green-600" /></Button>
                                                    <Button size="icon" variant="ghost" title={t('Reject')} onClick={() => setDeciding({ row: r, action: 'reject' })}><X className="h-4 w-4 text-destructive" /></Button>
                                                </>
                                            )}
                                            {can.delete && r.status !== 'approved' && <Button size="icon" variant="ghost" onClick={() => setDeleting(r)}><Trash2 className="h-4 w-4 text-destructive" /></Button>}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={rows} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t(title)}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        {fields.map((f) => (
                            <div key={f.name} className="space-y-1">
                                <Label>{t(f.label)}</Label>
                                {input(f)}
                                {form.errors[f.name] && <p className="text-sm text-destructive">{form.errors[f.name]}</p>}
                            </div>
                        ))}
                        {!workflow && <p className="text-xs text-muted-foreground">{t('The change is applied to the employee immediately.')}</p>}
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{t('Submit')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={!!deciding} onOpenChange={(o) => !o && setDeciding(null)}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{deciding?.action === 'approve' ? t('Approve') : t('Reject')}</DialogTitle></DialogHeader>
                    <div className="space-y-1">
                        <Label>{t('Comment')}</Label>
                        <Textarea value={comment} onChange={(e) => setComment(e.target.value)} />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeciding(null)}>{t('Cancel')}</Button>
                        <Button variant={deciding?.action === 'reject' ? 'destructive' : 'default'} onClick={decide}>{deciding?.action === 'approve' ? t('Approve') : t('Reject')}</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete this request?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('This action cannot be undone.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('hrm.lifecycle.destroy', [kind, deleting.id]), { preserveScroll: true, onFinish: () => setDeleting(null) })}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
