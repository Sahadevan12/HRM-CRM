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
import { Head, router, useForm } from '@inertiajs/react';
import { Check, Download, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Doc {
    id: number;
    title: string;
    description: string | null;
    file_name: string;
    file_size: number;
    requires_acknowledgment: boolean;
    acknowledged: boolean;
    ack_count?: number;
    audience?: number;
    created_at: string;
}

interface Props {
    documents: Doc[];
    hasProfile: boolean;
    can: { create: boolean; delete: boolean };
}

const size = (b: number) => (b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);

export default function DocumentsIndex({ documents, hasProfile, can }: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<Doc | null>(null);
    const form = useForm<{ title: string; description: string; file: File | null; requires_acknowledgment: boolean }>({ title: '', description: '', file: null, requires_acknowledgment: false });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('hrm.documents.store'), { forceFormData: true, preserveScroll: true, onSuccess: () => { setOpen(false); form.reset(); } });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Documents')}</h2>}>
            <Head title={t('Documents')} />

            <div className="mx-auto max-w-5xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                {can.create && <div className="flex justify-end"><Button onClick={() => { form.reset(); form.clearErrors(); setOpen(true); }}><Plus className="mr-1 h-4 w-4" />{t('Upload Document')}</Button></div>}

                <Card>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Title')}</TableHead>
                                    <TableHead>{t('File')}</TableHead>
                                    <TableHead>{t('Acknowledgment')}</TableHead>
                                    <TableHead className="text-right">{t('Actions')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {documents.length === 0 && <TableRow><TableCell colSpan={4} className="py-8 text-center text-muted-foreground">{t('No documents yet.')}</TableCell></TableRow>}
                                {documents.map((d) => (
                                    <TableRow key={d.id}>
                                        <TableCell>
                                            <div className="font-medium">{d.title}</div>
                                            {d.description && <div className="text-xs text-muted-foreground">{d.description}</div>}
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">{d.file_name} · {size(d.file_size)}</TableCell>
                                        <TableCell>
                                            {!d.requires_acknowledgment ? '—' : d.ack_count !== undefined ? (
                                                <Badge variant="outline">{d.ack_count} / {d.audience}</Badge>
                                            ) : d.acknowledged ? (
                                                <Badge><Check className="mr-1 h-3 w-3" />{t('Acknowledged')}</Badge>
                                            ) : hasProfile ? (
                                                <Button size="sm" onClick={() => router.post(route('hrm.documents.acknowledge', d.id), {}, { preserveScroll: true })}>{t('I have read this')}</Button>
                                            ) : '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button size="icon" variant="ghost" asChild><a href={route('hrm.documents.download', d.id)}><Download className="h-4 w-4" /></a></Button>
                                            {can.delete && <Button size="icon" variant="ghost" onClick={() => setDeleting(d)}><Trash2 className="h-4 w-4 text-destructive" /></Button>}
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
                    <DialogHeader><DialogTitle>{t('Upload Document')}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1"><Label>{t('Title')}</Label><Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />{form.errors.title && <p className="text-sm text-destructive">{form.errors.title}</p>}</div>
                        <div className="space-y-1"><Label>{t('Description')}</Label><Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></div>
                        <div className="space-y-1">
                            <Label>{t('File')} (PDF, JPG, PNG, DOC, DOCX · max 5 MB)</Label>
                            <Input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
                            {form.errors.file && <p className="text-sm text-destructive">{form.errors.file}</p>}
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={form.data.requires_acknowledgment} onCheckedChange={(v) => form.setData('requires_acknowledgment', v === true)} />
                            {t('Employees must acknowledge this document')}
                        </label>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{t('Upload')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete Document?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('The file is removed for good.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('hrm.documents.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
