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

interface AwardRow {
    id: number;
    employee_id: number;
    award_type_id: number;
    award_date: string;
    gift: string | null;
    description: string | null;
    employee?: { id: number; name: string } | null;
    awardType?: { id: number; name: string } | null;
}

interface Props {
    awards: Paginated<AwardRow>;
    filters: { search?: string };
    employeeOptions: { id: number; name: string }[];
    awardTypeOptions: { id: number; name: string }[];
}

const emptyForm = {
    employee_id: '',
    award_type_id: '',
    award_date: '',
    gift: '',
    description: '',
};

export default function AwardsIndex({ awards, filters, employeeOptions, awardTypeOptions }: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user.permissions?.includes(permission);

    const [search, setSearch] = useState(filters.search ?? '');
    const [editing, setEditing] = useState<AwardRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<AwardRow | null>(null);
    const form = useForm(emptyForm);

    const openCreate = () => {
        setEditing(null);
        form.setData(emptyForm);
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (row: AwardRow) => {
        setEditing(row);
        form.setData({
            employee_id: row.employee_id ? String(row.employee_id) : '',
            award_type_id: row.award_type_id ? String(row.award_type_id) : '',
            award_date: row.award_date,
            gift: row.gift ?? '',
            description: row.description ?? '',
        });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, employee_id: data.employee_id === 'none' ? null : data.employee_id, award_type_id: data.award_type_id === 'none' ? null : data.award_type_id, })); // 'none' (no choice) is sent as null
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('hrm.awards.update', editing.id), options);
        else form.post(route('hrm.awards.store'), options);
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Awards')}</h2>}>
            <Head title={t('Awards')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(route('hrm.awards.index'), { search }, { preserveState: true, replace: true });
                        }}
                        className="flex gap-2"
                    >
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search...')} className="w-64" />
                        <Button type="submit" variant="outline">{t('Search')}</Button>
                    </form>
                    {can('create-awards') && (
                        <Button onClick={openCreate}>
                            <Plus className="mr-1 h-4 w-4" /> {t('Add Award')}
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Employee')}</TableHead>
                                    <TableHead>{t('Award Type')}</TableHead>
                                    <TableHead>{t('Award Date')}</TableHead>
                                    <TableHead>{t('Gift')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {awards.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                                            {t('No records found.')}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {awards.data.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>{row.employee?.user?.name ?? '—'}</TableCell>
                                        <TableCell>{row.awardType?.name ?? '—'}</TableCell>
                                        <TableCell>{row.award_date}</TableCell>
                                        <TableCell>{row.gift}</TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-awards') && (
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(row)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                            )}
                                            {can('delete-awards') && (
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

                <Pagination meta={awards} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editing ? t('Edit Award') : t('Add Award')}</DialogTitle>
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
                            <Label>{t('Award Type')}</Label>
                            <Select value={form.data.award_type_id} onValueChange={(v) => form.setData('award_type_id', v)}>
                                <SelectTrigger><SelectValue placeholder={t('Choose')} /></SelectTrigger>
                                <SelectContent>
                                    {awardTypeOptions.map((o) => <SelectItem key={o.id} value={String(o.id)}>{o.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {form.errors.award_type_id && <p className="text-sm text-destructive">{form.errors.award_type_id}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="award_date">{t('Award Date')}</Label>
                            <Input id="award_date" type="date" value={form.data.award_date} onChange={(e) => form.setData('award_date', e.target.value)} />
                            {form.errors.award_date && <p className="text-sm text-destructive">{form.errors.award_date}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="gift">{t('Gift')}</Label>
                            <Input id="gift" value={form.data.gift} onChange={(e) => form.setData('gift', e.target.value)} />
                            {form.errors.gift && <p className="text-sm text-destructive">{form.errors.gift}</p>}
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
                        <AlertDialogTitle>{t('Delete Award?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('This action cannot be undone.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() =>
                                deleting &&
                                router.delete(route('hrm.awards.destroy', deleting.id), {
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
