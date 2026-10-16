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
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { PageProps, Paginated } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Row {
    id: number;
    title: string;
    body: string;
    announcement_category_id: number | null;
    start_date: string;
    end_date: string | null;
    requires_acknowledgment: boolean;
    category: { name: string } | null;
    departments: { id: number; name: string }[];
    department_ids: number[];
    audience: number;
    acknowledged: number;
}

interface Props {
    announcements: Paginated<Row>;
    categories: { id: number; name: string }[];
    departments: { id: number; name: string }[];
    filters: { search?: string };
}

const NONE = 'none';
const empty = { title: '', announcement_category_id: NONE, body: '', start_date: '', end_date: '', requires_acknowledgment: false, department_ids: [] as number[] };

export default function AnnouncementsIndex({ announcements, categories, departments, filters }: Props) {
    const { t } = useTranslation();
    const { auth } = usePage<PageProps>().props;
    const can = (p: string) => auth.user.permissions?.includes(p);

    const [search, setSearch] = useState(filters.search ?? '');
    const [editing, setEditing] = useState<Row | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<Row | null>(null);
    const form = useForm(empty);

    const openCreate = () => {
        setEditing(null);
        form.setData({ ...empty, start_date: new Date().toISOString().slice(0, 10) });
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (r: Row) => {
        setEditing(r);
        form.setData({
            title: r.title,
            announcement_category_id: r.announcement_category_id ? String(r.announcement_category_id) : NONE,
            body: r.body,
            start_date: r.start_date.slice(0, 10),
            end_date: r.end_date ? r.end_date.slice(0, 10) : '',
            requires_acknowledgment: r.requires_acknowledgment,
            department_ids: r.department_ids,
        });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, announcement_category_id: d.announcement_category_id === NONE ? null : d.announcement_category_id, end_date: d.end_date || null }));
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('hrm.announcements.update', editing.id), options);
        else form.post(route('hrm.announcements.store'), options);
    };

    const toggleDept = (id: number, on: boolean) => form.setData('department_ids', on ? [...form.data.department_ids, id] : form.data.department_ids.filter((d) => d !== id));
    const err = (k: string) => (form.errors as Record<string, string>)[k] && <p className="text-sm text-destructive">{(form.errors as Record<string, string>)[k]}</p>;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Announcements')}</h2>}>
            <Head title={t('Announcements')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <form onSubmit={(e) => { e.preventDefault(); router.get(route('hrm.announcements.index'), { search }, { preserveState: true, replace: true }); }} className="flex gap-2">
                        <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('Search...')} className="w-64" />
                        <Button type="submit" variant="outline">{t('Search')}</Button>
                    </form>
                    {can('create-announcements') && <Button onClick={openCreate}><Plus className="mr-1 h-4 w-4" />{t('Add Announcement')}</Button>}
                </div>

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Title')}</TableHead>
                                    <TableHead>{t('Category')}</TableHead>
                                    <TableHead>{t('Audience')}</TableHead>
                                    <TableHead>{t('From')}</TableHead>
                                    <TableHead>{t('To')}</TableHead>
                                    <TableHead>{t('Acknowledged')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {announcements.data.length === 0 && <TableRow><TableCell colSpan={7} className="py-8 text-center text-muted-foreground">{t('No records found.')}</TableCell></TableRow>}
                                {announcements.data.map((r) => (
                                    <TableRow key={r.id}>
                                        <TableCell className="font-medium">{r.title}</TableCell>
                                        <TableCell>{r.category?.name ?? '—'}</TableCell>
                                        <TableCell>{r.departments.length ? r.departments.map((d) => d.name).join(', ') : t('Everyone')}</TableCell>
                                        <TableCell>{r.start_date.slice(0, 10)}</TableCell>
                                        <TableCell>{r.end_date ? r.end_date.slice(0, 10) : '—'}</TableCell>
                                        <TableCell>{r.requires_acknowledgment ? <Badge variant="outline">{r.acknowledged} / {r.audience}</Badge> : '—'}</TableCell>
                                        <TableCell className="text-right">
                                            {can('edit-announcements') && <Button size="icon" variant="ghost" onClick={() => openEdit(r)}><Pencil className="h-4 w-4" /></Button>}
                                            {can('delete-announcements') && <Button size="icon" variant="ghost" onClick={() => setDeleting(r)}><Trash2 className="h-4 w-4 text-destructive" /></Button>}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Pagination meta={announcements} />
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogHeader><DialogTitle>{editing ? t('Edit Announcement') : t('Add Announcement')}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1"><Label>{t('Title')}</Label><Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />{err('title')}</div>
                        <div className="space-y-1">
                            <Label>{t('Category')}</Label>
                            <Select value={form.data.announcement_category_id} onValueChange={(v) => form.setData('announcement_category_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>{t('None')}</SelectItem>
                                    {categories.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1"><Label>{t('Message')}</Label><Textarea rows={5} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />{err('body')}</div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1"><Label>{t('From')}</Label><Input type="date" value={form.data.start_date} onChange={(e) => form.setData('start_date', e.target.value)} />{err('start_date')}</div>
                            <div className="space-y-1"><Label>{t('To')} ({t('optional')})</Label><Input type="date" value={form.data.end_date} onChange={(e) => form.setData('end_date', e.target.value)} />{err('end_date')}</div>
                        </div>
                        <div className="space-y-2">
                            <Label>{t('Departments')} ({t('none selected = everyone')})</Label>
                            <div className="grid grid-cols-2 gap-2">
                                {departments.map((d) => (
                                    <label key={d.id} className="flex items-center gap-2 text-sm">
                                        <Checkbox checked={form.data.department_ids.includes(d.id)} onCheckedChange={(v) => toggleDept(d.id, v === true)} />{d.name}
                                    </label>
                                ))}
                            </div>
                            {err('department_ids')}
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={form.data.requires_acknowledgment} onCheckedChange={(v) => form.setData('requires_acknowledgment', v === true)} />
                            {t('Employees must acknowledge this announcement')}
                        </label>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{editing ? t('Update') : t('Publish')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete Announcement?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('This action cannot be undone.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('hrm.announcements.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
