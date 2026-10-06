import Pagination from '@/Components/Pagination';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Textarea } from '@/Components/ui/textarea';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Check, Paperclip, X } from 'lucide-react';
import { useState } from 'react';

interface Payment {
    id: number;
    amount: number;
    notes: string | null;
    attachment: string | null;
    status: 'pending' | 'approved' | 'rejected';
    response_note: string | null;
    created_at: string;
    order: { order_number: string; plan_name: string; duration: string };
    user: { name: string; email: string };
}

const variant = { pending: 'secondary', approved: 'default', rejected: 'destructive' } as const;

export default function BankTransfersIndex({ payments }: { payments: Paginated<Payment> }) {
    const [rejecting, setRejecting] = useState<Payment | null>(null);
    const [note, setNote] = useState('');

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Bank Transfers</h2>}>
            <Head title="Bank Transfers" />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Order</TableHead>
                                    <TableHead>Company</TableHead>
                                    <TableHead>Plan</TableHead>
                                    <TableHead>Amount</TableHead>
                                    <TableHead>Proof</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {payments.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">No bank transfers.</TableCell>
                                    </TableRow>
                                )}
                                {payments.data.map((p) => (
                                    <TableRow key={p.id}>
                                        <TableCell className="font-medium">{p.order.order_number}</TableCell>
                                        <TableCell>{p.user.name}<div className="text-xs text-muted-foreground">{p.notes}</div></TableCell>
                                        <TableCell>{p.order.plan_name} ({p.order.duration})</TableCell>
                                        <TableCell>{p.amount}</TableCell>
                                        <TableCell>
                                            {p.attachment ? (
                                                <a className="inline-flex items-center gap-1 text-primary underline" href={route('bank-transfers.attachment', p.id)}>
                                                    <Paperclip className="h-4 w-4" /> View
                                                </a>
                                            ) : '—'}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={variant[p.status]} className="capitalize">{p.status}</Badge>
                                            {p.response_note && <div className="text-xs text-muted-foreground">{p.response_note}</div>}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {p.status === 'pending' && (
                                                <>
                                                    <Button size="icon" variant="ghost" title="Approve" onClick={() => router.post(route('bank-transfers.approve', p.id), {}, { preserveScroll: true })}>
                                                        <Check className="h-4 w-4 text-green-600" />
                                                    </Button>
                                                    <Button size="icon" variant="ghost" title="Reject" onClick={() => { setRejecting(p); setNote(''); }}>
                                                        <X className="h-4 w-4 text-destructive" />
                                                    </Button>
                                                </>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={payments} />
            </div>

            <Dialog open={!!rejecting} onOpenChange={(o) => !o && setRejecting(null)}>
                <DialogContent>
                    <DialogHeader><DialogTitle>Reject payment {rejecting?.order.order_number}</DialogTitle></DialogHeader>
                    <Textarea value={note} placeholder="Reason (shown to the company)" onChange={(e) => setNote(e.target.value)} />
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRejecting(null)}>Cancel</Button>
                        <Button
                            variant="destructive"
                            onClick={() => rejecting && router.post(route('bank-transfers.reject', rejecting.id), { response_note: note }, { preserveScroll: true, onFinish: () => setRejecting(null) })}
                        >
                            Reject
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AuthenticatedLayout>
    );
}
