import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Row {
    id: number;
    code: string;
    name: string;
    present: number;
    half_day: number;
    absent: number;
    leave_paid: number;
    leave_unpaid: number;
    late_count: number;
    late_minutes: number;
    worked_hours: number;
    overtime_hours: number;
}

interface Props {
    rows: Row[];
    month: string;
    workingDays: number;
    holidays: { name: string; start_date: string; end_date: string }[];
    departments: { id: number; name: string }[];
    filters: { department?: string };
}

const ALL = 'all';

export default function AttendanceSummary({ rows, month, workingDays, holidays, departments, filters }: Props) {
    const { t } = useTranslation();
    const [m, setM] = useState(month);
    const [dept, setDept] = useState(filters.department ?? ALL);

    const apply = () => router.get(route('hrm.attendances.summary'), { month: m, ...(dept !== ALL ? { department: dept } : {}) }, { preserveState: true, replace: true });

    const cols: [keyof Row, string][] = [
        ['present', 'Present'], ['half_day', 'Half Day'], ['absent', 'Absent'], ['leave_paid', 'Paid Leave'], ['leave_unpaid', 'Unpaid Leave'],
        ['late_count', 'Late'], ['worked_hours', 'Hours'], ['overtime_hours', 'Overtime'],
    ];

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Monthly Summary')}</h2>}>
            <Head title={t('Monthly Summary')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="flex flex-wrap items-end gap-2">
                        <div className="space-y-1"><Label>{t('Month')}</Label><Input type="month" value={m} onChange={(e) => setM(e.target.value)} /></div>
                        <Select value={dept} onValueChange={setDept}>
                            <SelectTrigger className="w-48"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>{t('All departments')}</SelectItem>
                                {departments.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}
                            </SelectContent>
                        </Select>
                        <Button variant="outline" onClick={apply}>{t('Filter')}</Button>
                    </div>
                    <Button variant="outline" asChild><Link href={route('hrm.attendances.index')}>{t('Attendance Records')}</Link></Button>
                </div>

                <p className="text-sm text-muted-foreground">
                    {t('Working days')}: <b>{workingDays}</b>
                    {holidays.length > 0 && <> · {t('Holidays')}: {holidays.map((h) => h.name).join(', ')}</>}
                </p>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Employee')}</TableHead>
                                    {cols.map(([, label]) => <TableHead key={label}>{t(label)}</TableHead>)}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 && <TableRow><TableCell colSpan={cols.length + 1} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell></TableRow>}
                                {rows.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell>{r.code} · {r.name}</TableCell>
                                        {cols.map(([k]) => <TableCell key={k}>{r[k]}</TableCell>)}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
