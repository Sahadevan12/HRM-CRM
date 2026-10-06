import Pagination from '@/Components/Pagination';
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
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Paginated, User } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface RoleOption {
    id: number;
    name: string;
    label: string;
}

interface UserRow extends Omit<User, 'roles'> {
    roles: { id: number; name: string; label: string }[];
}

interface Props {
    users: Paginated<UserRow>;
    roles: RoleOption[];
    filters: { search?: string; type?: string };
}

const emptyForm = { name: '', email: '', mobile_no: '', password: '', type: 'staff', role: 'staff', is_enable_login: true };

export default function UsersIndex({ users, roles, filters }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user.permissions?.includes(p);

    const [search, setSearch] = useState(filters.search ?? '');
    const [editing, setEditing] = useState<UserRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<UserRow | null>(null);

    const form = useForm(emptyForm);

    const openCreate = () => {
        setEditing(null);
        form.setData(emptyForm);
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (user: UserRow) => {
        setEditing(user);
        form.setData({
            name: user.name,
            email: user.email,
            mobile_no: user.mobile_no ?? '',
            password: '',
            type: user.type ?? 'staff',
            role: user.roles[0]?.name ?? 'staff',
            is_enable_login: user.is_enable_login ?? true,
        });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) {
            form.put(route('users.update', editing.id), options);
        } else {
            form.post(route('users.store'), options);
        }
    };

    const applySearch = (e: FormEvent) => {
        e.preventDefault();
        router.get(route('users.index'), { search }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-gray-800">Users</h2>}>
            <Head title="Users" />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <form onSubmit={applySearch} className="flex gap-2">
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search name or email..."
                            className="w-64"
                        />
                        <Button type="submit" variant="outline">Search</Button>
                    </form>
                    {can('create-users') && (
                        <Button onClick={openCreate}>
                            <Plus className="mr-1 h-4 w-4" /> Add User
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Role</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {users.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                                            No users found.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {users.data.map((u) => (
                                    <TableRow key={u.id}>
                                        <TableCell className="font-medium">{u.name}</TableCell>
                                        <TableCell>{u.email}</TableCell>
                                        <TableCell><Badge variant="secondary" className="capitalize">{u.type}</Badge></TableCell>
                                        <TableCell>
                                            {u.roles.map((r) => r.label).join(', ')}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-users') && (
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(u)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                            )}
                                            {can('delete-users') && (
                                                <Button size="icon" variant="ghost" onClick={() => setDeleting(u)}>
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

                <Pagination meta={users} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Edit User' : 'Add User'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1">
                            <Label htmlFor="name">Name</Label>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            {form.errors.name && <p className="text-sm text-destructive">{form.errors.name}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="email">Email</Label>
                            <Input id="email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                            {form.errors.email && <p className="text-sm text-destructive">{form.errors.email}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="mobile_no">Mobile</Label>
                            <Input id="mobile_no" value={form.data.mobile_no} onChange={(e) => form.setData('mobile_no', e.target.value)} />
                        </div>
                        {!editing && (
                            <div className="space-y-1">
                                <Label htmlFor="password">Password</Label>
                                <Input id="password" type="password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                                {form.errors.password && <p className="text-sm text-destructive">{form.errors.password}</p>}
                            </div>
                        )}
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1">
                                <Label>Type</Label>
                                <Select value={form.data.type} onValueChange={(v) => form.setData('type', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="staff">Staff</SelectItem>
                                        <SelectItem value="client">Client</SelectItem>
                                        <SelectItem value="vendor">Vendor</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="space-y-1">
                                <Label>Role</Label>
                                <Select value={form.data.role} onValueChange={(v) => form.setData('role', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {roles.map((r) => (
                                            <SelectItem key={r.id} value={r.name}>{r.label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.role && <p className="text-sm text-destructive">{form.errors.role}</p>}
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
                        <AlertDialogTitle>Delete user?</AlertDialogTitle>
                        <AlertDialogDescription>
                            {deleting?.name} will be permanently removed.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => deleting && router.delete(route('users.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}
                        >
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
