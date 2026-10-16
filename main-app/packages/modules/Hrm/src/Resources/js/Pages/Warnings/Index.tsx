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

interface WarningRow {
    id: number;
    employee_id: number;
    subject: string;
    severity: string;
    warning_date: string;
    description: string | null;
    employee?: { id: number; name: string } | null;
}

interface Props {
    warnings: Paginated<WarningRow>;
    filters: { search?: string };
    employeeOptions: { id: number; name: string }[];
}

const emptyForm = {
    employee_id: '',
    subject: '',
    severity: 'medium',
    warning_date: '',
    description: '',
};

export default function WarningsIndex({ warnings, filters, employeeOptions }: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user.permissions?.includes(permission);

    const [search, setSearch] = useState(filters.search ?? '');
    const [editing, setEditing] = useState<WarningRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<WarningRow | null>(null);
    const form = useForm(emptyForm);

    const openCreate = () => {
        setEditing(null);
        form.setData(emptyForm);
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (row: WarningRow) => {
        setEditing(row);
        form.setData({
            employee_id: row.employee_id ? String(row.employee_id) : '',
            subject: row.subject,
            severity: row.severity,
            warning_date: row.warning_date,
            description: row.description ?? '',
        });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, employee_id: data.employee_id === 'none' ? null : data.employee_id, })); // 'none' (no choice) is sent as null
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('hrm.warnings.update', editing.id), options);
        else form.post(route('hrm.warnings.store'), options);
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Warnings')}</h2>}>
            <Head title={t('Warnings')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(route('hrm.warnings.index'), { search }, { preserveState: true, replace: true });
                        }}
                        className="flex gap-2"
                    >
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search...')} className="w-64" />
                        <Button type="submit" variant="outline">{t('Search')}</Button>
                    </form>
                    {can('create-warnings') && (
                        <Button onClick={openCreate}>
                            <Plus className="mr-1 h-4 w-4" /> {t('Add Warning')}
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Employee')}</TableHead>
                                    <TableHead>{t('Subject')}</TableHead>
                                    <TableHead>{t('Severity')}</TableHead>
                                    <TableHead>{t('Warning Date')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {warnings.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                                            {t('No records found.')}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {warnings.data.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>{row.employee?.user?.name ?? '—'}</TableCell>
                                        <TableCell>{row.subject}</TableCell>
                                        <TableCell>{t(row.severity)}</TableCell>
                                        <TableCell>{row.warning_date}</TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-warnings') && (
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(row)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                            )}
                                            {can('delete-warnings') && (
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

                <Pagination meta={warnings} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editing ? t('Edit Warning') : t('Add Warning')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1">
                            <Label>{t('Employee')}</Label>
                            <Select value={form.data.employee_id} onValueChange={(v) => form.setData('employee_id', v)}>
                                <SelectTrigger><SelectValue placeholder={t('Choose')} /></SelectTrigger>
                                <SelectContent>
                                    {employeeOptions.map((o) => <SelectItem key={o.id} value={String(o.id)}>{o.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {form.errors.employee_id && <p className="text-sm text-destructive">{form.errors.employee_id}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="subject">{t('Subject')}</Label>
                            <Input id="subject" value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                            {form.errors.subject && <p className="text-sm text-destructive">{form.errors.subject}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="severity">{t('Severity')}</Label>
                            <Select value={form.data.severity} onValueChange={(v) => form.setData('severity', v)}>
                                <SelectTrigger id="severity"><SelectValue /></SelectTrigger>
                                <SelectContent><SelectItem value="low">{t('Low')}</SelectItem><SelectItem value="medium">{t('Medium')}</SelectItem><SelectItem value="high">{t('High')}</SelectItem></SelectContent>
                            </Select>
                            {form.errors.severity && <p className="text-sm text-destructive">{form.errors.severity}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="warning_date">{t('Warning Date')}</Label>
                            <Input id="warning_date" type="date" value={form.data.warning_date} onChange={(e) => form.setData('warning_date', e.target.value)} />
                            {form.errors.warning_date && <p className="text-sm text-destructive">{form.errors.warning_date}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="description">{t('Description')}</Label>
                            <Textarea id="description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                            {form.errors.description && <p className="text-sm text-destructive">{form.errors.description}</p>}
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
                        <AlertDialogTitle>{t('Delete Warning?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('This action cannot be undone.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() =>
                                deleting &&
                                router.delete(route('hrm.warnings.destroy', deleting.id), {
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
