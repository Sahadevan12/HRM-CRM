import Pagination from '@/Components/Pagination';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Paginated } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

interface Row {
    id: number;
    month: string;
    status: 'draft' | 'approved' | 'paid';
    payslips_count: number;
    total_earnings: number;
    total_deductions: number;
    total_net: number;
    paid_on: string | null;
}

interface Props {
    payrolls: Paginated<Row>;
    suggestedMonth: string;
    accountingActive: boolean;
}

const tone = { draft: 'secondary', approved: 'outline', paid: 'default' } as const;

export default function PayrollsIndex({ payrolls, suggestedMonth, accountingActive }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const { auth } = usePage<PageProps>().props;
    const form = useForm({ month: suggestedMonth });

    const generate = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('hrm.payrolls.store'));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Payrolls')}</h2>}>
            <Head title={t('Payrolls')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                {auth.user.permissions?.includes('create-payrolls') && (
                    <form onSubmit={generate} className="flex flex-wrap items-end gap-2">
                        <div className="space-y-1">
                            <Label>{t('Month')}</Label>
                            <Input type="month" value={form.data.month} onChange={(e) => form.setData('month', e.target.value)} />
                            {form.errors.month && <p className="text-sm text-destructive">{form.errors.month}</p>}
                        </div>
                        <Button type="submit" disabled={form.processing}>{t('Generate Payroll')}</Button>
                    </form>
                )}
                {!accountingActive && <p className="text-xs text-muted-foreground">{t('Accounting is not active: paying a payroll will not create journal entries.')}</p>}

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Month')}</TableHead>
                                    <TableHead>{t('Employees')}</TableHead>
                                    <TableHead>{t('Earnings')}</TableHead>
                                    <TableHead>{t('Deductions')}</TableHead>
                                    <TableHead>{t('Net Pay')}</TableHead>
                                    <TableHead>{t('Status')}</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {payrolls.data.length === 0 && <TableRow><TableCell colSpan={7} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell></TableRow>}
                                {payrolls.data.map((p) => (
                                    <TableRow key={p.id}>
                                        <TableCell>{p.month}</TableCell>
                                        <TableCell>{p.payslips_count}</TableCell>
                                        <TableCell>{money(p.total_earnings)}</TableCell>
                                        <TableCell>{money(p.total_deductions)}</TableCell>
                                        <TableCell className="font-medium">{money(p.total_net)}</TableCell>
                                        <TableCell><Badge variant={tone[p.status]}>{t(p.status)}</Badge></TableCell>
                                        <TableCell className="text-right"><Button size="sm" variant="outline" asChild><Link href={route('hrm.payrolls.show', p.id)}>{t('Open')}</Link></Button></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={payrolls} />
            </div>
        </AuthenticatedLayout>
    );
}
