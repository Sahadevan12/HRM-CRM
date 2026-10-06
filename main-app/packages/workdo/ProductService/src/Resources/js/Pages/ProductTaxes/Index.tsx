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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Paginated } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface ProductTaxRow {
    id: number;
    name: string;
    rate: number;
}

interface Props {
    productTaxes: Paginated<ProductTaxRow>;
    filters: { search?: string };
}

const emptyForm = {
    name: '',
    rate: 0,
};

export default function ProductTaxesIndex({ productTaxes, filters }: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user.permissions?.includes(permission);

    const [search, setSearch] = useState(filters.search ?? '');
    const [editing, setEditing] = useState<ProductTaxRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<ProductTaxRow | null>(null);
    const form = useForm(emptyForm);

    const openCreate = () => {
        setEditing(null);
        form.setData(emptyForm);
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (row: ProductTaxRow) => {
        setEditing(row);
        form.setData({
            name: row.name,
            rate: row.rate,
        });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('productservice.product-taxes.update', editing.id), options);
        else form.post(route('productservice.product-taxes.store'), options);
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Product Taxes')}</h2>}>
            <Head title={t('Product Taxes')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(route('productservice.product-taxes.index'), { search }, { preserveState: true, replace: true });
                        }}
                        className="flex gap-2"
                    >
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search...')} className="w-64" />
                        <Button type="submit" variant="outline">{t('Search')}</Button>
                    </form>
                    {can('create-product-taxes') && (
                        <Button onClick={openCreate}>
                            <Plus className="mr-1 h-4 w-4" /> {t('Add Product Tax')}
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Name')}</TableHead>
                                    <TableHead>{t('Rate')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {productTaxes.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={3} className="py-8 text-center text-muted-foreground">
                                            {t('No records found.')}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {productTaxes.data.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>{row.name}</TableCell>
                                        <TableCell>{row.rate}</TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-product-taxes') && (
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(row)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                            )}
                                            {can('delete-product-taxes') && (
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

                <Pagination meta={productTaxes} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editing ? t('Edit Product Tax') : t('Add Product Tax')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1">
                            <Label htmlFor="name">{t('Name')}</Label>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            {form.errors.name && <p className="text-sm text-destructive">{form.errors.name}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="rate">{t('Rate')}</Label>
                            <Input id="rate" type="number" step="0.01" value={form.data.rate} onChange={(e) => form.setData('rate', Number(e.target.value))} />
                            {form.errors.rate && <p className="text-sm text-destructive">{form.errors.rate}</p>}
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
                        <AlertDialogTitle>{t('Delete Product Tax?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('This action cannot be undone.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() =>
                                deleting &&
                                router.delete(route('productservice.product-taxes.destroy', deleting.id), {
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
