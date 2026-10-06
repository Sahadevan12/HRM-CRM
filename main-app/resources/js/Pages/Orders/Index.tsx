import Pagination from '@/Components/Pagination';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

interface OrderRow {
    id: number;
    order_number: string;
    plan_name: string;
    duration: string;
    price: number;
    discount: number;
    final_price: number;
    coupon_code: string | null;
    payment_type: string;
    payment_status: 'pending' | 'paid' | 'rejected';
    created_at: string;
    user?: { name: string; email: string };
}

const statusVariant = { pending: 'secondary', paid: 'default', rejected: 'destructive' } as const;

export default function OrdersIndex({ orders, filters, showCompany }: { orders: Paginated<OrderRow>; filters: { search?: string }; showCompany: boolean }) {
    const [search, setSearch] = useState(filters.search ?? '');

    const applySearch = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('orders.index'), { search }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Orders</h2>}>
            <Head title="Orders" />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <form onSubmit={applySearch} className="flex gap-2">
                    <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search order or plan..." className="w-64" />
                    <Button type="submit" variant="outline">Search</Button>
                </form>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Order</TableHead>
                                    {showCompany && <TableHead>Company</TableHead>}
                                    <TableHead>Plan</TableHead>
                                    <TableHead>Payment</TableHead>
                                    <TableHead>Total</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Date</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {orders.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">No orders yet.</TableCell>
                                    </TableRow>
                                )}
                                {orders.data.map((o) => (
                                    <TableRow key={o.id}>
                                        <TableCell className="font-medium">{o.order_number}</TableCell>
                                        {showCompany && <TableCell>{o.user?.name}</TableCell>}
                                        <TableCell>{o.plan_name} <span className="text-muted-foreground">({o.duration})</span></TableCell>
                                        <TableCell className="capitalize">{o.payment_type.replace('_', ' ')}</TableCell>
                                        <TableCell>
                                            {o.final_price}
                                            {o.discount > 0 && <span className="text-xs text-muted-foreground"> (-{o.discount} {o.coupon_code})</span>}
                                        </TableCell>
                                        <TableCell><Badge variant={statusVariant[o.payment_status]} className="capitalize">{o.payment_status}</Badge></TableCell>
                                        <TableCell>{o.created_at.slice(0, 10)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={orders} />
            </div>
        </AuthenticatedLayout>
    );
}
