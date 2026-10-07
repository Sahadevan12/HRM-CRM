import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

interface Option { id: number; name: string }

interface Props {
    employee: Record<string, any> | null;
    role?: string | null;
    nextCode: string | null;
    branches: Option[];
    departments: (Option & { branch_id: number | null })[];
    designations: (Option & { department_id: number | null })[];
    roles: { name: string; label: string }[];
    options: { genders: string[]; employmentTypes: string[]; statuses: string[] };
}

const NONE = 'none';
const text = (v: unknown) => (v === null || v === undefined ? '' : String(v));
const choice = (v: unknown) => (v ? String(v) : NONE);

function Field({ label, error, children, className }: { label: string; error?: string; children: ReactNode; className?: string }) {
    return (
        <div className={`space-y-1 ${className ?? ''}`}>
            <Label>{label}</Label>
            {children}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}

export default function EmployeeForm({ employee, role, nextCode, branches, departments, designations, roles, options }: Props) {
    const { t } = useTranslation();
    const e = employee;

    const form = useForm({
        name: text(e?.user?.name),
        email: text(e?.user?.email),
        password: '',
        role: role ?? 'staff',
        is_enable_login: e ? Boolean(e.user.is_enable_login) : true,
        employee_code: text(e?.employee_code),
        branch_id: choice(e?.branch_id),
        department_id: choice(e?.department_id),
        designation_id: choice(e?.designation_id),
        date_of_birth: text(e?.date_of_birth),
        gender: choice(e?.gender),
        date_of_joining: text(e?.date_of_joining),
        employment_type: text(e?.employment_type) || 'full_time',
        status: text(e?.status) || 'active',
        phone: text(e?.phone),
        address_line: text(e?.address_line),
        city: text(e?.city),
        state: text(e?.state),
        country: text(e?.country),
        postal_code: text(e?.postal_code),
        emergency_name: text(e?.emergency_name),
        emergency_relationship: text(e?.emergency_relationship),
        emergency_phone: text(e?.emergency_phone),
        bank_name: text(e?.bank_name),
        account_holder: text(e?.account_holder),
        account_number: text(e?.account_number),
        bank_code: text(e?.bank_code),
        tax_id: text(e?.tax_id),
        basic_salary: text(e?.basic_salary ?? 0),
        hourly_rate: text(e?.hourly_rate ?? 0),
        notes: text(e?.notes),
    });

    const set = (key: keyof typeof form.data) => (ev: { target: { value: string } }) => form.setData(key, ev.target.value as never);
    const err = (key: string) => (form.errors as Record<string, string>)[key];
    const input = (key: keyof typeof form.data, type = 'text') => <Input type={type} value={form.data[key] as string} onChange={set(key)} />;

    // a department can be company wide (no branch) or belong to the chosen branch; designations follow the department
    const branch = form.data.branch_id;
    const visibleDepartments = departments.filter((d) => branch === NONE || d.branch_id === null || String(d.branch_id) === branch);
    const dept = form.data.department_id;
    const visibleDesignations = designations.filter((d) => dept === NONE || d.department_id === null || String(d.department_id) === dept);

    const dropdown = (key: 'branch_id' | 'department_id' | 'designation_id', label: string, items: Option[]) => (
        <Field label={label} error={err(key)}>
            <Select value={form.data[key]} onValueChange={(v) => form.setData(key, v)}>
                <SelectTrigger><SelectValue /></SelectTrigger>
                <SelectContent>
                    <SelectItem value={NONE}>{t('None')}</SelectItem>
                    {items.map((o) => <SelectItem key={o.id} value={String(o.id)}>{o.name}</SelectItem>)}
                </SelectContent>
            </Select>
        </Field>
    );

    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        form.transform((d) => ({
            ...d,
            branch_id: d.branch_id === NONE ? null : d.branch_id,
            department_id: d.department_id === NONE ? null : d.department_id,
            designation_id: d.designation_id === NONE ? null : d.designation_id,
            gender: d.gender === NONE ? null : d.gender,
        }));
        if (e) form.put(route('hrm.employees.update', e.id));
        else form.post(route('hrm.employees.store'));
    };

    const title = e ? `${t('Edit Employee')} – ${e.employee_code}` : t('Add Employee');

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{title}</h2>}>
            <Head title={title} />

            <form onSubmit={submit} className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <Card>
                    <CardHeader><CardTitle>{t('Login')}</CardTitle></CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <Field label={t('Full name')} error={err('name')}>{input('name')}</Field>
                        <Field label="Email" error={err('email')}>{input('email', 'email')}</Field>
                        <Field label={e ? t('New password (leave empty to keep)') : t('Password')} error={err('password')}>{input('password', 'password')}</Field>
                        <Field label={t('Role')} error={err('role')}>
                            <Select value={form.data.role} onValueChange={(v) => form.setData('role', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{roles.map((r) => <SelectItem key={r.name} value={r.name}>{r.label}</SelectItem>)}</SelectContent>
                            </Select>
                        </Field>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2">
                            <Checkbox checked={form.data.is_enable_login} onCheckedChange={(c) => form.setData('is_enable_login', c === true)} /> {t('Can log in')}
                        </label>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>{t('Job')}</CardTitle></CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-3">
                        <Field label={t('Employee code')} error={err('employee_code')}>
                            <Input value={form.data.employee_code} placeholder={nextCode ?? ''} onChange={set('employee_code')} />
                        </Field>
                        {dropdown('branch_id', t('Branch'), branches)}
                        {dropdown('department_id', t('Department'), visibleDepartments)}
                        {dropdown('designation_id', t('Designation'), visibleDesignations)}
                        <Field label={t('Date of joining')} error={err('date_of_joining')}>{input('date_of_joining', 'date')}</Field>
                        <Field label={t('Employment type')} error={err('employment_type')}>
                            <Select value={form.data.employment_type} onValueChange={(v) => form.setData('employment_type', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{options.employmentTypes.map((x) => <SelectItem key={x} value={x}>{t(x.replace('_', ' '))}</SelectItem>)}</SelectContent>
                            </Select>
                        </Field>
                        {e && (
                            <Field label={t('Status')} error={err('status')}>
                                <Select value={form.data.status} onValueChange={(v) => form.setData('status', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{options.statuses.map((x) => <SelectItem key={x} value={x} className="capitalize">{t(x)}</SelectItem>)}</SelectContent>
                                </Select>
                            </Field>
                        )}
                        <Field label={t('Basic salary (per month)')} error={err('basic_salary')}>{input('basic_salary', 'number')}</Field>
                        <Field label={t('Overtime rate (per hour)')} error={err('hourly_rate')}>{input('hourly_rate', 'number')}</Field>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>{t('Personal')}</CardTitle></CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-3">
                        <Field label={t('Date of birth')} error={err('date_of_birth')}>{input('date_of_birth', 'date')}</Field>
                        <Field label={t('Gender')} error={err('gender')}>
                            <Select value={form.data.gender} onValueChange={(v) => form.setData('gender', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent><SelectItem value={NONE}>{t('Not specified')}</SelectItem>{options.genders.map((g) => <SelectItem key={g} value={g} className="capitalize">{t(g)}</SelectItem>)}</SelectContent>
                            </Select>
                        </Field>
                        <Field label={t('Phone')} error={err('phone')}>{input('phone')}</Field>
                        <Field label={t('Address')} error={err('address_line')} className="sm:col-span-3">{input('address_line')}</Field>
                        <Field label={t('City')}>{input('city')}</Field>
                        <Field label={t('State')}>{input('state')}</Field>
                        <Field label={t('Country')}>{input('country')}</Field>
                        <Field label={t('Postal code')}>{input('postal_code')}</Field>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>{t('Emergency contact')}</CardTitle></CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-3">
                        <Field label={t('Name')}>{input('emergency_name')}</Field>
                        <Field label={t('Relationship')}>{input('emergency_relationship')}</Field>
                        <Field label={t('Phone')}>{input('emergency_phone')}</Field>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle>{t('Bank & tax')}</CardTitle></CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-3">
                        <Field label={t('Bank name')}>{input('bank_name')}</Field>
                        <Field label={t('Account holder')}>{input('account_holder')}</Field>
                        <Field label={t('Account number')}>{input('account_number')}</Field>
                        <Field label={t('Bank code (IFSC / SWIFT)')}>{input('bank_code')}</Field>
                        <Field label={t('Tax ID')}>{input('tax_id')}</Field>
                    </CardContent>
                </Card>

                <Field label={t('Notes')} error={err('notes')}>
                    <Textarea rows={3} value={form.data.notes} onChange={set('notes')} />
                </Field>

                <div className="flex justify-end gap-2">
                    <Button asChild variant="outline"><Link href={e ? route('hrm.employees.show', e.id) : route('hrm.employees.index')}>{t('Cancel')}</Link></Button>
                    <Button type="submit" disabled={form.processing}>{e ? t('Update') : t('Create')}</Button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
