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
import { DocumentRow, STATUS_VARIANT, TypeProps } from './types';

interface Props {
    documents: Paginated<DocumentRow>;
    type: TypeProps;
    filters: { search?: string; status?: string };
}

export default function DocumentsIndex({ documents, type, filters }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const { auth } = usePage<PageProps>().props;
    const canCreate = auth.user.permissions?.includes(`create-${type.slug}`);

    const [search, setSearch] = useState(filters.search ?? '');
    const apply = (next: Record<string, string | undefined>) =>
        router.get(route(`${type.routeBase}.index`), { search, status: filters.status, ...next }, { preserveState: true, replace: true });

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t(type.plural)}</h2>}>
            <Head title={t(type.plural)} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap gap-2">
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                apply({});
                            }}
                            className="flex gap-2"
                        >
                            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search number or name...')} className="w-64" />
                            <Button type="submit" variant="outline">{t('Search')}</Button>
                        </form>
                        <Select value={filters.status ?? 'all'} onValueChange={(v) => apply({ status: v === 'all' ? undefined : v })}>
                            <SelectTrigger className="w-40"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">{t('All statuses')}</SelectItem>
                                {type.statuses.map((s) => <SelectItem key={s} value={s} className="capitalize">{t(s)}</SelectItem>)}
                            </SelectContent>
                        </Select>
                    </div>
                    {canCreate && !type.isReturn && (
                        <Button asChild>
                            <Link href={route(`${type.routeBase}.create`)}><Plus className="mr-1 h-4 w-4" /> {t('New')} {t(type.label)}</Link>
                        </Button>
                    )}
                </div>

                {type.isReturn && (
                    <p className="text-sm text-muted-foreground">
                        {t('Returns are created from a posted invoice: open the invoice and choose "Create return".')}
                    </p>
                )}

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Number')}</TableHead>
                                    <TableHead>{t(type.partyLabel)}</TableHead>
                                    <TableHead>{t('Date')}</TableHead>
                                    <TableHead className="text-right">{t('Total')}</TableHead>
                                    <TableHead>{t('Status')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {documents.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell>
                                    </TableRow>
                                )}
                                {documents.data.map((d) => (
                                    <TableRow key={d.id}>
                                        <TableCell className="font-medium">
                                            <Link className="text-primary hover:underline" href={route(`${type.routeBase}.show`, d.id)}>{d.number}</Link>
                                        </TableCell>
                                        <TableCell>{d.party?.name}</TableCell>
                                        <TableCell>{d.doc_date}</TableCell>
                                        <TableCell className="text-right">{money(d.total_amount)}</TableCell>
                                        <TableCell><Badge variant={STATUS_VARIANT[d.status] ?? 'secondary'} className="capitalize">{t(d.status)}</Badge></TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={documents} />
            </div>
        </AuthenticatedLayout>
    );
}
