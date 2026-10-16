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
import axios from 'axios';
import { Boxes, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface ProductRow {
    id: number;
    name: string;
    sku: string;
    type: 'product' | 'service';
    sale_price: number;
    purchase_price: number;
    description: string | null;
    is_active: boolean;
    category_id: number | null;
    unit_id: number | null;
    tax_ids: number[] | null;
    total_stock: number | null;
    category?: { id: number; name: string } | null;
    unit?: { id: number; name: string } | null;
}

interface Option {
    id: number;
    name: string;
}

interface Props {
    products: Paginated<ProductRow>;
    categories: Option[];
    units: Option[];
    taxes: (Option & { rate: number })[];
    warehouses: Option[];
    filters: { search?: string; type?: string; category?: string };
}

interface StockLine {
    warehouse_id: number;
    name: string;
    quantity: number;
}

const NONE = 'none';

const emptyForm = {
    name: '',
    sku: '',
    type: 'product',
    sale_price: 0,
    purchase_price: 0,
    description: '',
    is_active: true,
    category_id: NONE,
    unit_id: NONE,
    tax_ids: [] as number[],
};

export default function ProductsIndex({ products, categories, units, taxes, filters }: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user.permissions?.includes(permission);

    const [search, setSearch] = useState(filters.search ?? '');
    const [editing, setEditing] = useState<ProductRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<ProductRow | null>(null);
    const [stockFor, setStockFor] = useState<ProductRow | null>(null);
    const [stockLines, setStockLines] = useState<StockLine[]>([]);
    const form = useForm(emptyForm);

    const openCreate = () => {
        setEditing(null);
        form.setData(emptyForm);
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (p: ProductRow) => {
        setEditing(p);
        form.setData({
            name: p.name,
            sku: p.sku,
            type: p.type,
            sale_price: p.sale_price,
            purchase_price: p.purchase_price,
            description: p.description ?? '',
            is_active: p.is_active,
            category_id: p.category_id ? String(p.category_id) : NONE,
            unit_id: p.unit_id ? String(p.unit_id) : NONE,
            tax_ids: p.tax_ids ?? [],
        });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // the select uses a sentinel for "none" because Radix items cannot have an empty value
        form.transform((data) => ({
            ...data,
            category_id: data.category_id === NONE ? null : data.category_id,
            unit_id: data.unit_id === NONE ? null : data.unit_id,
        }));
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('productservice.products.update', editing.id), options);
        else form.post(route('productservice.products.store'), options);
    };

    const toggleTax = (id: number, checked: boolean) =>
        form.setData('tax_ids', checked ? [...form.data.tax_ids, id] : form.data.tax_ids.filter((x) => x !== id));

    const openStock = async (p: ProductRow) => {
        setStockFor(p);
        setStockLines([]);
        const { data } = await axios.get<StockLine[]>(route('productservice.products.stock', p.id));
        setStockLines(data);
    };

    const saveStock = (line: StockLine) =>
        stockFor &&
        router.post(
            route('productservice.products.update-stock', stockFor.id),
            { warehouse_id: line.warehouse_id, quantity: line.quantity },
            { preserveScroll: true, onSuccess: () => router.reload({ only: ['products'] }) },
        );

    const applyFilters = (next: Record<string, string | undefined>) =>
        router.get(route('productservice.products.index'), { search, ...filters, ...next }, { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Products')}</h2>}>
            <Head title={t('Products')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap gap-2">
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                applyFilters({});
                            }}
                            className="flex gap-2"
                        >
                            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search name or SKU...')} className="w-64" />
                            <Button type="submit" variant="outline">{t('Search')}</Button>
                        </form>
                        <Select value={filters.type ?? 'all'} onValueChange={(v) => applyFilters({ type: v === 'all' ? undefined : v })}>
                            <SelectTrigger className="w-36"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">{t('All types')}</SelectItem>
                                <SelectItem value="product">{t('Product')}</SelectItem>
                                <SelectItem value="service">{t('Service')}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    {can('create-products') && (
                        <Button onClick={openCreate}><Plus className="mr-1 h-4 w-4" /> {t('Add Product')}</Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Name')}</TableHead>
                                    <TableHead>SKU</TableHead>
                                    <TableHead>{t('Type')}</TableHead>
                                    <TableHead>{t('Category')}</TableHead>
                                    <TableHead>{t('Sale price')}</TableHead>
                                    <TableHead>{t('Stock')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {products.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell>
                                    </TableRow>
                                )}
                                {products.data.map((p) => (
                                    <TableRow key={p.id}>
                                        <TableCell>
                                            <div className="font-medium">{p.name}</div>
                                            {!p.is_active && <Badge variant="secondary">{t('Inactive')}</Badge>}
                                        </TableCell>
                                        <TableCell><code>{p.sku}</code></TableCell>
                                        <TableCell><Badge variant="secondary" className="capitalize">{p.type}</Badge></TableCell>
                                        <TableCell>{p.category?.name ?? '—'}</TableCell>
                                        <TableCell>{p.sale_price}</TableCell>
                                        <TableCell>{p.type === 'service' ? '—' : `${p.total_stock ?? 0} ${p.unit?.name ?? ''}`}</TableCell>
                                        <TableCell className="text-right">
                                            {can('manage-product-stock') && p.type === 'product' && (
                                                <Button size="icon" variant="ghost" title={t('Stock')} onClick={() => openStock(p)}><Boxes className="h-4 w-4" /></Button>
                                            )}
                                            {can('edit-products') && (
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(p)}><Pencil className="h-4 w-4" /></Button>
                                            )}
                                            {can('delete-products') && (
                                                <Button size="icon" variant="ghost" onClick={() => setDeleting(p)}><Trash2 className="h-4 w-4 text-destructive" /></Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={products} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                    <DialogHeader><DialogTitle>{editing ? t('Edit Product') : t('Add Product')}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1">
                                <Label htmlFor="name">{t('Name')}</Label>
                                <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                {form.errors.name && <p className="text-sm text-destructive">{form.errors.name}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="sku">SKU</Label>
                                <Input id="sku" value={form.data.sku} onChange={(e) => form.setData('sku', e.target.value)} />
                                {form.errors.sku && <p className="text-sm text-destructive">{form.errors.sku}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label>{t('Type')}</Label>
                                <Select value={form.data.type} onValueChange={(v) => form.setData('type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="product">{t('Product')}</SelectItem>
                                        <SelectItem value="service">{t('Service')}</SelectItem>
                                    </SelectContent>
                                </Select>
                                {form.errors.type && <p className="text-sm text-destructive">{form.errors.type}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label>{t('Category')}</Label>
                                <Select value={form.data.category_id} onValueChange={(v) => form.setData('category_id', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>{t('None')}</SelectItem>
                                        {categories.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                                {form.errors.category_id && <p className="text-sm text-destructive">{form.errors.category_id}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="sale_price">{t('Sale price')}</Label>
                                <Input id="sale_price" type="number" step="0.01" min={0} value={form.data.sale_price} onChange={(e) => form.setData('sale_price', Number(e.target.value))} />
                                {form.errors.sale_price && <p className="text-sm text-destructive">{form.errors.sale_price}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="purchase_price">{t('Purchase price')}</Label>
                                <Input id="purchase_price" type="number" step="0.01" min={0} value={form.data.purchase_price} onChange={(e) => form.setData('purchase_price', Number(e.target.value))} />
                                {form.errors.purchase_price && <p className="text-sm text-destructive">{form.errors.purchase_price}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label>{t('Unit')}</Label>
                                <Select value={form.data.unit_id} onValueChange={(v) => form.setData('unit_id', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>{t('None')}</SelectItem>
                                        {units.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                                {form.errors.unit_id && <p className="text-sm text-destructive">{form.errors.unit_id}</p>}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label>{t('Taxes')}</Label>
                            {taxes.length === 0 && <p className="text-sm text-muted-foreground">{t('No taxes defined yet.')}</p>}
                            <div className="flex flex-wrap gap-4">
                                {taxes.map((tax) => (
                                    <label key={tax.id} className="flex items-center gap-2 text-sm">
                                        <Checkbox checked={form.data.tax_ids.includes(tax.id)} onCheckedChange={(c) => toggleTax(tax.id, c === true)} />
                                        {tax.name} ({tax.rate}%)
                                    </label>
                                ))}
                            </div>
                            {form.errors['tax_ids'] && <p className="text-sm text-destructive">{form.errors['tax_ids']}</p>}
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="description">{t('Description')}</Label>
                            <Textarea id="description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={form.data.is_active} onCheckedChange={(c) => form.setData('is_active', c === true)} /> {t('Active')}
                        </label>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{editing ? t('Update') : t('Create')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={!!stockFor} onOpenChange={(o) => !o && setStockFor(null)}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('Stock')} – {stockFor?.name}</DialogTitle></DialogHeader>
                    <div className="space-y-3">
                        {stockLines.length === 0 && <p className="text-sm text-muted-foreground">{t('No active warehouses. Create a warehouse first.')}</p>}
                        {stockLines.map((line, i) => (
                            <div key={line.warehouse_id} className="flex items-center gap-2">
                                <div className="flex-1 text-sm font-medium">{line.name}</div>
                                <Input
                                    className="w-32"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={line.quantity}
                                    onChange={(e) => setStockLines(stockLines.map((l, j) => (j === i ? { ...l, quantity: Number(e.target.value) } : l)))}
                                />
                                <Button size="sm" onClick={() => saveStock(line)}>{t('Save')}</Button>
                            </div>
                        ))}
                    </div>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete product?')}</AlertDialogTitle>
                        <AlertDialogDescription>{deleting?.name} {t('and its stock records will be removed.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('productservice.products.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>
                            {t('Delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
