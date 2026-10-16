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
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, Plus, Trash2, X } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Row {
    id: number;
    employee_id: number;
    start_date: string;
    end_date: string;
    total_days: number;
    reason: string | null;
    status: 'pending' | 'approved' | 'rejected';
    approver_comment: string | null;
    employee: { employee_code: string; user: { name: string } };
    type: { name: string; is_paid: boolean };
    approver: { name: string } | null;
}

interface Props {
    applications: Paginated<Row>;
    leaveTypes: { id: number; name: string; days_per_year: number; is_paid: boolean }[];
    employees: { id: number; code: string; name: string }[];
    myEmployeeId: number | null;
    can: { manage: boolean; approve: boolean; delete: boolean; apply: boolean };
    balances: { type_id: number; name: string; remaining: number | null }[];
    filters: { employee?: string; status?: string };
}

const ALL = 'all';
const empty = { employee_id: '', leave_type_id: '', start_date: '', end_date: '', reason: '' };
const tone: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = { pending: 'secondary', approved: 'default', rejected: 'destructive' };

export default function LeaveApplicationsIndex({ applications, leaveTypes, employees, myEmployeeId, can, balances, filters }: Props) {
    const { t } = useTranslation();

    const [status, setStatus] = useState(filters.status ?? ALL);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<Row | null>(null);
    const [deciding, setDeciding] = useState<{ row: Row; action: 'approve' | 'reject' } | null>(null);
    const [comment, setComment] = useState('');
    const form = useForm(empty);

    const filter = (s: string) => {
        setStatus(s);
        router.get(route('hrm.leave-applications.index'), s === ALL ? {} : { status: s }, { preserveState: true, replace: true });
    };

    const openCreate = () => {
        form.setData({ ...empty, employee_id: myEmployeeId ? String(myEmployeeId) : '' });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, employee_id: can.manage && d.employee_id ? d.employee_id : null }));
        form.post(route('hrm.leave-applications.store'), { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    const decide = () => {
        if (!deciding) return;
        router.post(route(`hrm.leave-applications.${deciding.action}`, deciding.row.id), { approver_comment: comment }, {
            preserveScroll: true,
            onFinish: () => {
                setDeciding(null);
                setComment('');
            },
        });
    };

    const err = (k: keyof typeof empty) => form.errors[k] && <p className="text-sm text-destructive">{form.errors[k]}</p>;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{can.manage ? t('Leave Applications') : t('My Leave')}</h2>}>
            <Head title={t('Leave Applications')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Select value={status} onValueChange={filter}>
                        <SelectTrigger className="w-44"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>{t('All statuses')}</SelectItem>
                            {['pending', 'approved', 'rejected'].map((s) => <SelectItem key={s} value={s}>{t(s)}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild><Link href={route('hrm.leave-applications.balance')}>{t('Leave Balance')}</Link></Button>
                        {can.apply && <Button onClick={openCreate}><Plus className="mr-1 h-4 w-4" />{t('Apply for Leave')}</Button>}
                    </div>
                </div>

                {balances.length > 0 && (
                    <div className="flex flex-wrap gap-2 text-sm text-muted-foreground">
                        {balances.filter((b) => b.remaining !== null).map((b) => <Badge key={b.type_id} variant="outline">{b.name}: {b.remaining} {t('left')}</Badge>)}
                    </div>
                )}

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Employee')}</TableHead>
                                    <TableHead>{t('Leave Type')}</TableHead>
                                    <TableHead>{t('From')}</TableHead>
                                    <TableHead>{t('To')}</TableHead>
                                    <TableHead>{t('Days')}</TableHead>
                                    <TableHead>{t('Reason')}</TableHead>
                                    <TableHead>{t('Status')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {applications.data.length === 0 && <TableRow><TableCell colSpan={8} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell></TableRow>}
                                {applications.data.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell>{r.employee.employee_code} · {r.employee.user.name}</TableCell>
                                        <TableCell>{r.type.name}</TableCell>
                                        <TableCell>{r.start_date.slice(0, 10)}</TableCell>
                                        <TableCell>{r.end_date.slice(0, 10)}</TableCell>
                                        <TableCell>{r.total_days}</TableCell>
                                        <TableCell className="max-w-48 truncate">{r.reason}</TableCell>
                                        <TableCell>
                                            <Badge variant={tone[r.status]}>{t(r.status)}</Badge>
                                            {r.approver && <div className="text-xs text-muted-foreground">{r.approver.name}</div>}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {can.approve && r.status === 'pending' && (
                                                <>
                                                    <Button size="icon" variant="ghost" title={t('Approve')} onClick={() => setDeciding({ row: r, action: 'approve' })}><Check className="h-4 w-4 text-green-600" /></Button>
                                                    <Button size="icon" variant="ghost" title={t('Reject')} onClick={() => setDeciding({ row: r, action: 'reject' })}><X className="h-4 w-4 text-destructive" /></Button>
                                                </>
                                            )}
                                            {(can.delete || (r.status === 'pending' && r.employee_id === myEmployeeId)) && (
                                                <Button size="icon" variant="ghost" onClick={() => setDeleting(r)}><Trash2 className="h-4 w-4 text-destructive" /></Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={applications} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('Apply for Leave')}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        {can.manage && (
                            <div className="space-y-1">
                                <Label>{t('Employee')}</Label>
                                <Select value={form.data.employee_id} onValueChange={(v) => form.setData('employee_id', v)}>
                                    <SelectTrigger><SelectValue placeholder={t('Select employee')} /></SelectTrigger>
                                    <SelectContent>{employees.map((e) => <SelectItem key={e.id} value={String(e.id)}>{e.code} · {e.name}</SelectItem>)}</SelectContent>
                                </Select>
                                {err('employee_id')}
                            </div>
                        )}
                        <div className="space-y-1">
                            <Label>{t('Leave Type')}</Label>
                            <Select value={form.data.leave_type_id} onValueChange={(v) => form.setData('leave_type_id', v)}>
                                <SelectTrigger><SelectValue placeholder={t('Select leave type')} /></SelectTrigger>
                                <SelectContent>{leaveTypes.map((l) => <SelectItem key={l.id} value={String(l.id)}>{l.name}{l.is_paid ? '' : ` (${t('unpaid')})`}</SelectItem>)}</SelectContent>
                            </Select>
                            {err('leave_type_id')}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1"><Label>{t('From')}</Label><Input type="date" value={form.data.start_date} onChange={(e) => form.setData('start_date', e.target.value)} />{err('start_date')}</div>
                            <div className="space-y-1"><Label>{t('To')}</Label><Input type="date" value={form.data.end_date} onChange={(e) => form.setData('end_date', e.target.value)} />{err('end_date')}</div>
                        </div>
                        <div className="space-y-1"><Label>{t('Reason')}</Label><Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />{err('reason')}</div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{t('Submit')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={!!deciding} onOpenChange={(o) => !o && setDeciding(null)}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{deciding?.action === 'approve' ? t('Approve Leave') : t('Reject Leave')}</DialogTitle></DialogHeader>
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
                        <AlertDialogTitle>{t('Delete Leave Application?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('This action cannot be undone.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('hrm.leave-applications.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
