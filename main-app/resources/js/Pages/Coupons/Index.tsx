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
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface CouponRow {
    id: number;
    name: string;
    code: string;
    type: 'percentage' | 'flat';
    discount: number;
    usage_limit: number | null;
    expiry_date: string | null;
    is_active: boolean;
    usages_count: number;
}

const empty = { name: '', code: '', type: 'percentage', discount: 10, usage_limit: '' as string | number, expiry_date: '', is_active: true };

export default function CouponsIndex({ coupons }: { coupons: CouponRow[] }) {
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user.permissions?.includes(p);

    const [editing, setEditing] = useState<CouponRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<CouponRow | null>(null);
    const form = useForm(empty);

    const openCreate = () => {
        setEditing(null);
        form.setData(empty);
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (c: CouponRow) => {
        setEditing(c);
        form.setData({ ...c, usage_limit: c.usage_limit ?? '', expiry_date: c.expiry_date ?? '' });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('coupons.update', editing.id), options);
        else form.post(route('coupons.store'), options);
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Coupons</h2>}>
            <Head title="Coupons" />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex justify-end">
                    {can('create-coupons') && (
                        <Button onClick={openCreate}><Plus className="mr-1 h-4 w-4" /> Add Coupon</Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Code</TableHead>
                                    <TableHead>Discount</TableHead>
                                    <TableHead>Used</TableHead>
                                    <TableHead>Expires</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {coupons.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">No coupons yet.</TableCell>
                                    </TableRow>
                                )}
                                {coupons.map((c) => (
                                    <TableRow key={c.id}>
                                        <TableCell className="font-medium">{c.name}</TableCell>
                                        <TableCell><code>{c.code}</code></TableCell>
                                        <TableCell>{c.type === 'percentage' ? `${c.discount}%` : c.discount}</TableCell>
                                        <TableCell>{c.usages_count}{c.usage_limit ? ` / ${c.usage_limit}` : ''}</TableCell>
                                        <TableCell>{c.expiry_date ?? '—'}</TableCell>
                                        <TableCell><Badge variant={c.is_active ? 'default' : 'secondary'}>{c.is_active ? 'Active' : 'Inactive'}</Badge></TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-coupons') && (
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(c)}><Pencil className="h-4 w-4" /></Button>
                                            )}
                                            {can('delete-coupons') && (
                                                <Button size="icon" variant="ghost" onClick={() => setDeleting(c)}><Trash2 className="h-4 w-4 text-destructive" /></Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{editing ? 'Edit Coupon' : 'Add Coupon'}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1">
                                <Label>Name</Label>
                                <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                {form.errors.name && <p className="text-sm text-destructive">{form.errors.name}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label>Code</Label>
                                <Input value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} />
                                {form.errors.code && <p className="text-sm text-destructive">{form.errors.code}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label>Type</Label>
                                <Select value={form.data.type} onValueChange={(v) => form.setData('type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="percentage">Percentage</SelectItem>
                                        <SelectItem value="flat">Flat amount</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label>Discount</Label>
                                <Input type="number" step="0.01" min={0} value={form.data.discount} onChange={(e) => form.setData('discount', Number(e.target.value))} />
                                {form.errors.discount && <p className="text-sm text-destructive">{form.errors.discount}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label>Usage limit (blank = unlimited)</Label>
                                <Input type="number" min={1} value={form.data.usage_limit} onChange={(e) => form.setData('usage_limit', e.target.value)} />
                            </div>
                            <div className="space-y-1">
                                <Label>Expiry date</Label>
                                <Input type="date" value={form.data.expiry_date} onChange={(e) => form.setData('expiry_date', e.target.value)} />
                            </div>
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={form.data.is_active} onCheckedChange={(c) => form.setData('is_active', c === true)} /> Active
                        </label>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>Cancel</Button>
                            <Button type="submit" disabled={form.processing}>{editing ? 'Update' : 'Create'}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete coupon?</AlertDialogTitle>
                        <AlertDialogDescription>{deleting?.code} will be permanently removed.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('coupons.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
