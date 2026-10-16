import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { LogIn, LogOut } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Row {
    id: number;
    date: string;
    in_time: string | null;
    out_time: string | null;
    total_hours: number;
    overtime_hours: number;
    late_minutes: number;
    status: string;
}

interface Props {
    employee: { id: number; shift?: { name: string; start_time: string; end_time: string } | null } | null;
    open: Row | null;
    recent: Row[];
    tz: string;
}

const time = (v: string | null) => (v ? v.slice(0, 5) : '-');

export default function MyAttendance({ employee, open, recent, tz }: Props) {
    const { t } = useTranslation();
    const [busy, setBusy] = useState(false);

    const post = (name: string) => router.post(route(name), {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) });

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('My Attendance')}</h2>}>
            <Head title={t('My Attendance')} />

            <div className="mx-auto max-w-4xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                {!employee ? (
                    <Card><CardContent className="py-8 text-center text-muted-foreground">{t('Your login has no employee profile. Ask HR to create one.')}</CardContent></Card>
                ) : (
                    <>
                        <Card>
                            <CardHeader><CardTitle>{open ? t('You are clocked in') : t('You are clocked out')}</CardTitle></CardHeader>
                            <CardContent className="flex flex-wrap items-center justify-between gap-4">
                                <div className="text-sm text-muted-foreground">
                                    {employee.shift ? `${employee.shift.name}: ${time(employee.shift.start_time)} - ${time(employee.shift.end_time)}` : t('Default shift (8 hours)')}
                                    {' · '}{tz}
                                    {open && <div>{t('Since')} {time(open.in_time)}</div>}
                                </div>
                                {open ? (
                                    <Button onClick={() => post('hrm.attendances.clock-out')} disabled={busy} variant="destructive"><LogOut className="mr-1 h-4 w-4" />{t('Clock Out')}</Button>
                                ) : (
                                    <Button onClick={() => post('hrm.attendances.clock-in')} disabled={busy}><LogIn className="mr-1 h-4 w-4" />{t('Clock In')}</Button>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardContent className="p-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>{t('Date')}</TableHead>
                                            <TableHead>{t('In')}</TableHead>
                                            <TableHead>{t('Out')}</TableHead>
                                            <TableHead>{t('Hours')}</TableHead>
                                            <TableHead>{t('Overtime')}</TableHead>
                                            <TableHead>{t('Late (min)')}</TableHead>
                                            <TableHead>{t('Status')}</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {recent.length === 0 && <TableRow><TableCell colSpan={7} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell></TableRow>}
                                        {recent.map((r) => (
                                            <TableRow key={r.id}>
                                                <TableCell>{r.date.slice(0, 10)}</TableCell>
                                                <TableCell>{time(r.in_time)}</TableCell>
                                                <TableCell>{time(r.out_time)}</TableCell>
                                                <TableCell>{r.total_hours}</TableCell>
                                                <TableCell>{r.overtime_hours}</TableCell>
                                                <TableCell>{r.late_minutes}</TableCell>
                                                <TableCell><Badge variant="outline">{t(r.status)}</Badge></TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
