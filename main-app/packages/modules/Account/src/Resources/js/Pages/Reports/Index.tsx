import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useMoney } from '@/hooks/useMoney';
import { cn } from '@/lib/utils';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

type Row = { code: string; name: string; amount: number };

interface Props {
    report: 'trial-balance' | 'profit-loss' | 'balance-sheet' | 'ledger';
    // the shape depends on the report; each section below knows its own
    data: any;
    accounts: { id: number; code: string; name: string; type: string }[];
    params: { from: string; to: string; account?: string | null };
}

const REPORTS = [
    ['trial-balance', 'Trial Balance'],
    ['profit-loss', 'Profit & Loss'],
    ['balance-sheet', 'Balance Sheet'],
    ['ledger', 'General Ledger'],
] as const;

export default function ReportsIndex({ report, data, accounts, params }: Props) {
    const { t } = useTranslation();
    const money = useMoney();

    const [from, setFrom] = useState(params.from);
    const [to, setTo] = useState(params.to);
    const [account, setAccount] = useState<string>(params.account ?? String(data?.account?.id ?? ''));

    const go = (next: Record<string, string | undefined> = {}) =>
        router.get(route('account.reports.index'), { report, from, to, account: report === 'ledger' ? account : undefined, ...next }, { preserveState: false });

    const usesRange = report === 'profit-loss' || report === 'ledger';

    const section = (title: string, rows: Row[], total: number, totalLabel: string) => (
        <>
            <TableRow className="bg-muted/50"><TableCell colSpan={2} className="font-semibold">{title}</TableCell></TableRow>
            {rows.map((r) => (
                <TableRow key={r.code}>
                    <TableCell><span className="font-mono text-muted-foreground">{r.code}</span> {r.name}</TableCell>
                    <TableCell className="text-right">{money(r.amount)}</TableCell>
                </TableRow>
            ))}
            <TableRow className="font-semibold"><TableCell>{totalLabel}</TableCell><TableCell className="text-right">{money(total)}</TableCell></TableRow>
        </>
    );

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Accounting Reports')}</h2>}>
            <Head title={t('Accounting Reports')} />

            <div className="mx-auto max-w-5xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap gap-1 rounded-lg bg-muted p-1 print:hidden">
                    {REPORTS.map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => go({ report: key })}
                            className={cn('flex-1 rounded-md px-3 py-1.5 text-sm font-medium', report === key ? 'bg-background shadow' : 'text-muted-foreground hover:text-foreground')}
                        >
                            {t(label)}
                        </button>
                    ))}
                </div>

                <form onSubmit={(e) => { e.preventDefault(); go(); }} className="flex flex-wrap items-end gap-3 print:hidden">
                    {usesRange && (
                        <div className="space-y-1"><Label>{t('From')}</Label><Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-40" /></div>
                    )}
                    <div className="space-y-1"><Label>{usesRange ? t('To') : t('As of')}</Label><Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-40" /></div>
                    {report === 'ledger' && (
                        <div className="space-y-1">
                            <Label>{t('Account')}</Label>
                            <Select value={account} onValueChange={setAccount}>
                                <SelectTrigger className="w-64"><SelectValue /></SelectTrigger>
                                <SelectContent>{accounts.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.code} – {a.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                    )}
                    <Button type="submit">{t('Run')}</Button>
                    <Button type="button" variant="outline" onClick={() => window.print()}><Printer className="mr-1 h-4 w-4" /> {t('Print')}</Button>
                </form>

                <Card>
                    <CardContent className="pt-6">
                        <div className="mb-4 text-lg font-semibold">
                            {t(REPORTS.find(([k]) => k === report)![1])}
                            <span className="ml-2 text-sm font-normal text-muted-foreground">{usesRange ? `${params.from} → ${params.to}` : params.to}</span>
                        </div>

                        {report === 'trial-balance' && (
                            <Table>
                                <TableHeader>
                                    <TableRow><TableHead>{t('Account')}</TableHead><TableHead className="text-right">{t('Debit')}</TableHead><TableHead className="text-right">{t('Credit')}</TableHead></TableRow>
                                </TableHeader>
                                <TableBody>
                                    {data.rows.length === 0 && <TableRow><TableCell colSpan={3} className="py-6 text-center text-muted-foreground">{t('No bookings yet.')}</TableCell></TableRow>}
                                    {data.rows.map((r: any) => (
                                        <TableRow key={r.code}>
                                            <TableCell><span className="font-mono text-muted-foreground">{r.code}</span> {r.name}</TableCell>
                                            <TableCell className="text-right">{r.balance_debit > 0 ? money(r.balance_debit) : ''}</TableCell>
                                            <TableCell className="text-right">{r.balance_credit > 0 ? money(r.balance_credit) : ''}</TableCell>
                                        </TableRow>
                                    ))}
                                    <TableRow className="font-semibold">
                                        <TableCell>{t('Totals')}</TableCell>
                                        <TableCell className="text-right">{money(data.total_debit)}</TableCell>
                                        <TableCell className="text-right">{money(data.total_credit)}</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        )}

                        {report === 'profit-loss' && (
                            <Table>
                                <TableBody>
                                    {section(t('Revenue'), data.revenue, data.total_revenue, t('Total revenue'))}
                                    {section(t('Expenses'), data.expenses, data.total_expenses, t('Total expenses'))}
                                    <TableRow className="text-base font-bold">
                                        <TableCell>{data.net_profit >= 0 ? t('Net profit') : t('Net loss')}</TableCell>
                                        <TableCell className={cn('text-right', data.net_profit < 0 && 'text-destructive')}>{money(data.net_profit)}</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        )}

                        {report === 'balance-sheet' && (
                            <Table>
                                <TableBody>
                                    {section(t('Assets'), data.assets, data.total_assets, t('Total assets'))}
                                    {section(t('Liabilities'), data.liabilities, data.total_liabilities, t('Total liabilities'))}
                                    <TableRow className="bg-muted/50"><TableCell colSpan={2} className="font-semibold">{t('Equity')}</TableCell></TableRow>
                                    {data.equity.map((r: Row) => (
                                        <TableRow key={r.code}><TableCell><span className="font-mono text-muted-foreground">{r.code}</span> {r.name}</TableCell><TableCell className="text-right">{money(r.amount)}</TableCell></TableRow>
                                    ))}
                                    <TableRow><TableCell>{t('Current earnings')}</TableCell><TableCell className="text-right">{money(data.current_earnings)}</TableCell></TableRow>
                                    <TableRow className="font-semibold"><TableCell>{t('Total equity')}</TableCell><TableCell className="text-right">{money(data.total_equity)}</TableCell></TableRow>
                                    <TableRow className="text-base font-bold">
                                        <TableCell>{t('Liabilities + equity')}</TableCell>
                                        <TableCell className="text-right">
                                            {money(data.total_liabilities + data.total_equity)}{' '}
                                            <Badge variant={data.balanced ? 'default' : 'destructive'}>{data.balanced ? t('Balanced') : t('Out of balance')}</Badge>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        )}

                        {report === 'ledger' && (
                            <>
                                <div className="mb-2 text-sm text-muted-foreground">{data.account.code} – {data.account.name}</div>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t('Date')}</TableHead><TableHead>{t('Entry')}</TableHead><TableHead>{t('Description')}</TableHead>
                                            <TableHead className="text-right">{t('Debit')}</TableHead><TableHead className="text-right">{t('Credit')}</TableHead><TableHead className="text-right">{t('Balance')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow className="bg-muted/50"><TableCell colSpan={5} className="font-semibold">{t('Opening balance')}</TableCell><TableCell className="text-right font-semibold">{money(data.opening)}</TableCell></TableRow>
                                        {data.lines.map((l: any, i: number) => (
                                            <TableRow key={i}>
                                                <TableCell>{l.date}</TableCell><TableCell>{l.number}</TableCell><TableCell>{l.description}</TableCell>
                                                <TableCell className="text-right">{l.debit > 0 ? money(l.debit) : ''}</TableCell>
                                                <TableCell className="text-right">{l.credit > 0 ? money(l.credit) : ''}</TableCell>
                                                <TableCell className="text-right">{money(l.balance)}</TableCell>
                                            </TableRow>
                                        ))}
                                        <TableRow className="font-semibold"><TableCell colSpan={5}>{t('Closing balance')}</TableCell><TableCell className="text-right">{money(data.closing)}</TableCell></TableRow>
                                    </TableBody>
                                </Table>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
