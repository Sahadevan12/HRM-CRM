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
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Slip {
    id: number;
    working_days: number;
    unpaid_days: number;
    overtime_hours: number;
    earnings: number;
    deductions: number;
    net_pay: number;
    employee: { employee_code: string; user: { name: string } };
}

interface Props {
    payroll: {
        id: number;
        month: string;
        status: 'draft' | 'approved' | 'paid';
        total_earnings: number;
        total_deductions: number;
        total_net: number;
        paid_on: string | null;
        payslips: Slip[];
    };
    can: { create: boolean; approve: boolean; pay: boolean; delete: boolean };
    accountingActive: boolean;
}

export default function PayrollShow({ payroll, can, accountingActive }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const [paying, setPaying] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const form = useForm({ paid_on: new Date().toISOString().slice(0, 10), method: 'bank' });

    const post = (name: string) => router.post(route(name, payroll.id), {}, { preserveScroll: true });

    const regenerate = () => router.post(route('hrm.payrolls.store'), { month: payroll.month });

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Payroll')} {payroll.month}</h2>}>
            <Head title={`${t('Payroll')} ${payroll.month}`} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Badge>{t(payroll.status)}</Badge>
                        {payroll.paid_on && <span className="text-sm text-muted-foreground">{t('Paid on')} {payroll.paid_on}</span>}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild><Link href={route('hrm.payrolls.index')}>{t('Back')}</Link></Button>
                        {payroll.status === 'draft' && can.create && <Button variant="outline" onClick={regenerate}>{t('Regenerate')}</Button>}
                        {payroll.status === 'draft' && can.approve && <Button onClick={() => post('hrm.payrolls.approve')}>{t('Approve')}</Button>}
                        {payroll.status === 'approved' && can.approve && <Button variant="outline" onClick={() => post('hrm.payrolls.reopen')}>{t('Reopen')}</Button>}
                        {payroll.status === 'approved' && can.pay && <Button onClick={() => setPaying(true)}>{t('Pay')}</Button>}
                        {payroll.status === 'draft' && can.delete && <Button variant="destructive" onClick={() => setDeleting(true)}>{t('Delete')}</Button>}
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <Card><CardContent className="p-4"><div className="text-xs text-muted-foreground">{t('Earnings')}</div><div className="text-xl font-semibold">{money(payroll.total_earnings)}</div></CardContent></Card>
                    <Card><CardContent className="p-4"><div className="text-xs text-muted-foreground">{t('Deductions')}</div><div className="text-xl font-semibold">{money(payroll.total_deductions)}</div></CardContent></Card>
                    <Card><CardContent className="p-4"><div className="text-xs text-muted-foreground">{t('Net Pay')}</div><div className="text-xl font-semibold">{money(payroll.total_net)}</div></CardContent></Card>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Employee')}</TableHead>
                                    <TableHead>{t('Working days')}</TableHead>
                                    <TableHead>{t('Unpaid days')}</TableHead>
                                    <TableHead>{t('Overtime')}</TableHead>
                                    <TableHead>{t('Earnings')}</TableHead>
                                    <TableHead>{t('Deductions')}</TableHead>
                                    <TableHead>{t('Net Pay')}</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {payroll.payslips.map((s) => (
                                    <TableRow key={s.id}>
                                        <TableCell>{s.employee.employee_code} · {s.employee.user.name}</TableCell>
                                        <TableCell>{s.working_days}</TableCell>
                                        <TableCell>{s.unpaid_days}</TableCell>
                                        <TableCell>{s.overtime_hours} h</TableCell>
                                        <TableCell>{money(s.earnings)}</TableCell>
                                        <TableCell>{money(s.deductions)}</TableCell>
                                        <TableCell className="font-medium">{money(s.net_pay)}</TableCell>
                                        <TableCell className="text-right"><Button size="sm" variant="outline" asChild><Link href={route('hrm.payslips.show', s.id)}>{t('Payslip')}</Link></Button></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            <Dialog open={paying} onOpenChange={setPaying}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('Pay Payroll')} {payroll.month}</DialogTitle></DialogHeader>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(route('hrm.payrolls.pay', payroll.id), { preserveScroll: true, onSuccess: () => setPaying(false) });
                        }}
                        className="space-y-4"
                    >
                        <div className="space-y-1">
                            <Label>{t('Payment date')}</Label>
                            <Input type="date" value={form.data.paid_on} onChange={(e) => form.setData('paid_on', e.target.value)} />
                            {form.errors.paid_on && <p className="text-sm text-destructive">{form.errors.paid_on}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label>{t('Paid from')}</Label>
                            <Select value={form.data.method} onValueChange={(v) => form.setData('method', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="bank">{t('Bank')}</SelectItem>
                                    <SelectItem value="cash">{t('Cash')}</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {accountingActive ? t('A journal entry is booked: salary expense against cash / bank.') : t('Accounting is not active: nothing is booked.')} {t('A paid payroll cannot be changed.')}
                        </p>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setPaying(false)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{t('Confirm Payment')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={deleting} onOpenChange={setDeleting}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete this draft payroll?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('This action cannot be undone.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => router.delete(route('hrm.payrolls.destroy', payroll.id))}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
