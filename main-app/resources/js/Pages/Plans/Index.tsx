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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface PlanRow {
    id: number;
    name: string;
    description: string | null;
    monthly_price: number;
    yearly_price: number;
    max_users: number;
    free_plan: boolean;
    trial: boolean;
    trial_days: number;
    is_disable: boolean;
    modules: string[] | null;
    companies_count: number;
}

interface Props {
    plans: PlanRow[];
    modules: { module: string; name: string }[];
}

const empty = {
    name: '',
    description: '',
    monthly_price: 0,
    yearly_price: 0,
    max_users: 5,
    free_plan: false,
    trial: false,
    trial_days: 0,
    is_disable: false,
    modules: [] as string[],
};

export default function PlansIndex({ plans, modules }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user.permissions?.includes(p);

    const [editing, setEditing] = useState<PlanRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<PlanRow | null>(null);
    const form = useForm(empty);

    const openCreate = () => {
        setEditing(null);
        form.setData(empty);
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (plan: PlanRow) => {
        setEditing(plan);
        form.setData({ ...plan, description: plan.description ?? '', modules: plan.modules ?? [] });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('plans.update', editing.id), options);
        else form.post(route('plans.store'), options);
    };

    const toggleModule = (name: string, checked: boolean) =>
        form.setData('modules', checked ? [...form.data.modules, name] : form.data.modules.filter((m) => m !== name));

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Plans</h2>}>
            <Head title="Plans" />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex justify-end">
                    {can('create-plans') && (
                        <Button onClick={openCreate}>
                            <Plus className="mr-1 h-4 w-4" /> Add Plan
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Plan</TableHead>
                                    <TableHead>Monthly</TableHead>
                                    <TableHead>Yearly</TableHead>
                                    <TableHead>Users</TableHead>
                                    <TableHead>Modules</TableHead>
                                    <TableHead>Companies</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {plans.map((p) => (
                                    <TableRow key={p.id}>
                                        <TableCell>
                                            <div className="font-medium">{p.name}</div>
                                            <div className="flex gap-1 pt-1">
                                                {p.free_plan && <Badge variant="secondary">Free</Badge>}
                                                {p.trial && <Badge variant="secondary">Trial {p.trial_days}d</Badge>}
                                                {p.is_disable && <Badge variant="destructive">Disabled</Badge>}
                                            </div>
                                        </TableCell>
                                        <TableCell>{p.monthly_price}</TableCell>
                                        <TableCell>{p.yearly_price}</TableCell>
                                        <TableCell>{p.max_users === -1 ? 'Unlimited' : p.max_users}</TableCell>
                                        <TableCell>{p.modules?.length ?? 0}</TableCell>
                                        <TableCell>{p.companies_count}</TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-plans') && (
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(p)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                            )}
                                            {can('delete-plans') && (
                                                <Button size="icon" variant="ghost" onClick={() => setDeleting(p)}>
                                                    <Trash2 className="h-4 w-4 text-destructive" />
                                                </Button>
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
                <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Edit Plan' : 'Add Plan'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1">
                            <Label>Name</Label>
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            {form.errors.name && <p className="text-sm text-destructive">{form.errors.name}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label>Description</Label>
                            <Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        </div>
                        <div className="grid grid-cols-3 gap-4">
                            <div className="space-y-1">
                                <Label>Monthly price</Label>
                                <Input type="number" min={0} step="0.01" value={form.data.monthly_price} onChange={(e) => form.setData('monthly_price', Number(e.target.value))} />
                                {form.errors.monthly_price && <p className="text-sm text-destructive">{form.errors.monthly_price}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label>Yearly price</Label>
                                <Input type="number" min={0} step="0.01" value={form.data.yearly_price} onChange={(e) => form.setData('yearly_price', Number(e.target.value))} />
                                {form.errors.yearly_price && <p className="text-sm text-destructive">{form.errors.yearly_price}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label>Max users (-1 = unlimited)</Label>
                                <Input type="number" min={-1} value={form.data.max_users} onChange={(e) => form.setData('max_users', Number(e.target.value))} />
                                {form.errors.max_users && <p className="text-sm text-destructive">{form.errors.max_users}</p>}
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-6">
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={form.data.free_plan} onCheckedChange={(c) => form.setData('free_plan', c === true)} /> Free plan
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={form.data.trial} onCheckedChange={(c) => form.setData('trial', c === true)} /> Offer trial
                            </label>
                            {form.data.trial && (
                                <div className="flex items-center gap-2 text-sm">
                                    <Label>Trial days</Label>
                                    <Input className="w-20" type="number" min={0} value={form.data.trial_days} onChange={(e) => form.setData('trial_days', Number(e.target.value))} />
                                </div>
                            )}
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={form.data.is_disable} onCheckedChange={(c) => form.setData('is_disable', c === true)} /> Disabled
                            </label>
                        </div>
                        <div className="space-y-2">
                            <Label>Included modules</Label>
                            {modules.length === 0 && <p className="text-sm text-muted-foreground">No add-on modules installed.</p>}
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                {modules.map((m) => (
                                    <label key={m.module} className="flex items-center gap-2 text-sm">
                                        <Checkbox checked={form.data.modules.includes(m.module)} onCheckedChange={(c) => toggleModule(m.module, c === true)} />
                                        {m.name}
                                    </label>
                                ))}
                            </div>
                        </div>
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
                        <AlertDialogTitle>Delete plan?</AlertDialogTitle>
                        <AlertDialogDescription>{deleting?.name} will be permanently removed.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('plans.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
