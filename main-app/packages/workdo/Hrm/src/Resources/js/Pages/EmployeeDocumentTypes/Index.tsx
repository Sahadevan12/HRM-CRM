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

interface EmployeeDocumentTypeRow {
    id: number;
    name: string;
    is_required: boolean;

}

interface Props {
    employeeDocumentTypes: Paginated<EmployeeDocumentTypeRow>;
    filters: { search?: string };

}

const emptyForm = {
    name: '',
    is_required: false,
};

export default function EmployeeDocumentTypesIndex({ employeeDocumentTypes, filters }: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user.permissions?.includes(permission);

    const [search, setSearch] = useState(filters.search ?? '');
    const [editing, setEditing] = useState<EmployeeDocumentTypeRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<EmployeeDocumentTypeRow | null>(null);
    const form = useForm(emptyForm);

    const openCreate = () => {
        setEditing(null);
        form.setData(emptyForm);
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (row: EmployeeDocumentTypeRow) => {
        setEditing(row);
        form.setData({
            name: row.name,
            is_required: row.is_required,
        });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data,  })); // 'none' (no choice) is sent as null
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('hrm.employee-document-types.update', editing.id), options);
        else form.post(route('hrm.employee-document-types.store'), options);
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Employee Document Types')}</h2>}>
            <Head title={t('Employee Document Types')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(route('hrm.employee-document-types.index'), { search }, { preserveState: true, replace: true });
                        }}
                        className="flex gap-2"
                    >
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search...')} className="w-64" />
                        <Button type="submit" variant="outline">{t('Search')}</Button>
                    </form>
                    {can('create-employee-document-types') && (
                        <Button onClick={openCreate}>
                            <Plus className="mr-1 h-4 w-4" /> {t('Add Employee Document Type')}
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Name')}</TableHead>
                                    <TableHead>{t('Is Required')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {employeeDocumentTypes.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={3} className="py-8 text-center text-muted-foreground">
                                            {t('No records found.')}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {employeeDocumentTypes.data.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>{row.name}</TableCell>
                                        <TableCell>{row.is_required ? t('Yes') : t('No')}</TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-employee-document-types') && (
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(row)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                            )}
                                            {can('delete-employee-document-types') && (
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

                <Pagination meta={employeeDocumentTypes} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editing ? t('Edit Employee Document Type') : t('Add Employee Document Type')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1">
                            <Label htmlFor="name">{t('Name')}</Label>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            {form.errors.name && <p className="text-sm text-destructive">{form.errors.name}</p>}
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={form.data.is_required} onCheckedChange={(c) => form.setData('is_required', c === true)} /> {t('Is Required')}
                        </label>
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
                        <AlertDialogTitle>{t('Delete Employee Document Type?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('This action cannot be undone.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() =>
                                deleting &&
                                router.delete(route('hrm.employee-document-types.destroy', deleting.id), {
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
