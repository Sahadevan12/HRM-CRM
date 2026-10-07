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

interface SalaryComponentRow {
    id: number;
    name: string;
    type: string;
    calc: string;
    amount: number;
    description: string | null;

}

interface Props {
    salaryComponents: Paginated<SalaryComponentRow>;
    filters: { search?: string };

}

const emptyForm = {
    name: '',
    type: 'allowance',
    calc: 'fixed',
    amount: 0,
    description: '',
};

export default function SalaryComponentsIndex({ salaryComponents, filters }: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user.permissions?.includes(permission);

    const [search, setSearch] = useState(filters.search ?? '');
    const [editing, setEditing] = useState<SalaryComponentRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<SalaryComponentRow | null>(null);
    const form = useForm(emptyForm);

    const openCreate = () => {
        setEditing(null);
        form.setData(emptyForm);
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (row: SalaryComponentRow) => {
        setEditing(row);
        form.setData({
            name: row.name,
            type: row.type,
            calc: row.calc,
            amount: row.amount,
            description: row.description ?? '',
        });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data,  })); // 'none' (no choice) is sent as null
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('hrm.salary-components.update', editing.id), options);
        else form.post(route('hrm.salary-components.store'), options);
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Salary Components')}</h2>}>
            <Head title={t('Salary Components')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(route('hrm.salary-components.index'), { search }, { preserveState: true, replace: true });
                        }}
                        className="flex gap-2"
                    >
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search...')} className="w-64" />
                        <Button type="submit" variant="outline">{t('Search')}</Button>
                    </form>
                    {can('create-salary-components') && (
                        <Button onClick={openCreate}>
                            <Plus className="mr-1 h-4 w-4" /> {t('Add Salary Component')}
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Name')}</TableHead>
                                    <TableHead>{t('Type')}</TableHead>
                                    <TableHead>{t('Calculation')}</TableHead>
                                    <TableHead>{t('Amount')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {salaryComponents.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                                            {t('No records found.')}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {salaryComponents.data.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>{row.name}</TableCell>
                                        <TableCell>{t(row.type === 'allowance' ? 'Allowance' : 'Deduction')}</TableCell>
                                        <TableCell>{row.calc === 'percent' ? t('% of basic salary') : t('Fixed amount')}</TableCell>
                                        <TableCell>{row.amount}</TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-salary-components') && (
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(row)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                            )}
                                            {can('delete-salary-components') && (
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

                <Pagination meta={salaryComponents} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editing ? t('Edit Salary Component') : t('Add Salary Component')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1">
                            <Label htmlFor="name">{t('Name')}</Label>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            {form.errors.name && <p className="text-sm text-destructive">{form.errors.name}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="type">{t('Type')}</Label>
                            <Select value={form.data.type} onValueChange={(v) => form.setData('type', v)}>
                                <SelectTrigger id="type"><SelectValue /></SelectTrigger>
                                <SelectContent><SelectItem value="allowance">{t('Allowance')}</SelectItem><SelectItem value="deduction">{t('Deduction')}</SelectItem></SelectContent>
                            </Select>
                            {form.errors.type && <p className="text-sm text-destructive">{form.errors.type}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="calc">{t('Calculation')}</Label>
                            <Select value={form.data.calc} onValueChange={(v) => form.setData('calc', v)}>
                                <SelectTrigger id="calc"><SelectValue /></SelectTrigger>
                                <SelectContent><SelectItem value="fixed">{t('Fixed amount')}</SelectItem><SelectItem value="percent">{t('% of basic salary')}</SelectItem></SelectContent>
                            </Select>
                            {form.errors.calc && <p className="text-sm text-destructive">{form.errors.calc}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="amount">{t('Amount')}</Label>
                            <Input id="amount" type="number" step="0.01" value={form.data.amount} onChange={(e) => form.setData('amount', Number(e.target.value))} />
                            {form.errors.amount && <p className="text-sm text-destructive">{form.errors.amount}</p>}
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
                        <AlertDialogTitle>{t('Delete Salary Component?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('This action cannot be undone.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() =>
                                deleting &&
                                router.delete(route('hrm.salary-components.destroy', deleting.id), {
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
