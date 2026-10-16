import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

interface Slip {
    id: number;
    earnings: number;
    deductions: number;
    net_pay: number;
    payroll: { month: string; status: string; paid_on: string | null };
}

export default function MyPayslips({ payslips, hasProfile }: { payslips: Slip[]; hasProfile: boolean }) {
    const { t } = useTranslation();
    const money = useMoney();

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('My Payslips')}</h2>}>
            <Head title={t('My Payslips')} />

            <div className="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
                {!hasProfile ? (
                    <Card><CardContent className="py-8 text-center text-muted-foreground">{t('Your login has no employee profile. Ask HR to create one.')}</CardContent></Card>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('Month')}</TableHead>
                                        <TableHead>{t('Earnings')}</TableHead>
                                        <TableHead>{t('Deductions')}</TableHead>
                                        <TableHead>{t('Net Pay')}</TableHead>
                                        <TableHead>{t('Status')}</TableHead>
                                        <TableHead />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {payslips.length === 0 && <TableRow><TableCell colSpan={6} className="py-8 text-center text-muted-foreground">{t('No payslips yet.')}</TableCell></TableRow>}
                                    {payslips.map((s) => (
                                        <TableRow key={s.id}>
                                            <TableCell>{s.payroll.month}</TableCell>
                                            <TableCell>{money(s.earnings)}</TableCell>
                                            <TableCell>{money(s.deductions)}</TableCell>
                                            <TableCell className="font-medium">{money(s.net_pay)}</TableCell>
                                            <TableCell><Badge variant="outline">{t(s.payroll.status)}</Badge></TableCell>
                                            <TableCell className="text-right"><Button size="sm" variant="outline" asChild><Link href={route('hrm.payslips.show', s.id)}>{t('View')}</Link></Button></TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
