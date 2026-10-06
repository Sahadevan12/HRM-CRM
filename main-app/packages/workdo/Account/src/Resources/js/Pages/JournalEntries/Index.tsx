import Pagination from '@/Components/Pagination';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Paginated } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Entry {
    id: number;
    number: string;
    journal_date: string;
    entry_type: 'manual' | 'automatic';
    description: string;
    total_debit: number;
}

interface Props {
    entries: Paginated<Entry>;
    filters: { search?: string; type?: string; from?: string; to?: string };
}

export default function JournalEntriesIndex({ entries, filters }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const { auth } = usePage<PageProps>().props;
    const canCreate = auth.user.permissions?.includes('create-journal-entries');

    const [search, setSearch] = useState(filters.search ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    const apply = (next: Record<string, string | undefined> = {}) =>
        router.get(route('account.journal-entries.index'), { search, from, to, type: filters.type, ...next }, { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Journal Entries')}</h2>}>
            <Head title={t('Journal Entries')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <form onSubmit={(e) => { e.preventDefault(); apply(); }} className="flex flex-wrap gap-2">
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search number or description...')} className="w-60" />
                        <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} className="w-40" aria-label={t('From')} />
                        <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} className="w-40" aria-label={t('To')} />
                        <Select value={filters.type ?? 'all'} onValueChange={(v) => apply({ type: v === 'all' ? undefined : v })}>
                            <SelectTrigger className="w-36"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">{t('All types')}</SelectItem>
                                <SelectItem value="manual">{t('Manual')}</SelectItem>
                                <SelectItem value="automatic">{t('Automatic')}</SelectItem>
                            </SelectContent>
                        </Select>
                        <Button type="submit" variant="outline">{t('Filter')}</Button>
                    </form>
                    {canCreate && (
                        <Button asChild><Link href={route('account.journal-entries.create')}><Plus className="mr-1 h-4 w-4" /> {t('New Entry')}</Link></Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Number')}</TableHead>
                                    <TableHead>{t('Date')}</TableHead>
                                    <TableHead>{t('Description')}</TableHead>
                                    <TableHead>{t('Type')}</TableHead>
                                    <TableHead className="text-right">{t('Amount')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entries.data.length === 0 && (
                                    <TableRow><TableCell colSpan={5} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell></TableRow>
                                )}
                                {entries.data.map((e) => (
                                    <TableRow key={e.id}>
                                        <TableCell className="font-medium"><Link className="text-primary hover:underline" href={route('account.journal-entries.show', e.id)}>{e.number}</Link></TableCell>
                                        <TableCell>{e.journal_date}</TableCell>
                                        <TableCell>{e.description}</TableCell>
                                        <TableCell><Badge variant={e.entry_type === 'manual' ? 'outline' : 'secondary'} className="capitalize">{t(e.entry_type)}</Badge></TableCell>
                                        <TableCell className="text-right">{money(e.total_debit)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={entries} />
            </div>
        </AuthenticatedLayout>
    );
}
