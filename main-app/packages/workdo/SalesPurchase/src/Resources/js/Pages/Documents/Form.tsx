import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { DocItem, DocumentRow, ProductOption, TaxOption, TypeProps } from './types';

interface Line {
    product_id: string;
    quantity: number;
    unit_price: number;
    discount_amount: number;
    tax_ids: number[];
}

interface ReturnLine {
    source_item_id: number;
    quantity: number;
}

interface Props {
    type: TypeProps;
    document?: DocumentRow;
    parties?: { id: number; name: string; email: string }[];
    warehouses?: { id: number; name: string }[];
    products?: ProductOption[];
    taxes?: TaxOption[];
    invoice?: DocumentRow;
    remaining?: Record<string, number>;
}

const today = () => new Date().toISOString().slice(0, 10);
const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

/** Same formula as the server (DocumentCalculator) – used for the live preview only, the server recalculates. */
function lineTotals(line: Line, taxes: TaxOption[]) {
    const gross = round2(line.quantity * line.unit_price);
    const discount = round2(line.discount_amount);
    const net = round2(gross - discount);
    const tax = round2(line.tax_ids.reduce((sum, id) => sum + round2((net * (taxes.find((t) => t.id === id)?.rate ?? 0)) / 100), 0));
    return { gross, discount, tax, total: round2(net + tax) };
}

export default function DocumentForm(props: Props) {
    return props.type.isReturn ? <ReturnForm {...props} /> : <EditableForm {...props} />;
}

// ───────────────────────── invoice / purchase / proposal ─────────────────────────

function EditableForm({ type, document, parties = [], warehouses = [], products = [], taxes = [] }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const isPurchase = type.party === 'vendor';

    const form = useForm<{ party_id: string; warehouse_id: string; doc_date: string; due_date: string; notes: string; items: Line[] }>({
        party_id: document ? String(document.party_id) : '',
        warehouse_id: document ? String(document.warehouse_id) : warehouses.length === 1 ? String(warehouses[0].id) : '',
        doc_date: document?.doc_date ?? today(),
        due_date: document?.due_date ?? '',
        notes: document?.notes ?? '',
        items: (document?.items ?? []).map((i: DocItem) => ({
            product_id: String(i.product_id),
            quantity: i.quantity,
            unit_price: i.unit_price,
            discount_amount: i.discount_amount,
            tax_ids: i.taxes.map((x) => x.tax_id).filter((x): x is number => x !== null),
        })),
    });

    const setItem = (index: number, patch: Partial<Line>) =>
        form.setData('items', form.data.items.map((item, i) => (i === index ? { ...item, ...patch } : item)));

    const addLine = () =>
        form.setData('items', [...form.data.items, { product_id: '', quantity: 1, unit_price: 0, discount_amount: 0, tax_ids: [] }]);

    const pickProduct = (index: number, productId: string) => {
        const product = products.find((p) => String(p.id) === productId);
        setItem(index, {
            product_id: productId,
            unit_price: product ? (isPurchase ? product.purchase_price : product.sale_price) : 0,
            tax_ids: product?.tax_ids ?? [],
        });
    };

    const toggleTax = (index: number, taxId: number, checked: boolean) => {
        const current = form.data.items[index].tax_ids;
        setItem(index, { tax_ids: checked ? [...current, taxId] : current.filter((x) => x !== taxId) });
    };

    const totals = form.data.items.reduce(
        (sum, line) => {
            const l = lineTotals(line, taxes);
            return { subtotal: sum.subtotal + l.gross, discount: sum.discount + l.discount, tax: sum.tax + l.tax };
        },
        { subtotal: 0, discount: 0, tax: 0 },
    );
    const grand = round2(totals.subtotal - totals.discount + totals.tax);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (document) form.put(route(`${type.routeBase}.update`, document.id));
        else form.post(route(`${type.routeBase}.store`));
    };

    const err = (key: string) => (form.errors as Record<string, string>)[key];
    const title = `${document ? t('Edit') : t('New')} ${t(type.label)}${document ? ` ${document.number}` : ''}`;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{title}</h2>}>
            <Head title={title} />

            <form onSubmit={submit} className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <Card>
                    <CardContent className="grid gap-4 pt-6 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="space-y-1">
                            <Label>{t(type.partyLabel)}</Label>
                            <Select value={form.data.party_id} onValueChange={(v) => form.setData('party_id', v)}>
                                <SelectTrigger><SelectValue placeholder={t('Choose')} /></SelectTrigger>
                                <SelectContent>
                                    {parties.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {parties.length === 0 && (
                                <p className="text-xs text-muted-foreground">{t('No')} {t(type.partyLabel).toLowerCase()}s – {t('add one in Users first.')}</p>
                            )}
                            {err('party_id') && <p className="text-sm text-destructive">{err('party_id')}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label>{t('Warehouse')}</Label>
                            <Select value={form.data.warehouse_id} onValueChange={(v) => form.setData('warehouse_id', v)}>
                                <SelectTrigger><SelectValue placeholder={t('Choose')} /></SelectTrigger>
                                <SelectContent>
                                    {warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {err('warehouse_id') && <p className="text-sm text-destructive">{err('warehouse_id')}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="doc_date">{t('Date')}</Label>
                            <Input id="doc_date" type="date" value={form.data.doc_date} onChange={(e) => form.setData('doc_date', e.target.value)} />
                            {err('doc_date') && <p className="text-sm text-destructive">{err('doc_date')}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="due_date">{type.key === 'sales_proposal' ? t('Valid until') : t('Due date')}</Label>
                            <Input id="due_date" type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} />
                            {err('due_date') && <p className="text-sm text-destructive">{err('due_date')}</p>}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>{t('Items')}</CardTitle>
                        <Button type="button" size="sm" variant="outline" onClick={addLine}><Plus className="mr-1 h-4 w-4" /> {t('Add line')}</Button>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="min-w-56">{t('Product')}</TableHead>
                                    <TableHead className="w-24">{t('Qty')}</TableHead>
                                    <TableHead className="w-32">{t('Price')}</TableHead>
                                    <TableHead className="w-28">{t('Discount')}</TableHead>
                                    <TableHead>{t('Taxes')}</TableHead>
                                    <TableHead className="text-right">{t('Total')}</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {form.data.items.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="py-6 text-center text-muted-foreground">{t('Add at least one line.')}</TableCell>
                                    </TableRow>
                                )}
                                {form.data.items.map((line, i) => (
                                    <TableRow key={i} className="align-top">
                                        <TableCell>
                                            <Select value={line.product_id} onValueChange={(v) => pickProduct(i, v)}>
                                                <SelectTrigger><SelectValue placeholder={t('Choose a product')} /></SelectTrigger>
                                                <SelectContent>
                                                    {products.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name} ({p.sku})</SelectItem>)}
                                                </SelectContent>
                                            </Select>
                                            {err(`items.${i}.product_id`) && <p className="text-xs text-destructive">{err(`items.${i}.product_id`)}</p>}
                                        </TableCell>
                                        <TableCell>
                                            <Input type="number" min={0} step="0.01" value={line.quantity} onChange={(e) => setItem(i, { quantity: Number(e.target.value) })} />
                                            {err(`items.${i}.quantity`) && <p className="text-xs text-destructive">{err(`items.${i}.quantity`)}</p>}
                                        </TableCell>
                                        <TableCell>
                                            <Input type="number" min={0} step="0.01" value={line.unit_price} onChange={(e) => setItem(i, { unit_price: Number(e.target.value) })} />
                                            {err(`items.${i}.unit_price`) && <p className="text-xs text-destructive">{err(`items.${i}.unit_price`)}</p>}
                                        </TableCell>
                                        <TableCell>
                                            <Input type="number" min={0} step="0.01" value={line.discount_amount} onChange={(e) => setItem(i, { discount_amount: Number(e.target.value) })} />
                                            {err(`items.${i}.discount_amount`) && <p className="text-xs text-destructive">{err(`items.${i}.discount_amount`)}</p>}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-col gap-1 text-sm">
                                                {taxes.length === 0 && <span className="text-muted-foreground">—</span>}
                                                {taxes.map((tax) => (
                                                    <label key={tax.id} className="flex items-center gap-2">
                                                        <input type="checkbox" checked={line.tax_ids.includes(tax.id)} onChange={(e) => toggleTax(i, tax.id, e.target.checked)} />
                                                        {tax.name} ({tax.rate}%)
                                                    </label>
                                                ))}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right font-medium">{money(lineTotals(line, taxes).total)}</TableCell>
                                        <TableCell>
                                            <Button type="button" size="icon" variant="ghost" onClick={() => form.setData('items', form.data.items.filter((_, j) => j !== i))}>
                                                <Trash2 className="h-4 w-4 text-destructive" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        {err('items') && <p className="p-4 text-sm text-destructive">{err('items')}</p>}
                    </CardContent>
                </Card>

                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="space-y-1 lg:col-span-2">
                        <Label htmlFor="notes">{t('Notes')}</Label>
                        <Textarea id="notes" rows={4} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </div>
                    <Card>
                        <CardContent className="space-y-2 pt-6 text-sm">
                            <div className="flex justify-between"><span>{t('Subtotal')}</span><span>{money(totals.subtotal)}</span></div>
                            <div className="flex justify-between"><span>{t('Discount')}</span><span>-{money(totals.discount)}</span></div>
                            <div className="flex justify-between"><span>{t('Tax')}</span><span>{money(totals.tax)}</span></div>
                            <div className="flex justify-between border-t pt-2 text-base font-bold"><span>{t('Total')}</span><span>{money(grand)}</span></div>
                        </CardContent>
                    </Card>
                </div>

                <div className="flex justify-end gap-2">
                    <Button asChild variant="outline"><Link href={route(`${type.routeBase}.index`)}>{t('Cancel')}</Link></Button>
                    <Button type="submit" disabled={form.processing}>{document ? t('Update') : t('Create')} ({t('draft')})</Button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}

// ───────────────────────── return (from a posted invoice) ─────────────────────────

function ReturnForm({ type, invoice, remaining = {} }: Props) {
    const { t } = useTranslation();
    const money = useMoney();

    const form = useForm<{ parent_id: number; doc_date: string; reason: string; notes: string; items: ReturnLine[] }>({
        parent_id: invoice!.id,
        doc_date: today(),
        reason: '',
        notes: '',
        items: (invoice!.items ?? []).map((i) => ({ source_item_id: i.id, quantity: 0 })),
    });

    const setQty = (index: number, quantity: number) =>
        form.setData('items', form.data.items.map((l, i) => (i === index ? { ...l, quantity } : l)));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({ ...data, items: data.items.filter((l) => l.quantity > 0) }));
        form.post(route(`${type.routeBase}.store`));
    };

    const err = (key: string) => (form.errors as Record<string, string>)[key];
    const picked = form.data.items.filter((l) => l.quantity > 0).length;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('New')} {t(type.label)} – {invoice!.number}</h2>}>
            <Head title={`${t('New')} ${t(type.label)}`} />

            <form onSubmit={submit} className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <Card>
                    <CardContent className="grid gap-4 pt-6 sm:grid-cols-3">
                        <div><div className="text-sm text-muted-foreground">{t(type.partyLabel)}</div><div className="font-medium">{invoice!.party?.name}</div></div>
                        <div><div className="text-sm text-muted-foreground">{t('Warehouse')}</div><div className="font-medium">{invoice!.warehouse?.name}</div></div>
                        <div className="space-y-1">
                            <Label htmlFor="doc_date">{t('Date')}</Label>
                            <Input id="doc_date" type="date" value={form.data.doc_date} onChange={(e) => form.setData('doc_date', e.target.value)} />
                            {err('doc_date') && <p className="text-sm text-destructive">{err('doc_date')}</p>}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>{t('Items to return')}</CardTitle></CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Product')}</TableHead>
                                    <TableHead className="text-right">{t('Invoiced')}</TableHead>
                                    <TableHead className="text-right">{t('Can return')}</TableHead>
                                    <TableHead className="text-right">{t('Price')}</TableHead>
                                    <TableHead className="w-32">{t('Return qty')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {(invoice!.items ?? []).map((item, i) => {
                                    const left = remaining[item.id] ?? 0;
                                    return (
                                        <TableRow key={item.id}>
                                            <TableCell className="font-medium">{item.name}</TableCell>
                                            <TableCell className="text-right">{item.quantity}</TableCell>
                                            <TableCell className="text-right">{left}</TableCell>
                                            <TableCell className="text-right">{money(item.unit_price)}</TableCell>
                                            <TableCell>
                                                <Input type="number" min={0} max={left} step="0.01" disabled={left <= 0} value={form.data.items[i].quantity}
                                                    onChange={(e) => setQty(i, Math.min(Number(e.target.value), left))} />
                                                {err(`items.${i}.quantity`) && <p className="text-xs text-destructive">{err(`items.${i}.quantity`)}</p>}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                        {(err('items') || err('parent_id')) && <p className="p-4 text-sm text-destructive">{err('items') ?? err('parent_id')}</p>}
                    </CardContent>
                </Card>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-1">
                        <Label htmlFor="reason">{t('Reason')}</Label>
                        <Textarea id="reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="notes">{t('Notes')}</Label>
                        <Textarea id="notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </div>
                </div>

                <div className="flex justify-end gap-2">
                    <Button asChild variant="outline"><Link href={route(`salespurchase.${invoice!.type === 'sales_invoice' ? 'sales' : 'purchase'}-invoices.show`, invoice!.id)}>{t('Cancel')}</Link></Button>
                    <Button type="submit" disabled={form.processing || picked === 0}>{t('Create')} ({t('draft')})</Button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
