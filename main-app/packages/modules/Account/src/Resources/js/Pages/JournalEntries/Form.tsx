import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

interface Line {
    account_id: string;
    debit: number;
    credit: number;
    description: string;
}

const blank = (): Line => ({ account_id: '', debit: 0, credit: 0, description: '' });
const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

export default function JournalEntryForm({ accounts }: { accounts: { id: number; code: string; name: string; type: string }[] }) {
    const { t } = useTranslation();
    const money = useMoney();
    const form = useForm<{ journal_date: string; description: string; lines: Line[] }>({
        journal_date: new Date().toISOString().slice(0, 10),
        description: '',
        lines: [blank(), blank()],
    });

    const setLine = (i: number, patch: Partial<Line>) => form.setData('lines', form.data.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)));
    const debit = round2(form.data.lines.reduce((s, l) => s + (Number(l.debit) || 0), 0));
    const credit = round2(form.data.lines.reduce((s, l) => s + (Number(l.credit) || 0), 0));
    const balanced = debit === credit && debit > 0;
    const err = (key: string) => (form.errors as Record<string, string>)[key];

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('account.journal-entries.store'));
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('New Journal Entry')}</h2>}>
            <Head title={t('New Journal Entry')} />

            <form onSubmit={submit} className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <Card>
                    <CardContent className="grid gap-4 pt-6 sm:grid-cols-3">
                        <div className="space-y-1">
                            <Label htmlFor="date">{t('Date')}</Label>
                            <Input id="date" type="date" value={form.data.journal_date} onChange={(e) => form.setData('journal_date', e.target.value)} />
                            {err('journal_date') && <p className="text-sm text-destructive">{err('journal_date')}</p>}
                        </div>
                        <div className="space-y-1 sm:col-span-2">
                            <Label htmlFor="description">{t('Description')}</Label>
                            <Input id="description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                            {err('description') && <p className="text-sm text-destructive">{err('description')}</p>}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>{t('Lines')}</CardTitle>
                        <Button type="button" size="sm" variant="outline" onClick={() => form.setData('lines', [...form.data.lines, blank()])}><Plus className="mr-1 h-4 w-4" /> {t('Add line')}</Button>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="min-w-64">{t('Account')}</TableHead>
                                    <TableHead>{t('Note')}</TableHead>
                                    <TableHead className="w-32">{t('Debit')}</TableHead>
                                    <TableHead className="w-32">{t('Credit')}</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {form.data.lines.map((line, i) => (
                                    <TableRow key={i} className="align-top">
                                        <TableCell>
                                            <Select value={line.account_id} onValueChange={(v) => setLine(i, { account_id: v })}>
                                                <SelectTrigger><SelectValue placeholder={t('Choose an account')} /></SelectTrigger>
                                                <SelectContent>
                                                    {accounts.map((a) => <SelectItem key={a.id} value={String(a.id)}>{a.code} – {a.name}</SelectItem>)}
                                                </SelectContent>
                                            </Select>
                                            {err(`lines.${i}.account_id`) && <p className="text-xs text-destructive">{err(`lines.${i}.account_id`)}</p>}
                                        </TableCell>
                                        <TableCell><Input value={line.description} onChange={(e) => setLine(i, { description: e.target.value })} /></TableCell>
                                        <TableCell><Input type="number" min={0} step="0.01" value={line.debit} onChange={(e) => setLine(i, { debit: Number(e.target.value), credit: 0 })} /></TableCell>
                                        <TableCell><Input type="number" min={0} step="0.01" value={line.credit} onChange={(e) => setLine(i, { credit: Number(e.target.value), debit: 0 })} /></TableCell>
                                        <TableCell>
                                            <Button type="button" size="icon" variant="ghost" disabled={form.data.lines.length <= 2} onClick={() => form.setData('lines', form.data.lines.filter((_, j) => j !== i))}>
                                                <Trash2 className="h-4 w-4 text-destructive" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                                <TableRow className="font-semibold">
                                    <TableCell colSpan={2} className="text-right">{t('Totals')}</TableCell>
                                    <TableCell>{money(debit)}</TableCell>
                                    <TableCell>{money(credit)}</TableCell>
                                    <TableCell />
                                </TableRow>
                            </TableBody>
                        </Table>
                        {err('lines') && <p className="p-4 text-sm text-destructive">{err('lines')}</p>}
                        {!balanced && debit + credit > 0 && (
                            <p className="p-4 text-sm text-destructive">{t('Debits and credits must be equal. Difference')}: {money(Math.abs(debit - credit))}</p>
                        )}
                    </CardContent>
                </Card>

                <div className="flex justify-end gap-2">
                    <Button asChild variant="outline"><Link href={route('account.journal-entries.index')}>{t('Cancel')}</Link></Button>
                    <Button type="submit" disabled={form.processing || !balanced}>{t('Post entry')}</Button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
