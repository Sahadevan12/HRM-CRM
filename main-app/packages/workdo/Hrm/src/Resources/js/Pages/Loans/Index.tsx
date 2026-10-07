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
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Paginated } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Ban, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Row {
    id: number;
    title: string;
    amount: number;
    installment: number;
    repaid: number;
    start_month: string;
    status: 'active' | 'completed' | 'cancelled';
    employee: { employee_code: string; user: { name: string } };
}

interface Props {
    loans: Paginated<Row>;
    employees: { id: number; code: string; name: string }[];
    filters: { status?: string };
}

const empty = { employee_id: '', title: '', amount: '', installment: '', start_month: new Date().toISOString().slice(0, 7), notes: '' };
const ALL = 'all';

export default function LoansIndex({ loans, employees, filters }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user.permissions?.includes(p);

    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<Row | null>(null);
    const form = useForm(empty);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('hrm.loans.store'), { preserveScroll: true, onSuccess: () => { setOpen(false); form.reset(); } });
    };

    const err = (k: keyof typeof empty) => form.errors[k] && <p className="text-sm text-destructive">{form.errors[k]}</p>;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Loans')}</h2>}>
            <Head title={t('Loans')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Select value={filters.status ?? ALL} onValueChange={(v) => router.get(route('hrm.loans.index'), v === ALL ? {} : { status: v }, { preserveState: true, replace: true })}>
                        <SelectTrigger className="w-44"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>{t('All statuses')}</SelectItem>
                            {['active', 'completed', 'cancelled'].map((s) => <SelectItem key={s} value={s}>{t(s)}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    {can('create-loans') && <Button onClick={() => { form.reset(); form.clearErrors(); setOpen(true); }}><Plus className="mr-1 h-4 w-4" />{t('Add Loan')}</Button>}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Employee')}</TableHead>
                                    <TableHead>{t('Title')}</TableHead>
                                    <TableHead>{t('Amount')}</TableHead>
                                    <TableHead>{t('Instalment')}</TableHead>
                                    <TableHead>{t('Repaid')}</TableHead>
                                    <TableHead>{t('Starts')}</TableHead>
                                    <TableHead>{t('Status')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {loans.data.length === 0 && <TableRow><TableCell colSpan={8} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell></TableRow>}
                                {loans.data.map((l) => (
                                    <TableRow key={l.id}>
                                        <TableCell>{l.employee.employee_code} · {l.employee.user.name}</TableCell>
                                        <TableCell>{l.title}</TableCell>
                                        <TableCell>{money(l.amount)}</TableCell>
                                        <TableCell>{money(l.installment)}</TableCell>
                                        <TableCell>{money(l.repaid)}</TableCell>
                                        <TableCell>{l.start_month.slice(0, 7)}</TableCell>
                                        <TableCell><Badge variant="outline">{t(l.status)}</Badge></TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-loans') && l.status === 'active' && <Button size="icon" variant="ghost" title={t('Cancel loan')} onClick={() => router.post(route('hrm.loans.cancel', l.id), {}, { preserveScroll: true })}><Ban className="h-4 w-4" /></Button>}
                                            {can('delete-loans') && l.repaid === 0 && <Button size="icon" variant="ghost" onClick={() => setDeleting(l)}><Trash2 className="h-4 w-4 text-destructive" /></Button>}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={loans} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('Add Loan')}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1">
                            <Label>{t('Employee')}</Label>
                            <Select value={form.data.employee_id} onValueChange={(v) => form.setData('employee_id', v)}>
                                <SelectTrigger><SelectValue placeholder={t('Select employee')} /></SelectTrigger>
                                <SelectContent>{employees.map((e) => <SelectItem key={e.id} value={String(e.id)}>{e.code} · {e.name}</SelectItem>)}</SelectContent>
                            </Select>
                            {err('employee_id')}
                        </div>
                        <div className="space-y-1"><Label>{t('Title')}</Label><Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />{err('title')}</div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1"><Label>{t('Amount')}</Label><Input type="number" step="0.01" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />{err('amount')}</div>
                            <div className="space-y-1"><Label>{t('Monthly instalment')}</Label><Input type="number" step="0.01" value={form.data.installment} onChange={(e) => form.setData('installment', e.target.value)} />{err('installment')}</div>
                        </div>
                        <div className="space-y-1"><Label>{t('First deduction month')}</Label><Input type="month" value={form.data.start_month} onChange={(e) => form.setData('start_month', e.target.value)} />{err('start_month')}</div>
                        <div className="space-y-1"><Label>{t('Notes')}</Label><Textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />{err('notes')}</div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{t('Create')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete Loan?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('This action cannot be undone.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('hrm.loans.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
