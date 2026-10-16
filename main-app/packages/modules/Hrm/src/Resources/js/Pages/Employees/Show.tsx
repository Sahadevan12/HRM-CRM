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
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Download, Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Doc {
    id: number;
    title: string;
    original_name: string;
    size: number;
    expires_on: string | null;
    type: { name: string } | null;
}

interface Props {
    employee: Record<string, any> & { documents: Doc[] };
    role: string | null;
    documentTypes: { id: number; name: string; is_required: boolean }[];
    canEdit: boolean;
    canDelete: boolean;
    canDocuments: boolean;
}

const NONE = 'none';
const STATUS_VARIANT: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = { active: 'default', inactive: 'secondary', resigned: 'outline', terminated: 'destructive' };

function Item({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="text-sm font-medium">{children || '—'}</div>
        </div>
    );
}

export default function EmployeeShow({ employee: e, role, documentTypes, canEdit, canDelete, canDocuments }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const [upload, setUpload] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const [removing, setRemoving] = useState<Doc | null>(null);

    const form = useForm<{ title: string; document_type_id: string; expires_on: string; file: File | null }>({ title: '', document_type_id: NONE, expires_on: '', file: null });

    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        form.transform((d) => ({ ...d, document_type_id: d.document_type_id === NONE ? null : d.document_type_id }));
        form.post(route('hrm.employees.documents.store', e.id), { forceFormData: true, preserveScroll: true, onSuccess: () => { setUpload(false); form.reset(); } });
    };

    const today = new Date().toISOString().slice(0, 10);
    const address = [e.address_line, e.city, e.state, e.country, e.postal_code].filter(Boolean).join(', ');
    const missing = documentTypes.filter((d) => d.is_required && !e.documents.some((x) => x.type?.name === d.name));

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{e.user.name}</h2>}>
            <Head title={e.user.name} />

            <div className="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap gap-2 print:hidden">
                    <Button asChild variant="outline"><Link href={route('hrm.employees.index')}>{t('Back')}</Link></Button>
                    <div className="ml-auto flex gap-2">
                        {canEdit && <Button asChild><Link href={route('hrm.employees.edit', e.id)}><Pencil className="mr-1 h-4 w-4" /> {t('Edit')}</Link></Button>}
                        {canDelete && <Button variant="outline" onClick={() => setConfirm(true)}><Trash2 className="mr-1 h-4 w-4 text-destructive" /> {t('Delete')}</Button>}
                    </div>
                </div>

                <Card>
                    <CardContent className="space-y-6 pt-6">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <div className="text-2xl font-bold">{e.user.name}</div>
                                <div className="text-muted-foreground">{e.designation?.name ?? t('No designation')} · {e.employee_code}</div>
                            </div>
                            <div className="flex gap-2">
                                <Badge variant={STATUS_VARIANT[e.status] ?? 'secondary'} className="capitalize">{t(e.status)}</Badge>
                                {!e.user.is_enable_login && <Badge variant="outline">{t('No login')}</Badge>}
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-3">
                            <Item label="Email">{e.user.email}</Item>
                            <Item label={t('Phone')}>{e.phone}</Item>
                            <Item label={t('Role')}>{role}</Item>
                            <Item label={t('Branch')}>{e.branch?.name}</Item>
                            <Item label={t('Department')}>{e.department?.name}</Item>
                            <Item label={t('Employment type')}><span className="capitalize">{String(e.employment_type).replace('_', ' ')}</span></Item>
                            <Item label={t('Date of joining')}>{e.date_of_joining}</Item>
                            <Item label={t('Date of birth')}>{e.date_of_birth}</Item>
                            <Item label={t('Gender')}><span className="capitalize">{e.gender}</span></Item>
                            <Item label={t('Basic salary')}>{money(e.basic_salary)}</Item>
                            <Item label={t('Overtime rate')}>{money(e.hourly_rate)}</Item>
                            <Item label={t('Tax ID')}>{e.tax_id}</Item>
                            <div className="sm:col-span-3"><Item label={t('Address')}>{address}</Item></div>
                            <Item label={t('Emergency contact')}>{[e.emergency_name, e.emergency_relationship, e.emergency_phone].filter(Boolean).join(' · ')}</Item>
                            <Item label={t('Bank')}>{[e.bank_name, e.account_holder].filter(Boolean).join(' · ')}</Item>
                            <Item label={t('Account / code')}>{[e.account_number, e.bank_code].filter(Boolean).join(' / ')}</Item>
                            {e.notes && <div className="sm:col-span-3"><Item label={t('Notes')}>{e.notes}</Item></div>}
                        </div>
                    </CardContent>
                </Card>

                <Card className="print:hidden">
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>{t('Documents')}</CardTitle>
                        {canDocuments && <Button size="sm" onClick={() => setUpload(true)}><Plus className="mr-1 h-4 w-4" /> {t('Upload')}</Button>}
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {missing.length > 0 && (
                            <p className="rounded-md bg-destructive/10 p-2 text-sm text-destructive">{t('Missing required documents')}: {missing.map((m) => m.name).join(', ')}</p>
                        )}
                        {e.documents.length === 0 && <p className="text-sm text-muted-foreground">{t('No documents uploaded.')}</p>}
                        {e.documents.map((d) => (
                            <div key={d.id} className="flex items-center justify-between gap-2 rounded-md border p-3 text-sm">
                                <div>
                                    <div className="font-medium">{d.title} {d.type && <Badge variant="secondary" className="ml-1">{d.type.name}</Badge>}</div>
                                    <div className="text-xs text-muted-foreground">
                                        {d.original_name} · {Math.max(1, Math.round(d.size / 1024))} KB
                                        {d.expires_on && <span className={d.expires_on < today ? ' text-destructive' : ''}> · {d.expires_on < today ? t('expired') : t('expires')} {d.expires_on}</span>}
                                    </div>
                                </div>
                                {canDocuments && (
                                    <div className="flex gap-1">
                                        <Button asChild size="icon" variant="ghost"><a href={route('hrm.employees.documents.download', [e.id, d.id])}><Download className="h-4 w-4" /></a></Button>
                                        <Button size="icon" variant="ghost" onClick={() => setRemoving(d)}><Trash2 className="h-4 w-4 text-destructive" /></Button>
                                    </div>
                                )}
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>

            <Dialog open={upload} onOpenChange={setUpload}>
                <DialogContent>
                    <DialogHeader><DialogTitle>{t('Upload document')}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1">
                            <Label htmlFor="title">{t('Title')}</Label>
                            <Input id="title" value={form.data.title} onChange={(ev) => form.setData('title', ev.target.value)} />
                            {form.errors.title && <p className="text-sm text-destructive">{form.errors.title}</p>}
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1">
                                <Label>{t('Type')}</Label>
                                <Select value={form.data.document_type_id} onValueChange={(v) => form.setData('document_type_id', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent><SelectItem value={NONE}>{t('Other')}</SelectItem>{documentTypes.map((x) => <SelectItem key={x.id} value={String(x.id)}>{x.name}</SelectItem>)}</SelectContent>
                                </Select>
                                {form.errors.document_type_id && <p className="text-sm text-destructive">{form.errors.document_type_id}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="expires_on">{t('Expires on')}</Label>
                                <Input id="expires_on" type="date" value={form.data.expires_on} onChange={(ev) => form.setData('expires_on', ev.target.value)} />
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="file">{t('File (PDF, image or Word, max 5 MB)')}</Label>
                            <Input id="file" type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onChange={(ev) => form.setData('file', ev.target.files?.[0] ?? null)} />
                            {form.errors.file && <p className="text-sm text-destructive">{form.errors.file}</p>}
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setUpload(false)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{t('Upload')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!removing} onOpenChange={(o) => !o && setRemoving(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader><AlertDialogTitle>{t('Delete document?')}</AlertDialogTitle><AlertDialogDescription>{removing?.title}</AlertDialogDescription></AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => removing && router.delete(route('hrm.employees.documents.destroy', [e.id, removing.id]), { preserveScroll: true, onFinish: () => setRemoving(null) })}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog open={confirm} onOpenChange={setConfirm}>
                <AlertDialogContent>
                    <AlertDialogHeader><AlertDialogTitle>{t('Delete employee?')}</AlertDialogTitle><AlertDialogDescription>{e.user.name} – {t('the login and all uploaded documents are removed too.')}</AlertDialogDescription></AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => router.delete(route('hrm.employees.destroy', e.id))}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
