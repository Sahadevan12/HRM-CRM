import Pagination from '@/Components/Pagination';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, X } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Component {
    id: number;
    name: string;
    type: 'allowance' | 'deduction';
    calc: 'fixed' | 'percent';
    amount: number;
}

interface EmployeeRow {
    id: number;
    employee_code: string;
    basic_salary: number;
    hourly_rate: number | null;
    user: { name: string };
    salary_components: { salary_component_id: number; value: number }[];
}

interface Props {
    employees: Paginated<EmployeeRow>;
    components: Component[];
    filters: { search?: string };
}

interface Row {
    salary_component_id: string;
    value: string;
}

export default function SalarySetup({ employees, components, filters }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const [search, setSearch] = useState(filters.search ?? '');
    const [editing, setEditing] = useState<EmployeeRow | null>(null);
    const form = useForm<{ basic_salary: string; hourly_rate: string; components: Row[] }>({ basic_salary: '', hourly_rate: '', components: [] });

    const byId = (id: number | string) => components.find((c) => String(c.id) === String(id));

    const open = (e: EmployeeRow) => {
        setEditing(e);
        form.setData({
            basic_salary: String(e.basic_salary),
            hourly_rate: e.hourly_rate ? String(e.hourly_rate) : '',
            components: e.salary_components.map((c) => ({ salary_component_id: String(c.salary_component_id), value: String(c.value) })),
        });
        form.clearErrors();
    };

    const setRow = (i: number, patch: Partial<Row>) => form.setData('components', form.data.components.map((r, n) => (n === i ? { ...r, ...patch } : r)));

    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        if (editing) form.put(route('hrm.salary-setup.update', editing.id), { preserveScroll: true, onSuccess: () => setEditing(null) });
    };

    const used = new Set(form.data.components.map((c) => c.salary_component_id));
    const errorFor = (k: string) => (form.errors as Record<string, string>)[k];

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Salary Setup')}</h2>}>
            <Head title={t('Salary Setup')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <form onSubmit={(e) => { e.preventDefault(); router.get(route('hrm.salary-setup.index'), { search }, { preserveState: true, replace: true }); }} className="flex gap-2">
                    <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search...')} className="w-64" />
                    <Button type="submit" variant="outline">{t('Search')}</Button>
                </form>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Employee')}</TableHead>
                                    <TableHead>{t('Basic Salary')}</TableHead>
                                    <TableHead>{t('Hourly rate')}</TableHead>
                                    <TableHead>{t('Allowances & Deductions')}</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {employees.data.length === 0 && <TableRow><TableCell colSpan={5} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell></TableRow>}
                                {employees.data.map((e) => (
                                    <TableRow key={e.id}>
                                        <TableCell>{e.employee_code} · {e.user.name}</TableCell>
                                        <TableCell>{money(e.basic_salary)}</TableCell>
                                        <TableCell>{e.hourly_rate ? money(e.hourly_rate) : '-'}</TableCell>
                                        <TableCell className="text-sm text-muted-foreground">{e.salary_components.map((c) => byId(c.salary_component_id)?.name).filter(Boolean).join(', ') || '-'}</TableCell>
                                        <TableCell className="text-right"><Button size="icon" variant="ghost" onClick={() => open(e)}><Pencil className="h-4 w-4" /></Button></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={employees} />
            </div>

            <Dialog open={!!editing} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent className="max-w-xl">
                    <DialogHeader><DialogTitle>{editing?.user.name}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label>{t('Basic Salary')} ({t('per month')})</Label>
                                <Input type="number" step="0.01" value={form.data.basic_salary} onChange={(e) => form.setData('basic_salary', e.target.value)} />
                                {form.errors.basic_salary && <p className="text-sm text-destructive">{form.errors.basic_salary}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label>{t('Hourly rate')} ({t('overtime')})</Label>
                                <Input type="number" step="0.01" value={form.data.hourly_rate} onChange={(e) => form.setData('hourly_rate', e.target.value)} />
                                {form.errors.hourly_rate && <p className="text-sm text-destructive">{form.errors.hourly_rate}</p>}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label>{t('Allowances & Deductions')}</Label>
                            {form.data.components.map((row, i) => {
                                const c = byId(row.salary_component_id);
                                return (
                                    <div key={i} className="space-y-1">
                                        <div className="flex items-center gap-2">
                                            <Select value={row.salary_component_id} onValueChange={(v) => setRow(i, { salary_component_id: v, value: String(byId(v)?.amount ?? '') })}>
                                                <SelectTrigger className="flex-1"><SelectValue /></SelectTrigger>
                                                <SelectContent>
                                                    {components.filter((x) => String(x.id) === row.salary_component_id || !used.has(String(x.id))).map((x) => (
                                                        <SelectItem key={x.id} value={String(x.id)}>{x.name} ({t(x.type === 'allowance' ? 'Allowance' : 'Deduction')})</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <Input className="w-28" type="number" step="0.01" value={row.value} onChange={(e) => setRow(i, { value: e.target.value })} />
                                            <span className="w-4 text-sm text-muted-foreground">{c?.calc === 'percent' ? '%' : ''}</span>
                                            <Button type="button" size="icon" variant="ghost" onClick={() => form.setData('components', form.data.components.filter((_, n) => n !== i))}><X className="h-4 w-4" /></Button>
                                        </div>
                                        {(errorFor(`components.${i}.value`) || errorFor(`components.${i}.salary_component_id`)) && <p className="text-sm text-destructive">{errorFor(`components.${i}.value`) ?? errorFor(`components.${i}.salary_component_id`)}</p>}
                                    </div>
                                );
                            })}
                            <Button type="button" variant="outline" size="sm" disabled={used.size >= components.length} onClick={() => form.setData('components', [...form.data.components, { salary_component_id: '', value: '' }])}>
                                <Plus className="mr-1 h-4 w-4" />{t('Add')}
                            </Button>
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setEditing(null)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{t('Save')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
