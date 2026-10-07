import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useTranslation } from 'react-i18next';

interface Line {
    id: number;
    kind: string;
    label: string;
    amount: number;
}

interface Props {
    company: string;
    payslip: {
        working_days: number;
        unpaid_days: number;
        earnings: number;
        deductions: number;
        net_pay: number;
        payroll: { month: string; status: string; paid_on: string | null };
        employee: { employee_code: string; user: { name: string }; designation: { name: string } | null; department: { name: string } | null };
        lines: Line[];
    };
}

const EARNING = ['basic', 'allowance', 'overtime'];

export default function PayslipShow({ payslip, company }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const earnings = payslip.lines.filter((l) => EARNING.includes(l.kind));
    const deductions = payslip.lines.filter((l) => !EARNING.includes(l.kind));

    const section = (title: string, lines: Line[], total: number) => (
        <div>
            <h3 className="mb-2 text-sm font-semibold uppercase text-muted-foreground">{title}</h3>
            {lines.map((l) => (
                <div key={l.id} className="flex justify-between border-b py-1.5 text-sm"><span>{l.label}</span><span>{money(l.amount)}</span></div>
            ))}
            <div className="flex justify-between py-2 font-semibold"><span>{t('Total')}</span><span>{money(total)}</span></div>
        </div>
    );

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold print:hidden">{t('Payslip')}</h2>}>
            <Head title={`${t('Payslip')} ${payslip.payroll.month}`} />

            <div className="mx-auto max-w-3xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex justify-end print:hidden">
                    <Button variant="outline" onClick={() => window.print()}><Printer className="mr-1 h-4 w-4" />{t('Print')}</Button>
                </div>
                <Card>
                    <CardContent className="space-y-6 p-6">
                        <div className="flex flex-wrap justify-between gap-2">
                            <div>
                                <div className="text-lg font-semibold">{company}</div>
                                <div className="text-sm text-muted-foreground">{t('Payslip for')} {payslip.payroll.month}</div>
                            </div>
                            <div className="text-right text-sm">
                                <div className="font-medium">{payslip.employee.user.name} ({payslip.employee.employee_code})</div>
                                <div className="text-muted-foreground">{[payslip.employee.designation?.name, payslip.employee.department?.name].filter(Boolean).join(' · ')}</div>
                                <div className="text-muted-foreground">{t('Working days')}: {payslip.working_days} · {t('Unpaid days')}: {payslip.unpaid_days}</div>
                            </div>
                        </div>

                        <div className="grid gap-6 sm:grid-cols-2">
                            {section(t('Earnings'), earnings, payslip.earnings)}
                            {section(t('Deductions'), deductions, payslip.deductions)}
                        </div>

                        <div className="flex justify-between rounded-md bg-muted p-3 text-lg font-semibold">
                            <span>{t('Net Pay')}</span>
                            <span>{money(payslip.net_pay)}</span>
                        </div>
                        {payslip.payroll.paid_on && <p className="text-xs text-muted-foreground">{t('Paid on')} {payslip.payroll.paid_on}</p>}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
