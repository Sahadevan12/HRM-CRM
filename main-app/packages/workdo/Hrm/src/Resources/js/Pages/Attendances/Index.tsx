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
import { PageProps, Paginated } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Row {
    id: number;
    employee_id: number;
    date: string;
    in_time: string | null;
    out_time: string | null;
    total_hours: number;
    overtime_hours: number;
    late_minutes: number;
    status: string;
    source: string;
    notes: string | null;
    employee: { employee_code: string; user: { name: string } };
}

interface Props {
    records: Paginated<Row>;
    employees: { id: number; code: string; name: string; status: string }[];
    departments: { id: number; name: string }[];
    statuses: string[];
    filters: { from: string; to: string; employee?: string; department?: string; status?: string };
    tz: string;
}

const ALL = 'all';
const empty = { employee_id: '', date: '', clock_in: '', clock_out: '', status: 'present', notes: '' };

export default function AttendancesIndex({ records, employees, departments, statuses, filters, tz }: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user.permissions?.includes(p);

    const [f, setF] = useState({ from: filters.from, to: filters.to, employee: filters.employee ?? ALL, department: filters.department ?? ALL, status: filters.status ?? ALL });
    const [editing, setEditing] = useState<Row | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<Row | null>(null);
    const form = useForm(empty);

    const apply = () =>
        router.get(
            route('hrm.attendances.index'),
            Object.fromEntries(Object.entries(f).filter(([, v]) => v && v !== ALL)),
            { preserveState: true, replace: true },
        );

    const openCreate = () => {
        setEditing(null);
        form.setData({ ...empty, date: new Date().toISOString().slice(0, 10) });
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (r: Row) => {
        setEditing(r);
        form.setData({ employee_id: String(r.employee_id), date: r.date.slice(0, 10), clock_in: r.in_time ?? '', clock_out: r.out_time ?? '', status: r.status, notes: r.notes ?? '' });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('hrm.attendances.update', editing.id), options);
        else form.post(route('hrm.attendances.store'), options);
    };

    const err = (k: keyof typeof empty) => form.errors[k] && <p className="text-sm text-destructive">{form.errors[k]}</p>;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Attendance Records')}</h2>}>
            <Head title={t('Attendance Records')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="space-y-1"><Label>{t('From')}</Label><Input type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></div>
                        <div className="space-y-1"><Label>{t('To')}</Label><Input type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></div>
                        <Select value={f.employee} onValueChange={(v) => setF({ ...f, employee: v })}>
                            <SelectTrigger className="w-48"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>{t('All employees')}</SelectItem>
                                {employees.map((e) => <SelectItem key={e.id} value={String(e.id)}>{e.code} · {e.name}</SelectItem>)}
                            </SelectContent>
                        </Select>
                        <Select value={f.department} onValueChange={(v) => setF({ ...f, department: v })}>
                            <SelectTrigger className="w-44"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>{t('All departments')}</SelectItem>
                                {departments.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}
                            </SelectContent>
                        </Select>
                        <Select value={f.status} onValueChange={(v) => setF({ ...f, status: v })}>
                            <SelectTrigger className="w-36"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>{t('All statuses')}</SelectItem>
                                {statuses.map((s) => <SelectItem key={s} value={s}>{t(s)}</SelectItem>)}
                            </SelectContent>
                        </Select>
                        <Button variant="outline" onClick={apply}>{t('Filter')}</Button>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild><Link href={route('hrm.attendances.summary')}>{t('Monthly Summary')}</Link></Button>
                        {can('create-attendances') && <Button onClick={openCreate}><Plus className="mr-1 h-4 w-4" />{t('Add Attendance')}</Button>}
                    </div>
                </div>
                <p className="text-xs text-muted-foreground">{t('Times are shown in the company time zone')}: {tz}</p>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Date')}</TableHead>
                                    <TableHead>{t('Employee')}</TableHead>
                                    <TableHead>{t('In')}</TableHead>
                                    <TableHead>{t('Out')}</TableHead>
                                    <TableHead>{t('Hours')}</TableHead>
                                    <TableHead>{t('Overtime')}</TableHead>
                                    <TableHead>{t('Late (min)')}</TableHead>
                                    <TableHead>{t('Status')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {records.data.length === 0 && <TableRow><TableCell colSpan={9} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell></TableRow>}
                                {records.data.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell>{r.date.slice(0, 10)}</TableCell>
                                        <TableCell>{r.employee.employee_code} · {r.employee.user.name}</TableCell>
                                        <TableCell>{r.in_time ?? '-'}</TableCell>
                                        <TableCell>{r.out_time ?? '-'}</TableCell>
                                        <TableCell>{r.total_hours}</TableCell>
                                        <TableCell>{r.overtime_hours}</TableCell>
                                        <TableCell>{r.late_minutes}</TableCell>
                                        <TableCell><Badge variant={r.status === 'absent' ? 'destructive' : 'outline'}>{t(r.status)}</Badge></TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-attendances') && <Button size="icon" variant="ghost" onClick={() => openEdit(r)}><Pencil className="h-4 w-4" /></Button>}
                                            {can('delete-attendances') && <Button size="icon" variant="ghost" onClick={() => setDeleting(r)}><Trash2 className="h-4 w-4 text-destructive" /></Button>}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={records} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? t('Edit Attendance') : t('Add Attendance')}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1">
                            <Label>{t('Employee')}</Label>
                            <Select value={form.data.employee_id} onValueChange={(v) => form.setData('employee_id', v)} disabled={!!editing}>
                                <SelectTrigger><SelectValue placeholder={t('Select employee')} /></SelectTrigger>
                                <SelectContent>{employees.map((e) => <SelectItem key={e.id} value={String(e.id)}>{e.code} · {e.name}</SelectItem>)}</SelectContent>
                            </Select>
                            {err('employee_id')}
                        </div>
                        <div className="space-y-1"><Label>{t('Date')}</Label><Input type="date" value={form.data.date} onChange={(e) => form.setData('date', e.target.value)} />{err('date')}</div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1"><Label>{t('Clock In')}</Label><Input type="time" value={form.data.clock_in} onChange={(e) => form.setData('clock_in', e.target.value)} />{err('clock_in')}</div>
                            <div className="space-y-1"><Label>{t('Clock Out')}</Label><Input type="time" value={form.data.clock_out} onChange={(e) => form.setData('clock_out', e.target.value)} />{err('clock_out')}</div>
                        </div>
                        <p className="text-xs text-muted-foreground">{t('Leave the times empty and choose "absent" to mark an absent day. A clock out earlier than the clock in means the next day (night shift).')}</p>
                        <div className="space-y-1">
                            <Label>{t('Status')}</Label>
                            <Select value={form.data.status} onValueChange={(v) => form.setData('status', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{statuses.map((s) => <SelectItem key={s} value={s}>{t(s)}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1"><Label>{t('Notes')}</Label><Textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />{err('notes')}</div>
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
                        <AlertDialogTitle>{t('Delete Attendance?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('This action cannot be undone.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('hrm.attendances.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
