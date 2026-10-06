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
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Paginated } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Ban, CheckCircle, Package, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Company {
    id: number;
    name: string;
    email: string;
    plan_id: number | null;
    plan_name: string | null;
    plan_expire_date: string | null;
    trial_expire_date: string | null;
    expired: boolean;
    is_enable_login: boolean;
    members_count: number;
    total_user: number;
    created_at: string;
}

interface Props {
    companies: Paginated<Company>;
    plans: { id: number; name: string; free_plan: boolean; trial: boolean; is_disable: boolean }[];
    filters: { search?: string };
}

export default function CompaniesIndex({ companies, plans, filters }: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user.permissions?.includes(p);

    const [search, setSearch] = useState(filters.search ?? '');
    const [editing, setEditing] = useState<Company | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [planFor, setPlanFor] = useState<Company | null>(null);
    const [deleting, setDeleting] = useState<Company | null>(null);

    const form = useForm({ name: '', email: '', password: '' });
    const planForm = useForm({ plan_id: '' as string, duration: 'month' });

    const openCreate = () => {
        setEditing(null);
        form.setData({ name: '', email: '', password: '' });
        form.clearErrors();
        setFormOpen(true);
    };

    const openEdit = (c: Company) => {
        setEditing(c);
        form.setData({ name: c.name, email: c.email, password: '' });
        form.clearErrors();
        setFormOpen(true);
    };

    const openPlan = (c: Company) => {
        setPlanFor(c);
        planForm.setData({ plan_id: c.plan_id ? String(c.plan_id) : '', duration: 'month' });
        planForm.clearErrors();
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setFormOpen(false) };
        if (editing) form.put(route('companies.update', editing.id), options);
        else form.post(route('companies.store'), options);
    };

    const submitPlan = (e: FormEvent) => {
        e.preventDefault();
        if (!planFor) return;
        planForm.post(route('companies.assign-plan', planFor.id), { preserveScroll: true, onSuccess: () => setPlanFor(null) });
    };

    const selectedPlan = plans.find((p) => String(p.id) === planForm.data.plan_id);

    const planCell = (c: Company) => {
        const date = c.plan_expire_date ?? c.trial_expire_date;
        return (
            <>
                <div className="font-medium">{c.plan_name ?? t('No plan')}</div>
                <div className="text-xs text-muted-foreground">
                    {c.expired ? <span className="text-destructive">{t('Expired')}</span> : date ? `${c.plan_expire_date ? '' : t('Trial') + ' · '}${date}` : t('No expiry')}
                </div>
            </>
        );
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Companies')}</h2>}>
            <Head title={t('Companies')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(route('companies.index'), { search }, { preserveState: true, replace: true });
                        }}
                        className="flex gap-2"
                    >
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search...')} className="w-64" />
                        <Button type="submit" variant="outline">{t('Search')}</Button>
                    </form>
                    {can('create-companies') && (
                        <Button onClick={openCreate}><Plus className="mr-1 h-4 w-4" /> {t('Add Company')}</Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Company')}</TableHead>
                                    <TableHead>{t('Plan')}</TableHead>
                                    <TableHead>{t('Users')}</TableHead>
                                    <TableHead>{t('Login')}</TableHead>
                                    <TableHead>{t('Joined')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {companies.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">{t('No companies found.')}</TableCell>
                                    </TableRow>
                                )}
                                {companies.data.map((c) => (
                                    <TableRow key={c.id}>
                                        <TableCell>
                                            <div className="font-medium">{c.name}</div>
                                            <div className="text-xs text-muted-foreground">{c.email}</div>
                                        </TableCell>
                                        <TableCell>{planCell(c)}</TableCell>
                                        <TableCell>{c.members_count} / {c.total_user === -1 ? '∞' : c.total_user}</TableCell>
                                        <TableCell>
                                            <Badge variant={c.is_enable_login ? 'default' : 'destructive'}>{c.is_enable_login ? t('Enabled') : t('Disabled')}</Badge>
                                        </TableCell>
                                        <TableCell>{c.created_at}</TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-companies') && (
                                                <>
                                                    <Button size="icon" variant="ghost" title={t('Assign plan')} onClick={() => openPlan(c)}><Package className="h-4 w-4" /></Button>
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        title={c.is_enable_login ? t('Disable login') : t('Enable login')}
                                                        onClick={() => router.post(route('companies.toggle-login', c.id), {}, { preserveScroll: true })}
                                                    >
                                                        {c.is_enable_login ? <Ban className="h-4 w-4" /> : <CheckCircle className="h-4 w-4 text-green-600" />}
                                                    </Button>
                                                    <Button size="icon" variant="ghost" onClick={() => openEdit(c)}><Pencil className="h-4 w-4" /></Button>
                                                </>
                                            )}
                                            {can('delete-companies') && (
                                                <Button size="icon" variant="ghost" onClick={() => setDeleting(c)}><Trash2 className="h-4 w-4 text-destructive" /></Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={companies} />
            </div>

            <Dialog open={formOpen} onOpenChange={setFormOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? t('Edit Company') : t('Add Company')}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1">
                            <Label htmlFor="name">{t('Name')}</Label>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            {form.errors.name && <p className="text-sm text-destructive">{form.errors.name}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                            {form.errors.email && <p className="text-sm text-destructive">{form.errors.email}</p>}
                        </div>
                        {!editing && (
                            <div className="space-y-1">
                                <Label htmlFor="password">{t('Password')}</Label>
                                <Input id="password" type="password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                                {form.errors.password && <p className="text-sm text-destructive">{form.errors.password}</p>}
                            </div>
                        )}
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setFormOpen(false)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{editing ? t('Update') : t('Create')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={!!planFor} onOpenChange={(o) => !o && setPlanFor(null)}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('Assign plan')} – {planFor?.name}</DialogTitle></DialogHeader>
                    <form onSubmit={submitPlan} className="space-y-4">
                        <div className="space-y-1">
                            <Label>{t('Plan')}</Label>
                            <Select value={planForm.data.plan_id} onValueChange={(v) => planForm.setData('plan_id', v)}>
                                <SelectTrigger><SelectValue placeholder={t('Choose a plan')} /></SelectTrigger>
                                <SelectContent>
                                    {plans.map((p) => (
                                        <SelectItem key={p.id} value={String(p.id)}>{p.name}{p.is_disable ? ` (${t('disabled')})` : ''}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {planForm.errors.plan_id && <p className="text-sm text-destructive">{planForm.errors.plan_id}</p>}
                        </div>
                        {selectedPlan && !selectedPlan.free_plan && (
                            <div className="space-y-1">
                                <Label>{t('Duration')}</Label>
                                <Select value={planForm.data.duration} onValueChange={(v) => planForm.setData('duration', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="month">{t('1 month')}</SelectItem>
                                        <SelectItem value="year">{t('1 year')}</SelectItem>
                                        {selectedPlan.trial && <SelectItem value="trial">{t('Trial')}</SelectItem>}
                                        <SelectItem value="lifetime">{t('Lifetime (no expiry)')}</SelectItem>
                                    </SelectContent>
                                </Select>
                                {planForm.errors.duration && <p className="text-sm text-destructive">{planForm.errors.duration}</p>}
                            </div>
                        )}
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setPlanFor(null)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={planForm.processing || !planForm.data.plan_id}>{t('Assign')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete company?')}</AlertDialogTitle>
                        <AlertDialogDescription>
                            {deleting?.name} {t('and all of its users and data will be permanently removed.')}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('companies.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>
                            {t('Delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
