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
import { Check, CornerUpLeft, Pencil, Printer, Send, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Abilities, DocumentRow, SLUG_BY_TYPE, STATUS_VARIANT, TypeProps } from './types';

interface Props {
    document: DocumentRow;
    type: TypeProps;
    can: Abilities;
    companyName: string;
}

export default function DocumentShow({ document: doc, type, can, companyName }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const [confirmDelete, setConfirmDelete] = useState(false);

    const act = (action: string) => router.post(route(`${type.routeBase}.${action}`, doc.id), {}, { preserveScroll: true });
    const linkTo = (docType: string, id: number) => route(`salespurchase.${SLUG_BY_TYPE[docType]}.show`, id);
    const returnSlug = SLUG_BY_TYPE[can.returnType];

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t(type.label)} {doc.number}</h2>}>
            <Head title={`${t(type.label)} ${doc.number}`} />

            <div className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                {/* actions (hidden when printing) */}
                <div className="flex flex-wrap items-center gap-2 print:hidden">
                    <Button asChild variant="outline"><Link href={route(`${type.routeBase}.index`)}>{t('Back')}</Link></Button>
                    <div className="ml-auto flex flex-wrap gap-2">
                        {can.send && <Button onClick={() => act('send')}><Send className="mr-1 h-4 w-4" /> {t('Mark as sent')}</Button>}
                        {can.answer && (
                            <>
                                <Button onClick={() => act('accept')}><Check className="mr-1 h-4 w-4" /> {t('Accept')}</Button>
                                <Button variant="outline" onClick={() => act('reject')}><X className="mr-1 h-4 w-4" /> {t('Reject')}</Button>
                            </>
                        )}
                        {can.convert && <Button onClick={() => act('convert')}>{t('Convert to invoice')}</Button>}
                        {can.post && <Button onClick={() => act('post')}><Check className="mr-1 h-4 w-4" /> {t('Post')}</Button>}
                        {can.approve && <Button onClick={() => act('approve')}><Check className="mr-1 h-4 w-4" /> {t('Approve')}</Button>}
                        {can.complete && <Button onClick={() => act('complete')}><Check className="mr-1 h-4 w-4" /> {t('Complete')}</Button>}
                        {can.createReturn && (
                            <Button asChild variant="outline">
                                <Link href={`${route(`salespurchase.${returnSlug}.create`)}?invoice=${doc.id}`}><CornerUpLeft className="mr-1 h-4 w-4" /> {t('Create return')}</Link>
                            </Button>
                        )}
                        {can.edit && (
                            <Button asChild variant="outline"><Link href={route(`${type.routeBase}.edit`, doc.id)}><Pencil className="mr-1 h-4 w-4" /> {t('Edit')}</Link></Button>
                        )}
                        <Button variant="outline" onClick={() => window.print()}><Printer className="mr-1 h-4 w-4" /> {t('Print')}</Button>
                        {can.delete && (
                            <Button variant="outline" onClick={() => setConfirmDelete(true)}><Trash2 className="mr-1 h-4 w-4 text-destructive" /> {t('Delete')}</Button>
                        )}
                    </div>
                </div>

                {/* the printable document */}
                <Card>
                    <CardContent className="space-y-6 pt-6">
                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <div className="text-lg font-bold">{companyName}</div>
                                <div className="text-2xl font-semibold">{t(type.label)}</div>
                            </div>
                            <div className="text-right text-sm">
                                <div className="text-xl font-bold">{doc.number}</div>
                                <Badge variant={STATUS_VARIANT[doc.status] ?? 'secondary'} className="capitalize">{t(doc.status)}</Badge>
                                <div className="mt-1 text-muted-foreground">{t('Date')}: {doc.doc_date}</div>
                                {doc.due_date && <div className="text-muted-foreground">{type.key === 'sales_proposal' ? t('Valid until') : t('Due')}: {doc.due_date}</div>}
                            </div>
                        </div>

                        <div className="grid gap-4 text-sm sm:grid-cols-2">
                            <div>
                                <div className="text-muted-foreground">{t(type.partyLabel)}</div>
                                <div className="font-medium">{doc.party?.name}</div>
                                <div>{doc.party?.email}</div>
                            </div>
                            <div>
                                <div className="text-muted-foreground">{t('Warehouse')}</div>
                                <div className="font-medium">{doc.warehouse?.name}</div>
                                {doc.parent && (
                                    <div className="mt-1 print:hidden">
                                        {t('From')}: <Link className="text-primary hover:underline" href={linkTo(doc.parent.type, doc.parent.id)}>{doc.parent.number}</Link>
                                    </div>
                                )}
                            </div>
                        </div>

                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Item')}</TableHead>
                                    <TableHead className="text-right">{t('Qty')}</TableHead>
                                    <TableHead className="text-right">{t('Price')}</TableHead>
                                    <TableHead className="text-right">{t('Discount')}</TableHead>
                                    <TableHead className="text-right">{t('Tax')}</TableHead>
                                    <TableHead className="text-right">{t('Total')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {(doc.items ?? []).map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell className="font-medium">
                                            {item.name}
                                            {item.taxes.length > 0 && (
                                                <div className="text-xs font-normal text-muted-foreground">{item.taxes.map((x) => `${x.name} ${x.rate}%`).join(', ')}</div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">{item.quantity}</TableCell>
                                        <TableCell className="text-right">{money(item.unit_price)}</TableCell>
                                        <TableCell className="text-right">{money(item.discount_amount)}</TableCell>
                                        <TableCell className="text-right">{money(item.tax_amount)}</TableCell>
                                        <TableCell className="text-right">{money(item.total_amount)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>

                        <div className="ml-auto w-full max-w-xs space-y-1 text-sm">
                            <div className="flex justify-between"><span>{t('Subtotal')}</span><span>{money(doc.subtotal)}</span></div>
                            <div className="flex justify-between"><span>{t('Discount')}</span><span>-{money(doc.discount_amount)}</span></div>
                            <div className="flex justify-between"><span>{t('Tax')}</span><span>{money(doc.tax_amount)}</span></div>
                            <div className="flex justify-between border-t pt-2 text-base font-bold"><span>{t('Total')}</span><span>{money(doc.total_amount)}</span></div>
                            {doc.paid_amount > 0 && (
                                <div className="flex justify-between text-muted-foreground"><span>{t('Paid')}</span><span>{money(doc.paid_amount)}</span></div>
                            )}
                        </div>

                        {(doc.reason || doc.notes) && (
                            <div className="space-y-2 text-sm">
                                {doc.reason && <div><span className="text-muted-foreground">{t('Reason')}: </span>{doc.reason}</div>}
                                {doc.notes && <div><span className="text-muted-foreground">{t('Notes')}: </span>{doc.notes}</div>}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {(doc.children?.length ?? 0) > 0 && (
                    <Card className="print:hidden">
                        <CardContent className="space-y-2 pt-6 text-sm">
                            <div className="font-medium">{t('Related documents')}</div>
                            {doc.children!.map((c) => (
                                <div key={c.id} className="flex items-center justify-between">
                                    <Link className="text-primary hover:underline" href={linkTo(c.type, c.id)}>{c.number}</Link>
                                    <span className="flex items-center gap-3">
                                        <Badge variant={STATUS_VARIANT[c.status] ?? 'secondary'} className="capitalize">{t(c.status)}</Badge>
                                        {money(c.total_amount)}
                                    </span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </div>

            <AlertDialog open={confirmDelete} onOpenChange={setConfirmDelete}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete')} {doc.number}?</AlertDialogTitle>
                        <AlertDialogDescription>{t('This draft will be permanently removed.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => router.delete(route(`${type.routeBase}.destroy`, doc.id))}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
