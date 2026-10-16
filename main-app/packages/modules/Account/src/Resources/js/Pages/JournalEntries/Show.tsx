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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Printer, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Props {
    entry: {
        id: number;
        number: string;
        journal_date: string;
        entry_type: string;
        description: string;
        total_debit: number;
        total_credit: number;
        items: { id: number; description: string | null; debit: number; credit: number; account: { code: string; name: string } }[];
    };
    canDelete: boolean;
}

export default function JournalEntryShow({ entry, canDelete }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const [confirm, setConfirm] = useState(false);

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Journal Entry')} {entry.number}</h2>}>
            <Head title={`${t('Journal Entry')} ${entry.number}`} />

            <div className="mx-auto max-w-4xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex gap-2 print:hidden">
                    <Button asChild variant="outline"><Link href={route('account.journal-entries.index')}>{t('Back')}</Link></Button>
                    <div className="ml-auto flex gap-2">
                        <Button variant="outline" onClick={() => window.print()}><Printer className="mr-1 h-4 w-4" /> {t('Print')}</Button>
                        {canDelete && <Button variant="outline" onClick={() => setConfirm(true)}><Trash2 className="mr-1 h-4 w-4 text-destructive" /> {t('Delete')}</Button>}
                    </div>
                </div>

                <Card>
                    <CardContent className="space-y-4 pt-6">
                        <div className="flex flex-wrap justify-between gap-2">
                            <div>
                                <div className="text-xl font-bold">{entry.number}</div>
                                <div className="text-muted-foreground">{entry.description}</div>
                            </div>
                            <div className="text-right text-sm">
                                <div>{entry.journal_date}</div>
                                <Badge variant="secondary" className="capitalize">{t(entry.entry_type)}</Badge>
                            </div>
                        </div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Account')}</TableHead>
                                    <TableHead>{t('Note')}</TableHead>
                                    <TableHead className="text-right">{t('Debit')}</TableHead>
                                    <TableHead className="text-right">{t('Credit')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entry.items.map((i) => (
                                    <TableRow key={i.id}>
                                        <TableCell><span className="font-mono text-muted-foreground">{i.account.code}</span> {i.account.name}</TableCell>
                                        <TableCell>{i.description}</TableCell>
                                        <TableCell className="text-right">{i.debit > 0 ? money(i.debit) : ''}</TableCell>
                                        <TableCell className="text-right">{i.credit > 0 ? money(i.credit) : ''}</TableCell>
                                    </TableRow>
                                ))}
                                <TableRow className="font-semibold">
                                    <TableCell colSpan={2} className="text-right">{t('Totals')}</TableCell>
                                    <TableCell className="text-right">{money(entry.total_debit)}</TableCell>
                                    <TableCell className="text-right">{money(entry.total_credit)}</TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            <AlertDialog open={confirm} onOpenChange={setConfirm}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete')} {entry.number}?</AlertDialogTitle>
                        <AlertDialogDescription>{t('The booking will be removed from the books.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => router.delete(route('account.journal-entries.destroy', entry.id))}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
