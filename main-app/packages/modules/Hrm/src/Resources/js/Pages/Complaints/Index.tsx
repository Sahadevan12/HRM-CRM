import Pagination from '@/Components/Pagination';
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
import { PageProps, Paginated } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface ComplaintRow {
    id: number;
    from_employee_id: number;
    against_employee_id: number | null;
    subject: string;
    complaint_date: string;
    description: string;
    status: string;
    resolution: string | null;
    fromEmployee?: { id: number; name: string } | null;
    againstEmployee?: { id: number; name: string } | null;
}

interface Props {
    complaints: Paginated<ComplaintRow>;
    filters: { search?: string };
    employeeOptions: { id: number; name: string }[];
}

const emptyForm = {
    from_employee_id: '',
    against_employee_id: 'none',
    subject: '',
    complaint_date: '',
    description: '',
    status: 'open',
    resolution: '',
};

export default function ComplaintsIndex({ complaints, filters, employeeOptions }: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user.permissions?.includes(permission);

    const [search, setSearch] = useState(filters.search ?? '');
    const [editing, setEditing] = useState<ComplaintRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<ComplaintRow | null>(null);
    const form = useForm(emptyForm);

    const openCreate = () => {
        setEditing(null);
        form.setData(emptyForm);
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (row: ComplaintRow) => {
        setEditing(row);
        form.setData({
            from_employee_id: row.from_employee_id ? String(row.from_employee_id) : '',
            against_employee_id: row.against_employee_id ? String(row.against_employee_id) : 'none',
            subject: row.subject,
            complaint_date: row.complaint_date,
            description: row.description,
            status: row.status,
            resolution: row.resolution ?? '',
        });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, from_employee_id: data.from_employee_id === 'none' ? null : data.from_employee_id, against_employee_id: data.against_employee_id === 'none' ? null : data.against_employee_id, })); // 'none' (no choice) is sent as null
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('hrm.complaints.update', editing.id), options);
        else form.post(route('hrm.complaints.store'), options);
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Complaints')}</h2>}>
            <Head title={t('Complaints')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(route('hrm.complaints.index'), { search }, { preserveState: true, replace: true });
                        }}
                        className="flex gap-2"
                    >
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search...')} className="w-64" />
                        <Button type="submit" variant="outline">{t('Search')}</Button>
                    </form>
                    {can('create-complaints') && (
                        <Button onClick={openCreate}>
                            <Plus className="mr-1 h-4 w-4" /> {t('Add Complaint')}
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('From Employee')}</TableHead>
                                    <TableHead>{t('Against Employee')}</TableHead>
                                    <TableHead>{t('Subject')}</TableHead>
                                    <TableHead>{t('Complaint Date')}</TableHead>
                                    <TableHead>{t('Status')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {complaints.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                                            {t('No records found.')}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {complaints.data.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>{row.fromEmployee?.user?.name ?? '—'}</TableCell>
                                        <TableCell>{row.againstEmployee?.user?.name ?? '—'}</TableCell>
                                        <TableCell>{row.subject}</TableCell>
                                        <TableCell>{row.complaint_date}</TableCell>
                                        <TableCell>{t(row.status)}</TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-complaints') && (
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(row)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                            )}
                                            {can('delete-complaints') && (
                                                <Button size="icon" variant="ghost" onClick={() => setDeleting(row)}>
                                                    <Trash2 className="h-4 w-4 text-destructive" />
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={complaints} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editing ? t('Edit Complaint') : t('Add Complaint')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1">
                            <Label>{t('From Employee')}</Label>
                            <Select value={form.data.from_employee_id} onValueChange={(v) => form.setData('from_employee_id', v)}>
                                <SelectTrigger><SelectValue placeholder={t('Choose')} /></SelectTrigger>
                                <SelectContent>
                                    {employeeOptions.map((o) => <SelectItem key={o.id} value={String(o.id)}>{o.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {form.errors.from_employee_id && <p className="text-sm text-destructive">{form.errors.from_employee_id}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label>{t('Against Employee')}</Label>
                            <Select value={form.data.against_employee_id} onValueChange={(v) => form.setData('against_employee_id', v)}>
                                <SelectTrigger><SelectValue placeholder={t('Choose')} /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">{t('None')}</SelectItem>
                                    {employeeOptions.map((o) => <SelectItem key={o.id} value={String(o.id)}>{o.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {form.errors.against_employee_id && <p className="text-sm text-destructive">{form.errors.against_employee_id}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="subject">{t('Subject')}</Label>
                            <Input id="subject" value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                            {form.errors.subject && <p className="text-sm text-destructive">{form.errors.subject}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="complaint_date">{t('Complaint Date')}</Label>
                            <Input id="complaint_date" type="date" value={form.data.complaint_date} onChange={(e) => form.setData('complaint_date', e.target.value)} />
                            {form.errors.complaint_date && <p className="text-sm text-destructive">{form.errors.complaint_date}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="description">{t('Description')}</Label>
                            <Textarea id="description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                            {form.errors.description && <p className="text-sm text-destructive">{form.errors.description}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="status">{t('Status')}</Label>
                            <Select value={form.data.status} onValueChange={(v) => form.setData('status', v)}>
                                <SelectTrigger id="status"><SelectValue /></SelectTrigger>
                                <SelectContent><SelectItem value="open">{t('Open')}</SelectItem><SelectItem value="resolved">{t('Resolved')}</SelectItem></SelectContent>
                            </Select>
                            {form.errors.status && <p className="text-sm text-destructive">{form.errors.status}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="resolution">{t('Resolution')}</Label>
                            <Textarea id="resolution" value={form.data.resolution} onChange={(e) => form.setData('resolution', e.target.value)} />
                            {form.errors.resolution && <p className="text-sm text-destructive">{form.errors.resolution}</p>}
                        </div>
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
                        <AlertDialogTitle>{t('Delete Complaint?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('This action cannot be undone.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() =>
                                deleting &&
                                router.delete(route('hrm.complaints.destroy', deleting.id), {
                                    preserveScroll: true,
                                    onFinish: () => setDeleting(null),
                                })
                            }
                        >
                            {t('Delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
