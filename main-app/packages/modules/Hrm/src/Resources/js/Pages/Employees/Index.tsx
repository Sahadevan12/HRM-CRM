import Pagination from '@/Components/Pagination';
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
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Paginated } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Eye, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

interface EmployeeRow {
    id: number;
    employee_code: string;
    status: string;
    employment_type: string;
    date_of_joining: string | null;
    phone: string | null;
    user: { id: number; name: string; email: string; is_enable_login: boolean };
    branch: { name: string } | null;
    department: { name: string } | null;
    designation: { name: string } | null;
}

interface Props {
    employees: Paginated<EmployeeRow>;
    branches: { id: number; name: string }[];
    departments: { id: number; name: string }[];
    statuses: string[];
    filters: { search?: string; branch?: string; department?: string; status?: string };
}

const STATUS_VARIANT: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = { active: 'default', inactive: 'secondary', resigned: 'outline', terminated: 'destructive' };
const ALL = 'all';

export default function EmployeesIndex({ employees, branches, departments, statuses, filters }: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user.permissions?.includes(p);

    const [search, setSearch] = useState(filters.search ?? '');
    const [deleting, setDeleting] = useState<EmployeeRow | null>(null);

    const apply = (next: Record<string, string | undefined> = {}) =>
        router.get(route('hrm.employees.index'), { search, ...filters, ...next }, { preserveState: true, replace: true });
    const pick = (key: string) => (v: string) => apply({ [key]: v === ALL ? undefined : v });

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Employees')}</h2>}>
            <Head title={t('Employees')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap gap-2">
                        <form onSubmit={(e) => { e.preventDefault(); apply({ search }); }} className="flex gap-2">
                            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search name, email, code...')} className="w-60" />
                            <Button type="submit" variant="outline">{t('Search')}</Button>
                        </form>
                        <Select value={filters.branch ?? ALL} onValueChange={pick('branch')}>
                            <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
                            <SelectContent><SelectItem value={ALL}>{t('All branches')}</SelectItem>{branches.map((b) => <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>)}</SelectContent>
                        </Select>
                        <Select value={filters.department ?? ALL} onValueChange={pick('department')}>
                            <SelectTrigger className="w-44"><SelectValue /></SelectTrigger>
                            <SelectContent><SelectItem value={ALL}>{t('All departments')}</SelectItem>{departments.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}</SelectContent>
                        </Select>
                        <Select value={filters.status ?? ALL} onValueChange={pick('status')}>
                            <SelectTrigger className="w-36"><SelectValue /></SelectTrigger>
                            <SelectContent><SelectItem value={ALL}>{t('All statuses')}</SelectItem>{statuses.map((s) => <SelectItem key={s} value={s} className="capitalize">{t(s)}</SelectItem>)}</SelectContent>
                        </Select>
                    </div>
                    {can('create-employees') && <Button asChild><Link href={route('hrm.employees.create')}><Plus className="mr-1 h-4 w-4" /> {t('Add Employee')}</Link></Button>}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Code')}</TableHead>
                                    <TableHead>{t('Employee')}</TableHead>
                                    <TableHead>{t('Department')}</TableHead>
                                    <TableHead>{t('Joined')}</TableHead>
                                    <TableHead>{t('Status')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {employees.data.length === 0 && <TableRow><TableCell colSpan={6} className="py-8 text-center text-muted-foreground">{t('No employees found.')}</TableCell></TableRow>}
                                {employees.data.map((e) => (
                                    <TableRow key={e.id}>
                                        <TableCell className="font-mono">{e.employee_code}</TableCell>
                                        <TableCell>
                                            <div className="font-medium">{e.user.name}</div>
                                            <div className="text-xs text-muted-foreground">{e.user.email}</div>
                                        </TableCell>
                                        <TableCell>
                                            <div>{e.department?.name ?? '—'}</div>
                                            <div className="text-xs text-muted-foreground">{[e.designation?.name, e.branch?.name].filter(Boolean).join(' · ')}</div>
                                        </TableCell>
                                        <TableCell>{e.date_of_joining ?? '—'}</TableCell>
                                        <TableCell>
                                            <Badge variant={STATUS_VARIANT[e.status] ?? 'secondary'} className="capitalize">{t(e.status)}</Badge>
                                            {!e.user.is_enable_login && <Badge variant="outline" className="ml-1">{t('No login')}</Badge>}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button asChild size="icon" variant="ghost"><Link href={route('hrm.employees.show', e.id)}><Eye className="h-4 w-4" /></Link></Button>
                                            {can('edit-employees') && <Button asChild size="icon" variant="ghost"><Link href={route('hrm.employees.edit', e.id)}><Pencil className="h-4 w-4" /></Link></Button>}
                                            {can('delete-employees') && <Button size="icon" variant="ghost" onClick={() => setDeleting(e)}><Trash2 className="h-4 w-4 text-destructive" /></Button>}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={employees} />
            </div>

            <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete employee?')}</AlertDialogTitle>
                        <AlertDialogDescription>{deleting?.user.name} – {t('the login and all uploaded documents are removed too.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('hrm.employees.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
