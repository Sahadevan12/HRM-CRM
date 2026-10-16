import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Row {
    label: string;
    sales?: number;
    total?: number | string;
    quantity?: number | string;
    amount?: number | string;
}

interface Props {
    params: { from: string; to: string };
    summary: { sales: number; subtotal: number; discount: number; tax: number; total: number; returns: number; net: number; average: number };
    byMethod: Row[];
    byCashier: Row[];
    daily: Row[];
    topProducts: Row[];
}

const METHOD_LABEL: Record<string, string> = { cash: 'Cash', card: 'Card', bank_transfer: 'Bank transfer', credit: 'On account' };

export default function PosReports({ params, summary, byMethod, byCashier, daily, topProducts }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const [from, setFrom] = useState(params.from);
    const [to, setTo] = useState(params.to);

    const tiles: [string, string][] = [
        [t('Sales'), String(summary.sales)],
        [t('Gross sales'), money(summary.total)],
        [t('Returns'), money(summary.returns)],
        [t('Net sales'), money(summary.net)],
        [t('Average sale'), money(summary.average)],
        [t('Tax collected'), money(summary.tax)],
    ];

    const table = (title: string, rows: Row[], columns: { head: string; cell: (r: Row) => string }[], labelOf: (r: Row) => string = (r) => r.label) => (
        <Card>
            <CardHeader><CardTitle className="text-base">{title}</CardTitle></CardHeader>
            <CardContent className="p-0">
                <Table>
                    <TableHeader><TableRow><TableHead /> {columns.map((c) => <TableHead key={c.head} className="text-right">{c.head}</TableHead>)}</TableRow></TableHeader>
                    <TableBody>
                        {rows.length === 0 && <TableRow><TableCell colSpan={columns.length + 1} className="py-4 text-center text-muted-foreground">{t('No sales in this period.')}</TableCell></TableRow>}
                        {rows.map((r) => (
                            <TableRow key={r.label}>
                                <TableCell>{labelOf(r)}</TableCell>
                                {columns.map((c) => <TableCell key={c.head} className="text-right">{c.cell(r)}</TableCell>)}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('POS Reports')}</h2>}>
            <Head title={t('POS Reports')} />

            <div className="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <form onSubmit={(e) => { e.preventDefault(); router.get(route('pos.reports.index'), { from, to }, { preserveState: true }); }} className="flex flex-wrap items-end gap-3 print:hidden">
                    <div className="space-y-1"><Label>{t('From')}</Label><Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-40" /></div>
                    <div className="space-y-1"><Label>{t('To')}</Label><Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-40" /></div>
                    <Button type="submit">{t('Run')}</Button>
                    <Button type="button" variant="outline" onClick={() => window.print()}><Printer className="mr-1 h-4 w-4" /> {t('Print')}</Button>
                </form>

                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
                    {tiles.map(([label, value]) => (
                        <Card key={label}><CardContent className="pt-5"><div className="text-xs text-muted-foreground">{label}</div><div className="text-xl font-bold">{value}</div></CardContent></Card>
                    ))}
                </div>

                <div className="grid gap-6 md:grid-cols-2">
                    {table(t('By payment method'), byMethod, [{ head: t('Sales'), cell: (r) => String(r.sales) }, { head: t('Total'), cell: (r) => money(r.total) }], (r) => t(METHOD_LABEL[r.label] ?? r.label))}
                    {table(t('By cashier'), byCashier, [{ head: t('Sales'), cell: (r) => String(r.sales) }, { head: t('Total'), cell: (r) => money(r.total) }])}
                    {table(t('By day'), daily, [{ head: t('Sales'), cell: (r) => String(r.sales) }, { head: t('Total'), cell: (r) => money(r.total) }])}
                    {table(t('Top products'), topProducts, [{ head: t('Qty'), cell: (r) => String(Number(r.quantity)) }, { head: t('Amount'), cell: (r) => money(r.amount) }])}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
