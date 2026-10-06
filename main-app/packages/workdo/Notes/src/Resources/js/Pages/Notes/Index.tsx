import Pagination from '@/Components/Pagination';
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
import { PageProps, Paginated } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface NoteRow {
    id: number;
    title: string;
    body: string | null;
    priority: number;
    budget: number | null;
    due_on: string | null;
    is_pinned: boolean;
}

interface Props {
    notes: Paginated<NoteRow>;
    filters: { search?: string };
}

const emptyForm = {
    title: '',
    body: '',
    priority: 0,
    budget: 0,
    due_on: '',
    is_pinned: false,
};

export default function NotesIndex({ notes, filters }: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const can = (permission: string) => auth.user.permissions?.includes(permission);

    const [search, setSearch] = useState(filters.search ?? '');
    const [editing, setEditing] = useState<NoteRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<NoteRow | null>(null);
    const form = useForm(emptyForm);

    const openCreate = () => {
        setEditing(null);
        form.setData(emptyForm);
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (row: NoteRow) => {
        setEditing(row);
        form.setData({
            title: row.title,
            body: row.body ?? '',
            priority: row.priority,
            budget: row.budget ?? 0,
            due_on: row.due_on ?? '',
            is_pinned: row.is_pinned,
        });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('notes.notes.update', editing.id), options);
        else form.post(route('notes.notes.store'), options);
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Notes')}</h2>}>
            <Head title={t('Notes')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            router.get(route('notes.notes.index'), { search }, { preserveState: true, replace: true });
                        }}
                        className="flex gap-2"
                    >
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search...')} className="w-64" />
                        <Button type="submit" variant="outline">{t('Search')}</Button>
                    </form>
                    {can('create-notes') && (
                        <Button onClick={openCreate}>
                            <Plus className="mr-1 h-4 w-4" /> {t('Add Note')}
                        </Button>
                    )}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Title')}</TableHead>
                                    <TableHead>{t('Priority')}</TableHead>
                                    <TableHead>{t('Budget')}</TableHead>
                                    <TableHead>{t('Due On')}</TableHead>
                                    <TableHead>{t('Is Pinned')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {notes.data.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={6} className="py-8 text-center text-muted-foreground">
                                            {t('No records found.')}
                                        </TableCell>
                                    </TableRow>
                                )}
                                {notes.data.map((row) => (
                                    <TableRow key={row.id}>
                                        <TableCell>{row.title}</TableCell>
                                        <TableCell>{row.priority}</TableCell>
                                        <TableCell>{row.budget}</TableCell>
                                        <TableCell>{row.due_on}</TableCell>
                                        <TableCell>{row.is_pinned ? t('Yes') : t('No')}</TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-notes') && (
                                                <Button size="icon" variant="ghost" onClick={() => openEdit(row)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Button>
                                            )}
                                            {can('delete-notes') && (
                                                <Button size="icon" variant="ghost" onClick={() => setDeleting(row)}>
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

                <Pagination meta={notes} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editing ? t('Edit Note') : t('Add Note')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1">
                            <Label htmlFor="title">{t('Title')}</Label>
                            <Input id="title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                            {form.errors.title && <p className="text-sm text-destructive">{form.errors.title}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="body">{t('Body')}</Label>
                            <Textarea id="body" value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                            {form.errors.body && <p className="text-sm text-destructive">{form.errors.body}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="priority">{t('Priority')}</Label>
                            <Input id="priority" type="number" step="1" value={form.data.priority} onChange={(e) => form.setData('priority', Number(e.target.value))} />
                            {form.errors.priority && <p className="text-sm text-destructive">{form.errors.priority}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="budget">{t('Budget')}</Label>
                            <Input id="budget" type="number" step="0.01" value={form.data.budget} onChange={(e) => form.setData('budget', Number(e.target.value))} />
                            {form.errors.budget && <p className="text-sm text-destructive">{form.errors.budget}</p>}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="due_on">{t('Due On')}</Label>
                            <Input id="due_on" type="date" value={form.data.due_on} onChange={(e) => form.setData('due_on', e.target.value)} />
                            {form.errors.due_on && <p className="text-sm text-destructive">{form.errors.due_on}</p>}
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={form.data.is_pinned} onCheckedChange={(c) => form.setData('is_pinned', c === true)} /> {t('Is Pinned')}
                        </label>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{editing ? t('Update') : t('Create')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete Note?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('This action cannot be undone.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() =>
                                deleting &&
                                router.delete(route('notes.notes.destroy', deleting.id), {
                                    preserveScroll: true,
                                    onFinish: () => setDeleting(null),
                                })
                            }
                        >
                            {t('Delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
