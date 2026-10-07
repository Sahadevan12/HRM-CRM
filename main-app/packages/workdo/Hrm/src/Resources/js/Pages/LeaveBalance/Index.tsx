import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';

interface Balance {
    type_id: number;
    name: string;
    is_paid: boolean;
    allowance: number;
    used: number;
    pending: number;
    remaining: number | null;
}

interface Props {
    balances: Balance[];
    employee: { id: number; name: string; code: string } | null;
    employees: { id: number; code: string; name: string }[];
    year: number;
}

export default function LeaveBalance({ balances, employee, employees, year }: Props) {
    const { t } = useTranslation();

    const go = (params: Record<string, string | number>) => router.get(route('hrm.leave-applications.balance'), params, { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Leave Balance')}</h2>}>
            <Head title={t('Leave Balance')} />

            <div className="mx-auto max-w-4xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-end gap-3">
                    {employees.length > 0 && (
                        <div className="space-y-1">
                            <Label>{t('Employee')}</Label>
                            <Select value={employee ? String(employee.id) : ''} onValueChange={(v) => go({ employee: v, year })}>
                                <SelectTrigger className="w-64"><SelectValue placeholder={t('Select employee')} /></SelectTrigger>
                                <SelectContent>{employees.map((e) => <SelectItem key={e.id} value={String(e.id)}>{e.code} · {e.name}</SelectItem>)}</SelectContent>
                            </Select>
                        </div>
                    )}
                    <div className="space-y-1">
                        <Label>{t('Year')}</Label>
                        <Input type="number" className="w-28" defaultValue={year} onBlur={(e) => go({ ...(employee ? { employee: employee.id } : {}), year: e.target.value })} />
                    </div>
                </div>

                {!employee ? (
                    <Card><CardContent className="py-8 text-center text-muted-foreground">{t('Select an employee to see the leave balance.')}</CardContent></Card>
                ) : (
                    <Card>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('Leave Type')}</TableHead>
                                        <TableHead>{t('Allowance')}</TableHead>
                                        <TableHead>{t('Used')}</TableHead>
                                        <TableHead>{t('Pending')}</TableHead>
                                        <TableHead>{t('Remaining')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {balances.length === 0 && <TableRow><TableCell colSpan={5} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell></TableRow>}
                                    {balances.map((b) => (
                                        <TableRow key={b.type_id}>
                                            <TableCell>{b.name} {!b.is_paid && <span className="text-xs text-muted-foreground">({t('unpaid')})</span>}</TableCell>
                                            <TableCell>{b.allowance > 0 ? b.allowance : t('Unlimited')}</TableCell>
                                            <TableCell>{b.used}</TableCell>
                                            <TableCell>{b.pending}</TableCell>
                                            <TableCell>{b.remaining ?? '-'}</TableCell>
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
