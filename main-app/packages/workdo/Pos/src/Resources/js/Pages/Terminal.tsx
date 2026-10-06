import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { useMoney } from '@/hooks/useMoney';
import { cn } from '@/lib/utils';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Banknote, CreditCard, Landmark, Minus, NotebookPen, Plus, Search, Trash2 } from 'lucide-react';
import { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from 'sonner';

interface Product {
    id: number;
    name: string;
    sku: string;
    type: 'product' | 'service';
    category_id: number | null;
    sale_price: number;
    tax_ids: number[];
    stock: number | null;
}

interface CartLine {
    product: Product;
    quantity: number;
    discount: number;
}

interface Props {
    warehouses: { id: number; name: string }[];
    customers: { id: number; name: string }[];
    taxes: { id: number; name: string; rate: number }[];
    categories: { id: number; name: string }[];
    methods: string[];
    canSell: boolean;
}

const WALK_IN = 'walk-in';
const ALL = 'all';
const METHOD_ICON: Record<string, typeof Banknote> = { cash: Banknote, card: CreditCard, bank_transfer: Landmark, credit: NotebookPen };
const METHOD_LABEL: Record<string, string> = { cash: 'Cash', card: 'Card', bank_transfer: 'Bank transfer', credit: 'On account' };
const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

export default function Terminal({ warehouses, customers, taxes, categories, methods, canSell }: Props) {
    const { t } = useTranslation();
    const money = useMoney();

    const [warehouseId, setWarehouseId] = useState(warehouses[0] ? String(warehouses[0].id) : '');
    const [customerId, setCustomerId] = useState(WALK_IN);
    const [category, setCategory] = useState(ALL);
    const [query, setQuery] = useState('');
    const [products, setProducts] = useState<Product[]>([]);
    const [cart, setCart] = useState<CartLine[]>([]);
    const [method, setMethod] = useState('cash');
    const [tendered, setTendered] = useState('');
    const [busy, setBusy] = useState(false);
    const searchRef = useRef<HTMLInputElement>(null);

    // product grid: reload when the warehouse, category or search text changes (debounced)
    useEffect(() => {
        if (!warehouseId) return;
        const handle = setTimeout(async () => {
            const { data } = await axios.get<Product[]>(route('pos.products'), {
                params: { warehouse_id: warehouseId, q: query || undefined, category: category === ALL ? undefined : category },
            });
            setProducts(data);
        }, 250);
        return () => clearTimeout(handle);
    }, [warehouseId, category, query]);

    // changing the warehouse changes the stock: start a fresh cart
    const changeWarehouse = (id: string) => {
        setWarehouseId(id);
        setCart([]);
    };

    const add = (product: Product) => {
        setCart((current) => {
            const existing = current.find((l) => l.product.id === product.id);
            const next = (existing?.quantity ?? 0) + 1;
            if (product.stock !== null && next > product.stock) {
                toast.error(`${product.name}: ${t('only')} ${product.stock} ${t('in stock')}`);
                return current;
            }
            return existing ? current.map((l) => (l.product.id === product.id ? { ...l, quantity: next } : l)) : [...current, { product, quantity: 1, discount: 0 }];
        });
    };

    const setLine = (id: number, patch: Partial<CartLine>) => setCart((c) => c.map((l) => (l.product.id === id ? { ...l, ...patch } : l)));

    const setQuantity = (line: CartLine, quantity: number) => {
        if (quantity <= 0) return setCart((c) => c.filter((l) => l.product.id !== line.product.id));
        if (line.product.stock !== null && quantity > line.product.stock) {
            toast.error(`${line.product.name}: ${t('only')} ${line.product.stock} ${t('in stock')}`);
            return;
        }
        setLine(line.product.id, { quantity });
    };

    // search box: Enter with an exact SKU / barcode adds the item straight away (barcode scanners "type" the code + Enter)
    const onSearchSubmit = async (e: FormEvent) => {
        e.preventDefault();
        const code = query.trim();
        if (!code || !warehouseId) return;
        const { data } = await axios.get<Product[]>(route('pos.products'), { params: { warehouse_id: warehouseId, sku: code } });
        if (data[0]) {
            add(data[0]);
            setQuery('');
        } else {
            toast.error(t('No product with this code'));
        }
    };

    const totals = useMemo(() => {
        let subtotal = 0, discount = 0, tax = 0;
        cart.forEach((l) => {
            const gross = round2(l.quantity * l.product.sale_price);
            const disc = Math.min(round2(l.discount), gross);
            const net = round2(gross - disc);
            subtotal += gross;
            discount += disc;
            tax += l.product.tax_ids.reduce((s, id) => s + round2((net * (taxes.find((x) => x.id === id)?.rate ?? 0)) / 100), 0);
        });
        const total = round2(subtotal - discount + tax);
        return { subtotal: round2(subtotal), discount: round2(discount), tax: round2(tax), total };
    }, [cart, taxes]);

    const received = Number(tendered) || 0;
    const change = method === 'cash' ? round2(received - totals.total) : 0;
    const needsCustomer = method === 'credit' && customerId === WALK_IN;
    const canPay = cart.length > 0 && canSell && !needsCustomer && (method !== 'cash' || received >= totals.total);

    const complete = () => {
        setBusy(true);
        router.post(
            route('pos.checkout'),
            {
                warehouse_id: warehouseId,
                customer_id: customerId === WALK_IN ? null : customerId,
                payment_method: method,
                amount_tendered: method === 'cash' ? received : null,
                items: cart.map((l) => ({ product_id: l.product.id, quantity: l.quantity, discount_amount: l.discount })),
            },
            {
                onError: (errors) => toast.error(Object.values(errors)[0] ?? t('The sale could not be completed.')),
                onFinish: () => setBusy(false),
            },
        );
    };

    const roundUps = totals.total > 0 ? [...new Set([totals.total, Math.ceil(totals.total / 10) * 10, Math.ceil(totals.total / 50) * 50, Math.ceil(totals.total / 100) * 100, Math.ceil(totals.total / 500) * 500])] : [];

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('POS Terminal')}</h2>}>
            <Head title={t('POS Terminal')} />

            {warehouses.length === 0 ? (
                <div className="mx-auto max-w-xl px-4 py-16 text-center text-muted-foreground">{t('Create a warehouse and some products first.')}</div>
            ) : (
                <div className="grid gap-4 p-4 lg:grid-cols-5">
                    {/* ───────── products ───────── */}
                    <div className="space-y-3 lg:col-span-3">
                        <div className="flex flex-wrap gap-2">
                            <form onSubmit={onSearchSubmit} className="relative min-w-60 flex-1">
                                <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input ref={searchRef} autoFocus className="pl-9" value={query} onChange={(e) => setQuery(e.target.value)} placeholder={t('Search or scan barcode / SKU...')} />
                            </form>
                            <Select value={warehouseId} onValueChange={changeWarehouse}>
                                <SelectTrigger className="w-44"><SelectValue /></SelectTrigger>
                                <SelectContent>{warehouses.map((w) => <SelectItem key={w.id} value={String(w.id)}>{w.name}</SelectItem>)}</SelectContent>
                            </Select>
                            <Select value={category} onValueChange={setCategory}>
                                <SelectTrigger className="w-44"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>{t('All categories')}</SelectItem>
                                    {categories.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                            {products.length === 0 && <p className="col-span-full py-10 text-center text-muted-foreground">{t('No products available in this warehouse.')}</p>}
                            {products.map((p) => (
                                <button key={p.id} type="button" onClick={() => add(p)} className="rounded-lg border bg-card p-3 text-left transition hover:border-primary hover:shadow">
                                    <div className="line-clamp-2 min-h-10 text-sm font-medium">{p.name}</div>
                                    <div className="mt-1 text-lg font-bold">{money(p.sale_price)}</div>
                                    <div className="flex items-center justify-between text-xs text-muted-foreground">
                                        <span>{p.sku}</span>
                                        {p.stock !== null ? <span>{p.stock} {t('left')}</span> : <Badge variant="secondary">{t('Service')}</Badge>}
                                    </div>
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* ───────── cart ───────── */}
                    <Card className="h-fit lg:col-span-2">
                        <CardContent className="space-y-4 pt-6">
                            <Select value={customerId} onValueChange={setCustomerId}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={WALK_IN}>{t('Walk-in customer')}</SelectItem>
                                    {customers.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>

                            <div className="max-h-80 space-y-2 overflow-y-auto">
                                {cart.length === 0 && <p className="py-6 text-center text-sm text-muted-foreground">{t('The cart is empty. Click a product or scan a code.')}</p>}
                                {cart.map((line) => (
                                    <div key={line.product.id} className="rounded-md border p-2 text-sm">
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="font-medium">{line.product.name}</div>
                                            <button type="button" aria-label={t('Remove')} onClick={() => setCart((c) => c.filter((l) => l.product.id !== line.product.id))}><Trash2 className="h-4 w-4 text-destructive" /></button>
                                        </div>
                                        <div className="mt-1 flex items-center gap-2">
                                            <Button type="button" size="icon" variant="outline" className="h-7 w-7" onClick={() => setQuantity(line, line.quantity - 1)}><Minus className="h-3 w-3" /></Button>
                                            <Input className="h-7 w-16 text-center" type="number" min={0} step="1" value={line.quantity} onChange={(e) => setQuantity(line, Number(e.target.value))} />
                                            <Button type="button" size="icon" variant="outline" className="h-7 w-7" onClick={() => setQuantity(line, line.quantity + 1)}><Plus className="h-3 w-3" /></Button>
                                            <span className="text-muted-foreground">× {money(line.product.sale_price)}</span>
                                            <span className="ml-auto font-semibold">{money(round2(line.quantity * line.product.sale_price - line.discount))}</span>
                                        </div>
                                        <div className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
                                            {t('Discount')}
                                            <Input className="h-6 w-20" type="number" min={0} step="0.01" value={line.discount} onChange={(e) => setLine(line.product.id, { discount: Number(e.target.value) })} />
                                        </div>
                                    </div>
                                ))}
                            </div>

                            <div className="space-y-1 border-t pt-3 text-sm">
                                <div className="flex justify-between"><span>{t('Subtotal')}</span><span>{money(totals.subtotal)}</span></div>
                                <div className="flex justify-between"><span>{t('Discount')}</span><span>-{money(totals.discount)}</span></div>
                                <div className="flex justify-between"><span>{t('Tax')}</span><span>{money(totals.tax)}</span></div>
                                <div className="flex justify-between text-2xl font-bold"><span>{t('Total')}</span><span>{money(totals.total)}</span></div>
                            </div>

                            <div className="grid grid-cols-2 gap-2">
                                {methods.map((m) => {
                                    const Icon = METHOD_ICON[m] ?? Banknote;
                                    return (
                                        <button key={m} type="button" onClick={() => setMethod(m)} className={cn('flex items-center justify-center gap-2 rounded-md border p-2 text-sm', method === m && 'border-primary bg-primary/10 font-semibold text-primary')}>
                                            <Icon className="h-4 w-4" /> {t(METHOD_LABEL[m] ?? m)}
                                        </button>
                                    );
                                })}
                            </div>

                            {method === 'cash' && (
                                <div className="space-y-2">
                                    <Input type="number" min={0} step="0.01" value={tendered} onChange={(e) => setTendered(e.target.value)} placeholder={t('Cash received')} />
                                    <div className="flex flex-wrap gap-1">
                                        {roundUps.map((amount) => (
                                            <Button key={amount} type="button" size="sm" variant="outline" onClick={() => setTendered(String(amount))}>{amount === totals.total ? t('Exact') : money(amount)}</Button>
                                        ))}
                                    </div>
                                    {received > 0 && (
                                        <div className={cn('flex justify-between text-lg font-semibold', change < 0 && 'text-destructive')}>
                                            <span>{change < 0 ? t('Short by') : t('Change')}</span><span>{money(Math.abs(change))}</span>
                                        </div>
                                    )}
                                </div>
                            )}
                            {needsCustomer && <p className="text-sm text-destructive">{t('Choose a customer for a sale on account.')}</p>}
                            {!canSell && <p className="text-sm text-destructive">{t('You can look at the terminal but you are not allowed to make sales.')}</p>}

                            <Button className="w-full" size="lg" disabled={!canPay || busy} onClick={complete}>{t('Complete sale')} · {money(totals.total)}</Button>
                            {cart.length > 0 && <Button className="w-full" variant="ghost" onClick={() => setCart([])}>{t('Clear cart')}</Button>}
                        </CardContent>
                    </Card>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
