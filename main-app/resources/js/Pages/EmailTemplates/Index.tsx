import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Template {
    key: string;
    module: string;
    name: string;
    description: string;
    variables: Record<string, string>;
    sample: Record<string, string>;
    subject: string;
    body: string;
    is_enabled: boolean;
    customized: boolean;
    default_subject: string;
    default_body: string;
}

const fill = (text: string, values: Record<string, string>) => text.replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi, (_, k: string) => values[k.toLowerCase()] ?? '');

export default function EmailTemplates({ templates }: { templates: Template[] }) {
    const { t } = useTranslation();
    const [selected, setSelected] = useState(templates[0]?.key);
    const current = templates.find((x) => x.key === selected) ?? templates[0];
    const bodyRef = useRef<HTMLTextAreaElement>(null);

    const form = useForm({ subject: '', body: '', is_enabled: true });

    useEffect(() => {
        if (current) form.setData({ subject: current.subject, body: current.body, is_enabled: current.is_enabled });
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [current?.key, current?.subject, current?.body, current?.is_enabled]);

    if (!current) {
        return (
            <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Email Templates')}</h2>}>
                <Head title={t('Email Templates')} />
                <div className="p-8 text-center text-muted-foreground">{t('No e-mail templates yet. Activate a module in your plan.')}</div>
            </AuthenticatedLayout>
        );
    }

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('email-templates.update', current.key), { preserveScroll: true });
    };

    const insert = (name: string) => {
        const el = bodyRef.current;
        const token = `{{${name}}}`;
        if (!el) return form.setData('body', form.data.body + token);
        const { selectionStart: a, selectionEnd: b } = el;
        form.setData('body', form.data.body.slice(0, a) + token + form.data.body.slice(b));
        requestAnimationFrame(() => { el.focus(); el.setSelectionRange(a + token.length, a + token.length); });
    };

    const sample = { company: t('Your company'), app: 'ERP', ...current.sample };
    const modules = Array.from(new Set(templates.map((x) => x.module)));

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Email Templates')}</h2>}>
            <Head title={t('Email Templates')} />

            <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[18rem_1fr] lg:px-8">
                <div className="space-y-4">
                    {modules.map((m) => (
                        <div key={m}>
                            <div className="mb-1 px-2 text-xs font-semibold uppercase text-muted-foreground">{m}</div>
                            {templates.filter((x) => x.module === m).map((x) => (
                                <button key={x.key} type="button" onClick={() => setSelected(x.key)}
                                    className={`flex w-full items-center justify-between rounded-md px-3 py-2 text-left text-sm ${x.key === current.key ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'}`}>
                                    <span>{x.name}</span>
                                    {!x.is_enabled ? <Badge variant="secondary">{t('off')}</Badge> : x.customized ? <Badge variant="outline">{t('edited')}</Badge> : null}
                                </button>
                            ))}
                        </div>
                    ))}
                </div>

                <form onSubmit={submit} className="space-y-4">
                    <Card>
                        <CardContent className="space-y-4 p-5">
                            <div>
                                <h3 className="text-lg font-semibold">{current.name}</h3>
                                <p className="text-sm text-muted-foreground">{current.description}</p>
                            </div>

                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox checked={form.data.is_enabled} onCheckedChange={(v) => form.setData('is_enabled', v === true)} />
                                {t('Send this e-mail')}
                            </label>

                            <div className="space-y-1">
                                <Label>{t('Subject')}</Label>
                                <Input value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                                {form.errors.subject && <p className="text-sm text-destructive">{form.errors.subject}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label>{t('Message')}</Label>
                                <Textarea ref={bodyRef} rows={9} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
                                {form.errors.body && <p className="text-sm text-destructive">{form.errors.body}</p>}
                                <div className="flex flex-wrap gap-1 pt-1">
                                    {[...Object.keys(current.variables), 'company', 'app'].map((v) => (
                                        <Button key={v} type="button" size="sm" variant="outline" className="h-6 px-2 text-xs" title={current.variables[v] ?? ''} onClick={() => insert(v)}>{`{{${v}}}`}</Button>
                                    ))}
                                </div>
                                <p className="text-xs text-muted-foreground">{t('Plain text. Click a placeholder to put it at the cursor.')}</p>
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <Button type="submit" disabled={form.processing}>{t('Save')}</Button>
                                <Button type="button" variant="outline" onClick={() => router.post(route('email-templates.test', current.key), {}, { preserveScroll: true })}>{t('Send a test to me')}</Button>
                                {current.customized && <Button type="button" variant="ghost" onClick={() => router.delete(route('email-templates.reset', current.key), { preserveScroll: true })}>{t('Back to default')}</Button>}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="space-y-2 p-5">
                            <div className="text-xs font-semibold uppercase text-muted-foreground">{t('Preview with sample data')}</div>
                            <div className="font-medium">{fill(form.data.subject, sample)}</div>
                            <div className="whitespace-pre-line rounded-md bg-muted/40 p-3 text-sm">{fill(form.data.body, sample)}</div>
                        </CardContent>
                    </Card>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
