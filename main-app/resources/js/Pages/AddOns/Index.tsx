import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

interface AddOnRow {
    id: number;
    module: string;
    name: string;
    package_name: string | null;
    version: number | null;
    is_enable: boolean;
    for_admin: boolean;
    priority: number;
}

export default function AddOnsIndex({ addOns }: { addOns: AddOnRow[] }) {
    const { auth } = usePage<PageProps>().props;
    const canEdit = auth.user.permissions?.includes('edit-add-ons');

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Add-ons</h2>}>
            <Head title="Add-ons" />

            <div className="mx-auto max-w-5xl space-y-4 px-4 py-8 sm:px-6">
                <p className="text-sm text-muted-foreground">
                    Modules found in <code>packages/workdo</code>. Disabling a module hides it for every company, whatever their plan.
                </p>
                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Module</TableHead>
                                    <TableHead>Package</TableHead>
                                    <TableHead>Version</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Action</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {addOns.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">No modules installed.</TableCell>
                                    </TableRow>
                                )}
                                {addOns.map((a) => (
                                    <TableRow key={a.id}>
                                        <TableCell className="font-medium">
                                            {a.name} {a.for_admin && <Badge variant="secondary">Admin only</Badge>}
                                        </TableCell>
                                        <TableCell>{a.package_name}</TableCell>
                                        <TableCell>{a.version ?? '—'}</TableCell>
                                        <TableCell>
                                            <Badge variant={a.is_enable ? 'default' : 'secondary'}>{a.is_enable ? 'Enabled' : 'Disabled'}</Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {canEdit && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => router.post(route('add-ons.toggle', a.module), { is_enable: !a.is_enable }, { preserveScroll: true })}
                                                >
                                                    {a.is_enable ? 'Disable' : 'Enable'}
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
        </AuthenticatedLayout>
    );
}
