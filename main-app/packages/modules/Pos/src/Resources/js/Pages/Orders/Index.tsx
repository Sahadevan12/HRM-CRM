import Pagination from '@/Components/Pagination';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Paginated } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Sale {
    id: number;
    payment_method: string;
    amount_paid: number;
    document: { id: number; number: string; doc_date: string; total_amount: number; paid_amount: number; party: { name: string } };
    cashier: { name: string } | null;
}

interface Props {
    sales: Paginated<Sale>;
    total: number;
    methods: string[];
    filters: { search?: string; method?: string; from?: string; to?: string };
}

const METHOD_LABEL: Record<string, string> = { cash: 'Cash', card: 'Card', bank_transfer: 'Bank transfer', credit: 'On account' };

export default function PosOrders({ sales, total, methods, filters }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const [search, setSearch] = useState(filters.search ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    const apply = (next: Record<string, string | undefined> = {}) =>
        router.get(route('pos.orders.index'), { search, from, to, method: filters.method, ...next }, { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('POS Orders')}</h2>}>
            <Head title={t('POS Orders')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <form onSubmit={(e) => { e.preventDefault(); apply(); }} className="flex flex-wrap gap-2">
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search number or customer...')} className="w-60" />
                        <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-40" aria-label={t('From')} />
                        <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-40" aria-label={t('To')} />
                        <Select value={filters.method ?? 'all'} onValueChange={(v) => apply({ method: v === 'all' ? undefined : v })}>
                            <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">{t('All methods')}</SelectItem>
                                {methods.map((m) => <SelectItem key={m} value={m}>{t(METHOD_LABEL[m] ?? m)}</SelectItem>)}
                            </SelectContent>
                        </Select>
                        <Button type="submit" variant="outline">{t('Filter')}</Button>
                    </form>
                    <div className="text-right text-sm text-muted-foreground">{t('Total of the list')}<div className="text-xl font-bold text-foreground">{money(total)}</div></div>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Sale')}</TableHead><TableHead>{t('Date')}</TableHead><TableHead>{t('Customer')}</TableHead>
                                    <TableHead>{t('Cashier')}</TableHead><TableHead>{t('Payment')}</TableHead><TableHead className="text-right">{t('Total')}</TableHead><TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sales.data.length === 0 && <TableRow><TableCell colSpan={7} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell></TableRow>}
                                {sales.data.map((s) => (
                                    <TableRow key={s.id}>
                                        <TableCell className="font-medium">{s.document.number}</TableCell>
                                        <TableCell>{s.document.doc_date}</TableCell>
                                        <TableCell>{s.document.party.name}</TableCell>
                                        <TableCell>{s.cashier?.name ?? '-'}</TableCell>
                                        <TableCell><Badge variant={s.payment_method === 'credit' ? 'outline' : 'secondary'}>{t(METHOD_LABEL[s.payment_method] ?? s.payment_method)}</Badge></TableCell>
                                        <TableCell className="text-right">{money(s.document.total_amount)}</TableCell>
                                        <TableCell className="text-right">
                                            <Button asChild size="sm" variant="outline"><Link href={route('pos.receipts.show', s.id)}>{t('Receipt')}</Link></Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={sales} />
            </div>
        </AuthenticatedLayout>
    );
}
