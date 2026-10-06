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
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Paginated } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface TransferRow {
    id: number;
    quantity: number;
    transfer_date: string;
    notes: string | null;
    product: { id: number; name: string; sku: string };
    from_warehouse: { id: number; name: string };
    to_warehouse: { id: number; name: string };
}

interface Props {
    transfers: Paginated<TransferRow>;
    products: { id: number; name: string; sku: string }[];
    warehouses: { id: number; name: string }[];
    filters: { search?: string };
}

const today = () => new Date().toISOString().slice(0, 10);

export default function StockTransfersIndex({ transfers, products, warehouses, filters }: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user.permissions?.includes(permission);

    const [search, setSearch] = useState(filters.search ?? '');
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<TransferRow | null>(null);

    const form = useForm({ product_id: '', from_warehouse_id: '', to_warehouse_id: '', quantity: 1, transfer_date: today(), notes: '' });

    const openCreate = () => {
        form.reset();
        form.setData('transfer_date', today());
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('productservice.stock-transfers.store'), { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Stock Transfers')}</h2>}>
            <Head title={t('Stock Transfers')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(route('productservice.stock-transfers.index'), { search }, { preserveState: true, replace: true });
                        }}
                        className="flex gap-2"
                    >
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search product...')} className="w-64" />
                        <Button type="submit" variant="outline">{t('Search')}</Button>
                    </form>
                    {can('create-transfers') && (
                        <Button onClick={openCreate}><Plus className="mr-1 h-4 w-4" /> {t('New Transfer')}</Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Date')}</TableHead>
                                    <TableHead>{t('Product')}</TableHead>
                                    <TableHead>{t('Quantity')}</TableHead>
                                    <TableHead>{t('Warehouses')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {transfers.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell>
                                    </TableRow>
                                )}
                                {transfers.data.map((tr) => (
                                    <TableRow key={tr.id}>
                                        <TableCell>{tr.transfer_date}</TableCell>
                                        <TableCell>
                                            <div className="font-medium">{tr.product.name}</div>
                                            <div className="text-xs text-muted-foreground">{tr.product.sku}</div>
                                        </TableCell>
                                        <TableCell>{tr.quantity}</TableCell>
                                        <TableCell>
                                            <span className="inline-flex items-center gap-2">
                                                {tr.from_warehouse.name} <ArrowRight className="h-4 w-4 text-muted-foreground" /> {tr.to_warehouse.name}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {can('delete-transfers') && (
                                                <Button size="icon" variant="ghost" title={t('Reverse and delete')} onClick={() => setDeleting(tr)}>
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

                <Pagination meta={transfers} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('New Transfer')}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1">
                            <Label>{t('Product')}</Label>
                            <Select value={form.data.product_id} onValueChange={(v) => form.setData('product_id', v)}>
                                <SelectTrigger><SelectValue placeholder={t('Choose a product')} /></SelectTrigger>
                                <SelectContent>
                                    {products.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name} ({p.sku})</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {form.errors.product_id && <p className="text-sm text-destructive">{form.errors.product_id}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1">
                                <Label>{t('From warehouse')}</Label>
                                <Select value={form.data.from_warehouse_id} onValueChange={(v) => form.setData('from_warehouse_id', v)}>
                                    <SelectTrigger><SelectValue placeholder={t('Choose')} /></SelectTrigger>
                                    <SelectContent>
                                        {warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                                {form.errors.from_warehouse_id && <p className="text-sm text-destructive">{form.errors.from_warehouse_id}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label>{t('To warehouse')}</Label>
                                <Select value={form.data.to_warehouse_id} onValueChange={(v) => form.setData('to_warehouse_id', v)}>
                                    <SelectTrigger><SelectValue placeholder={t('Choose')} /></SelectTrigger>
                                    <SelectContent>
                                        {warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                                {form.errors.to_warehouse_id && <p className="text-sm text-destructive">{form.errors.to_warehouse_id}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="quantity">{t('Quantity')}</Label>
                                <Input id="quantity" type="number" min={0} step="0.01" value={form.data.quantity} onChange={(e) => form.setData('quantity', Number(e.target.value))} />
                                {form.errors.quantity && <p className="text-sm text-destructive">{form.errors.quantity}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="transfer_date">{t('Date')}</Label>
                                <Input id="transfer_date" type="date" value={form.data.transfer_date} onChange={(e) => form.setData('transfer_date', e.target.value)} />
                                {form.errors.transfer_date && <p className="text-sm text-destructive">{form.errors.transfer_date}</p>}
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="notes">{t('Notes')}</Label>
                            <Textarea id="notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{t('Transfer')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Reverse this transfer?')}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {deleting && `${deleting.quantity} × ${deleting.product.name}: ${deleting.to_warehouse.name} → ${deleting.from_warehouse.name}`}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('productservice.stock-transfers.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>
                            {t('Reverse')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
