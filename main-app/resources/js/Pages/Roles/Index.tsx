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
import { Checkbox } from '@/Components/ui/checkbox';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Paginated } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface Permission {
    id: number;
    name: string;
    module: string;
    label: string;
}

interface RoleRow {
    id: number;
    name: string;
    label: string;
    description: string | null;
    users_count: number;
    permissions: { id: number; name: string }[];
}

interface Props {
    roles: Paginated<RoleRow>;
    permissionGroups: Record<string, Permission[]>;
    filters: { search?: string };
}

export default function RolesIndex({ roles, permissionGroups, filters }: Props) {
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user.permissions?.includes(p);

    const [search, setSearch] = useState(filters.search ?? '');
    const [editing, setEditing] = useState<RoleRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<RoleRow | null>(null);

    const form = useForm<{ label: string; description: string; permissions: string[] }>({
        label: '',
        description: '',
        permissions: [],
    });

    const openCreate = () => {
        setEditing(null);
        form.setData({ label: '', description: '', permissions: [] });
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (role: RoleRow) => {
        setEditing(role);
        form.setData({
            label: role.label,
            description: role.description ?? '',
            permissions: role.permissions.map((p) => p.name),
        });
        form.clearErrors();
        setOpen(true);
    };

    const toggle = (name: string, checked: boolean) =>
        form.setData(
            'permissions',
            checked ? [...form.data.permissions, name] : form.data.permissions.filter((p) => p !== name),
        );

    const toggleGroup = (perms: Permission[], checked: boolean) => {
        const names = perms.map((p) => p.name);
        const rest = form.data.permissions.filter((p) => !names.includes(p));
        form.setData('permissions', checked ? [...rest, ...names] : rest);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) {
            form.put(route('roles.update', editing.id), options);
        } else {
            form.post(route('roles.store'), options);
        }
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold text-gray-800">Roles</h2>}>
            <Head title="Roles" />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(route('roles.index'), { search }, { preserveState: true, replace: true });
                        }}
                        className="flex gap-2"
                    >
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search roles…" className="w-64" />
                        <Button type="submit" variant="outline">Search</Button>
                    </form>
                    {can('create-roles') && (
                        <Button onClick={openCreate}>
                            <Plus className="mr-1 h-4 w-4" /> Add Role
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Role</TableHead>
                                    <TableHead>Permissions</TableHead>
                                    <TableHead>Users</TableHead>
                                    <TableHead className="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {roles.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={4} className="py-8 text-center text-muted-foreground">
                                            No custom roles yet.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {roles.data.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell>
                                            <div className="font-medium">{r.label}</div>
                                            <div className="text-xs text-muted-foreground">{r.description}</div>
                                        </TableCell>
                                        <TableCell><Badge variant="secondary">{r.permissions.length}</Badge></TableCell>
                                        <TableCell>{r.users_count}</TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-roles') && (
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(r)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                            )}
                                            {can('delete-roles') && (
                                                <Button size="icon" variant="ghost" onClick={() => setDeleting(r)}>
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

                <Pagination meta={roles} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Edit Role' : 'Add Role'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1">
                                <Label htmlFor="label">Name</Label>
                                <Input id="label" value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} />
                                {form.errors.label && <p className="text-sm text-destructive">{form.errors.label}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="description">Description</Label>
                                <Input id="description" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                            </div>
                        </div>

                        <div className="space-y-3">
                            <Label>Permissions</Label>
                            {Object.entries(permissionGroups).map(([module, perms]) => {
                                const all = perms.every((p) => form.data.permissions.includes(p.name));
                                return (
                                    <div key={module} className="rounded-md border p-3">
                                        <label className="mb-2 flex items-center gap-2 text-sm font-semibold capitalize">
                                            <Checkbox checked={all} onCheckedChange={(c) => toggleGroup(perms, c === true)} />
                                            {module.replace(/-/g, ' ')}
                                        </label>
                                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                            {perms.map((p) => (
                                                <label key={p.id} className="flex items-center gap-2 text-sm">
                                                    <Checkbox
                                                        checked={form.data.permissions.includes(p.name)}
                                                        onCheckedChange={(c) => toggle(p.name, c === true)}
                                                    />
                                                    {p.label}
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                );
                            })}
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
                        <AlertDialogTitle>Delete role?</AlertDialogTitle>
                        <AlertDialogDescription>{deleting?.label} will be permanently removed.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => deleting && router.delete(route('roles.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}
                        >
                            Delete
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
