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
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Paginated } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Lock, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Account {
    id: number;
    code: string;
    name: string;
    type: string;
    is_bank: boolean;
    is_system: boolean;
    is_active: boolean;
    description: string | null;
}

interface Props {
    accounts: Paginated<Account>;
    balances: Record<string, number>;
    types: string[];
    filters: { search?: string; type?: string };
}

const emptyForm = { code: '', name: '', type: 'expense', is_bank: false, is_active: true, description: '' };

export default function ChartOfAccountsIndex({ accounts, balances, types, filters }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user.permissions?.includes(p);

    const [search, setSearch] = useState(filters.search ?? '');
    const [editing, setEditing] = useState<Account | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<Account | null>(null);
    const form = useForm(emptyForm);

    const openCreate = () => {
        setEditing(null);
        form.setData(emptyForm);
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (a: Account) => {
        setEditing(a);
        form.setData({ code: a.code, name: a.name, type: a.type, is_bank: a.is_bank, is_active: a.is_active, description: a.description ?? '' });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('account.chart-of-accounts.update', editing.id), options);
        else form.post(route('account.chart-of-accounts.store'), options);
    };

    const apply = (next: Record<string, string | undefined>) =>
        router.get(route('account.chart-of-accounts.index'), { search, ...filters, ...next }, { preserveState: true, replace: true });

    const locked = !!editing?.is_system; // system accounts: only name + description can change

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Chart of Accounts')}</h2>}>
            <Head title={t('Chart of Accounts')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap gap-2">
                        <form onSubmit={(e) => { e.preventDefault(); apply({}); }} className="flex gap-2">
                            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search code or name...')} className="w-64" />
                            <Button type="submit" variant="outline">{t('Search')}</Button>
                        </form>
                        <Select value={filters.type ?? 'all'} onValueChange={(v) => apply({ type: v === 'all' ? undefined : v })}>
                            <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">{t('All types')}</SelectItem>
                                {types.map((x) => <SelectItem key={x} value={x} className="capitalize">{t(x)}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>
                    {can('create-chart-of-accounts') && <Button onClick={openCreate}><Plus className="mr-1 h-4 w-4" /> {t('Add Account')}</Button>}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Code')}</TableHead>
                                    <TableHead>{t('Name')}</TableHead>
                                    <TableHead>{t('Type')}</TableHead>
                                    <TableHead className="text-right">{t('Balance')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {accounts.data.map((a) => (
                                    <TableRow key={a.id} className={a.is_active ? '' : 'opacity-50'}>
                                        <TableCell className="font-mono">{a.code}</TableCell>
                                        <TableCell>
                                            <span className="font-medium">{a.name}</span>
                                            {a.is_system && <Lock className="ml-2 inline h-3 w-3 text-muted-foreground" aria-label="system" />}
                                            {a.is_bank && <Badge variant="secondary" className="ml-2">{t('Bank / Cash')}</Badge>}
                                            {!a.is_active && <Badge variant="outline" className="ml-2">{t('Inactive')}</Badge>}
                                        </TableCell>
                                        <TableCell><Badge variant="secondary" className="capitalize">{t(a.type)}</Badge></TableCell>
                                        <TableCell className="text-right">{money(balances[a.id] ?? 0)}</TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-chart-of-accounts') && <Button size="icon" variant="ghost" onClick={() => openEdit(a)}><Pencil className="h-4 w-4" /></Button>}
                                            {can('delete-chart-of-accounts') && !a.is_system && (
                                                <Button size="icon" variant="ghost" onClick={() => setDeleting(a)}><Trash2 className="h-4 w-4 text-destructive" /></Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={accounts} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? t('Edit Account') : t('Add Account')}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1">
                                <Label htmlFor="code">{t('Code')}</Label>
                                <Input id="code" value={form.data.code} disabled={locked} onChange={(e) => form.setData('code', e.target.value)} />
                                {form.errors.code && <p className="text-sm text-destructive">{form.errors.code}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label>{t('Type')}</Label>
                                <Select value={form.data.type} disabled={locked} onValueChange={(v) => form.setData('type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>{types.map((x) => <SelectItem key={x} value={x} className="capitalize">{t(x)}</SelectItem>)}</SelectContent>
                                </Select>
                                {form.errors.type && <p className="text-sm text-destructive">{form.errors.type}</p>}
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="name">{t('Name')}</Label>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            {form.errors.name && <p className="text-sm text-destructive">{form.errors.name}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="description">{t('Description')}</Label>
                            <Textarea id="description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        </div>
                        <div className="flex gap-6">
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={form.data.is_bank} disabled={locked || form.data.type !== 'asset'} onCheckedChange={(c) => form.setData('is_bank', c === true)} /> {t('Bank / Cash account')}
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={form.data.is_active} disabled={locked} onCheckedChange={(c) => form.setData('is_active', c === true)} /> {t('Active')}
                            </label>
                        </div>
                        {form.errors.is_bank && <p className="text-sm text-destructive">{form.errors.is_bank}</p>}
                        {locked && <p className="text-xs text-muted-foreground">{t('System account: only the name and description can be changed.')}</p>}
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{editing ? t('Update') : t('Create')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete account?')}</AlertDialogTitle>
                        <AlertDialogDescription>{deleting?.code} – {deleting?.name}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('account.chart-of-accounts.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>
                            {t('Delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
