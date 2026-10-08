import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { useForm } from '@inertiajs/react';
import { FormEvent, useEffect } from 'react';
import { useTranslation } from 'react-i18next';

interface Named { id: number; name: string }

interface Props {
    lead: { id: number; subject: string; name: string; email: string | null; pipeline_id?: number } | null;
    pipelines: Named[];
    pipelineId: number;
    clients: Named[];
    onClose: () => void;
}

const COPY: [string, string][] = [['products', 'Products'], ['sources', 'Sources'], ['labels', 'Labels'], ['tasks', 'Tasks'], ['calls', 'Calls'], ['emails', 'Emails'], ['discussions', 'Discussion'], ['files', 'Files']];

/** Lead -> deal: price, pipeline, the client (existing / new / none) and what to take over. */
export default function ConvertDialog({ lead, pipelines, pipelineId, clients, onClose }: Props) {
    const { t } = useTranslation();
    const form = useForm({
        price: '',
        pipeline_id: String(pipelineId),
        client_mode: 'none',
        client_id: '',
        client_name: '',
        client_email: '',
        copy: COPY.map(([k]) => k),
    });

    useEffect(() => {
        if (lead) {
            form.setData({ price: '', pipeline_id: String(pipelineId), client_mode: 'none', client_id: '', client_name: lead.name, client_email: lead.email ?? '', copy: COPY.map(([k]) => k) });
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [lead?.id]);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!lead) return;
        form.post(route('crm.leads.convert', lead.id), { preserveScroll: true, onSuccess: onClose });
    };

    const errs = form.errors as Record<string, string>;
    const err = (k: string) => errs[k] && <p className="text-sm text-destructive">{errs[k]}</p>;

    return (
        <Dialog open={!!lead} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto">
                <DialogHeader><DialogTitle>{t('Convert to deal')}: {lead?.subject}</DialogTitle></DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    {err('convert') && <p className="rounded bg-destructive/10 p-2 text-sm text-destructive">{errs.convert}</p>}
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1"><Label>{t('Deal price')}</Label><Input type="number" step="0.01" min="0" value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} />{err('price')}</div>
                        <div className="space-y-1">
                            <Label>{t('Pipeline')}</Label>
                            <Select value={form.data.pipeline_id} onValueChange={(v) => form.setData('pipeline_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>{pipelines.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}</SelectContent>
                            </Select>
                            {err('pipeline_id')}
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label>{t('Client')}</Label>
                        <Select value={form.data.client_mode} onValueChange={(v) => form.setData('client_mode', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="none">{t('No client yet')}</SelectItem>
                                <SelectItem value="existing" disabled={clients.length === 0}>{t('Existing client')}</SelectItem>
                                <SelectItem value="new">{t('New client')}</SelectItem>
                            </SelectContent>
                        </Select>
                        {form.data.client_mode === 'existing' && (
                            <>
                                <Select value={form.data.client_id} onValueChange={(v) => form.setData('client_id', v)}>
                                    <SelectTrigger><SelectValue placeholder={t('Select client')} /></SelectTrigger>
                                    <SelectContent>{clients.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
                                </Select>
                                {err('client_id')}
                            </>
                        )}
                        {form.data.client_mode === 'new' && (
                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-1"><Input placeholder={t('Name')} value={form.data.client_name} onChange={(e) => form.setData('client_name', e.target.value)} />{err('client_name')}</div>
                                <div className="space-y-1"><Input type="email" placeholder={t('Email')} value={form.data.client_email} onChange={(e) => form.setData('client_email', e.target.value)} />{err('client_email')}</div>
                                <p className="col-span-2 text-xs text-muted-foreground">{t('The client is created without a login.')}</p>
                            </div>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label>{t('Take over from the lead')}</Label>
                        <div className="grid grid-cols-2 gap-2">
                            {COPY.map(([k, label]) => (
                                <label key={k} className="flex items-center gap-2 text-sm">
                                    <Checkbox checked={form.data.copy.includes(k)} onCheckedChange={(v) => form.setData('copy', v === true ? [...form.data.copy, k] : form.data.copy.filter((c) => c !== k))} />{t(label)}
                                </label>
                            ))}
                        </div>
                        <p className="text-xs text-muted-foreground">{t('Labels are only copied when the deal stays in the same pipeline.')}</p>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>{t('Cancel')}</Button>
                        <Button type="submit" disabled={form.processing}>{t('Convert')}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
