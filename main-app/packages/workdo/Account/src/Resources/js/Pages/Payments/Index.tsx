import Pagination from '@/Components/Pagination';
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
import { Textarea } from '@/Components/ui/textarea';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Paginated } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface PaymentRow {
    id: number;
    amount: number;
    payment_date: string;
    reference: string | null;
    party: { id: number; name: string };
    document: { id: number; number: string };
    account: { code: string; name: string };
}

interface Props {
    payments: Paginated<PaymentRow>;
    invoices: { id: number; number: string; party: string | null; total_amount: number; outstanding: number }[];
    accounts: { id: number; code: string; name: string }[];
    kind: 'customer' | 'vendor';
    filters: { search?: string };
}

export default function PaymentsIndex({ payments, invoices, accounts, kind, filters }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const { auth } = usePage<PageProps>().props;
    const can = (verb: string) => auth.user.permissions?.includes(`${verb}-${kind}-payments`);
    const routeBase = `account.${kind}-payments`;
    const title = kind === 'customer' ? t('Customer Payments') : t('Vendor Payments');

    const [search, setSearch] = useState(filters.search ?? '');
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<PaymentRow | null>(null);

    const form = useForm({ document_id: '', account_id: '', amount: 0, payment_date: new Date().toISOString().slice(0, 10), reference: '', notes: '' });

    const openCreate = () => {
        form.setData({ document_id: '', account_id: accounts.length === 1 ? String(accounts[0].id) : '', amount: 0, payment_date: new Date().toISOString().slice(0, 10), reference: '', notes: '' });
        form.clearErrors();
        setOpen(true);
    };

    const pickInvoice = (id: string) => {
        const invoice = invoices.find((i) => String(i.id) === id);
        form.setData((d) => ({ ...d, document_id: id, amount: invoice?.outstanding ?? 0 }));
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route(`${routeBase}.store`), { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{title}</h2>}>
            <Head title={title} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <form onSubmit={(e) => { e.preventDefault(); router.get(route(`${routeBase}.index`), { search }, { preserveState: true, replace: true }); }} className="flex gap-2">
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search invoice, name or reference...')} className="w-72" />
                        <Button type="submit" variant="outline">{t('Search')}</Button>
                    </form>
                    {can('create') && <Button onClick={openCreate}><Plus className="mr-1 h-4 w-4" /> {kind === 'customer' ? t('Receive Payment') : t('Make Payment')}</Button>}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Date')}</TableHead>
                                    <TableHead>{kind === 'customer' ? t('Customer') : t('Vendor')}</TableHead>
                                    <TableHead>{t('Invoice')}</TableHead>
                                    <TableHead>{t('Account')}</TableHead>
                                    <TableHead>{t('Reference')}</TableHead>
                                    <TableHead className="text-right">{t('Amount')}</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {payments.data.length === 0 && (
                                    <TableRow><TableCell colSpan={7} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell></TableRow>
                                )}
                                {payments.data.map((p) => (
                                    <TableRow key={p.id}>
                                        <TableCell>{p.payment_date}</TableCell>
                                        <TableCell>{p.party.name}</TableCell>
                                        <TableCell className="font-medium">{p.document.number}</TableCell>
                                        <TableCell>{p.account.code} {p.account.name}</TableCell>
                                        <TableCell>{p.reference}</TableCell>
                                        <TableCell className="text-right">{money(p.amount)}</TableCell>
                                        <TableCell className="text-right">
                                            {can('delete') && <Button size="icon" variant="ghost" onClick={() => setDeleting(p)}><Trash2 className="h-4 w-4 text-destructive" /></Button>}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={payments} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{kind === 'customer' ? t('Receive Payment') : t('Make Payment')}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1">
                            <Label>{t('Invoice')}</Label>
                            <Select value={form.data.document_id} onValueChange={pickInvoice}>
                                <SelectTrigger><SelectValue placeholder={invoices.length ? t('Choose an invoice') : t('No open invoices')} /></SelectTrigger>
                                <SelectContent>
                                    {invoices.map((i) => <SelectItem key={i.id} value={String(i.id)}>{i.number} – {i.party} ({t('due')} {money(i.outstanding)})</SelectItem>)}
                                </SelectContent>
                            </Select>
                            {form.errors.document_id && <p className="text-sm text-destructive">{form.errors.document_id}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1">
                                <Label>{kind === 'customer' ? t('Deposit to') : t('Pay from')}</Label>
                                <Select value={form.data.account_id} onValueChange={(v) => form.setData('account_id', v)}>
                                    <SelectTrigger><SelectValue placeholder={t('Choose')} /></SelectTrigger>
                                    <SelectContent>{accounts.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.code} – {a.name}</SelectItem>)}</SelectContent>
                                </Select>
                                {form.errors.account_id && <p className="text-sm text-destructive">{form.errors.account_id}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="amount">{t('Amount')}</Label>
                                <Input id="amount" type="number" min={0} step="0.01" value={form.data.amount} onChange={(e) => form.setData('amount', Number(e.target.value))} />
                                {form.errors.amount && <p className="text-sm text-destructive">{form.errors.amount}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="payment_date">{t('Date')}</Label>
                                <Input id="payment_date" type="date" value={form.data.payment_date} onChange={(e) => form.setData('payment_date', e.target.value)} />
                                {form.errors.payment_date && <p className="text-sm text-destructive">{form.errors.payment_date}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="reference">{t('Reference')}</Label>
                                <Input id="reference" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="notes">{t('Notes')}</Label>
                            <Textarea id="notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{t('Record payment')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete payment?')}</AlertDialogTitle>
                        <AlertDialogDescription>{deleting && `${money(deleting.amount)} – ${deleting.document.number}. ${t('The invoice becomes payable again.')}`}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route(`${routeBase}.destroy`, deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
