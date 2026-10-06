import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { cn } from '@/lib/utils';
import { PageProps, Settings } from '@/types';
import { THEME_COLORS } from '@/utils/theme';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { FormEvent, ReactNode, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Props {
    settings: Settings;
    themeColors: string[];
}

const TABS = ['Brand', 'System', 'Currency'] as const;
type Tab = (typeof TABS)[number];

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
    return (
        <div className="space-y-1">
            <Label>{label}</Label>
            {children}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}

function BrandForm({ settings, themeColors }: Props) {
    const { t } = useTranslation();
    const form = useForm({
        titleText: settings.titleText ?? 'ERP Clone',
        footerText: settings.footerText ?? '',
        themeColor: settings.themeColor ?? 'slate',
        themeMode: settings.themeMode ?? 'light',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('settings.brand.update'), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-5">
            <Field label="Application title" error={form.errors.titleText}>
                <Input value={form.data.titleText} onChange={(e) => form.setData('titleText', e.target.value)} />
            </Field>
            <Field label="Footer text" error={form.errors.footerText}>
                <Input value={form.data.footerText} onChange={(e) => form.setData('footerText', e.target.value)} />
            </Field>
            <Field label="Primary colour" error={form.errors.themeColor}>
                <div className="flex gap-3">
                    {themeColors.map((c) => (
                        <button
                            key={c}
                            type="button"
                            title={c}
                            aria-label={c}
                            onClick={() => form.setData('themeColor', c)}
                            className={cn(
                                'flex h-9 w-9 items-center justify-center rounded-full border-2',
                                form.data.themeColor === c ? 'border-foreground' : 'border-transparent',
                            )}
                            style={{ backgroundColor: THEME_COLORS[c]?.swatch }}
                        >
                            {form.data.themeColor === c && <Check className="h-4 w-4 text-white" />}
                        </button>
                    ))}
                </div>
            </Field>
            <Field label="Default theme mode" error={form.errors.themeMode}>
                <Select value={form.data.themeMode} onValueChange={(v) => form.setData('themeMode', v)}>
                    <SelectTrigger className="w-48"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem value="light">Light</SelectItem>
                        <SelectItem value="dark">Dark</SelectItem>
                        <SelectItem value="system">System</SelectItem>
                    </SelectContent>
                </Select>
            </Field>
            <Button type="submit" disabled={form.processing}>{t('Save')}</Button>
        </form>
    );
}

function SystemForm({ settings }: Props) {
    const { t } = useTranslation();
    const { languages } = usePage<PageProps>().props;
    const form = useForm({
        defaultLanguage: settings.defaultLanguage ?? 'en',
        dateFormat: settings.dateFormat ?? 'Y-m-d',
        timezone: settings.timezone ?? 'UTC',
    });
    const timezones = Intl.supportedValuesOf('timeZone');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('settings.system.update'), { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-5">
            <Field label="Default language" error={form.errors.defaultLanguage}>
                <Select value={form.data.defaultLanguage} onValueChange={(v) => form.setData('defaultLanguage', v)}>
                    <SelectTrigger className="w-64"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        {Object.entries(languages).map(([code, name]) => (
                            <SelectItem key={code} value={code}>{name}</SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
            <Field label="Date format" error={form.errors.dateFormat}>
                <Select value={form.data.dateFormat} onValueChange={(v) => form.setData('dateFormat', v)}>
                    <SelectTrigger className="w-64"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        {['Y-m-d', 'd-m-Y', 'm/d/Y', 'd M Y'].map((f) => (
                            <SelectItem key={f} value={f}>{f}</SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
            <Field label="Timezone" error={form.errors.timezone}>
                <Select value={form.data.timezone} onValueChange={(v) => form.setData('timezone', v)}>
                    <SelectTrigger className="w-64"><SelectValue /></SelectTrigger>
                    <SelectContent className="max-h-72">
                        {timezones.map((tz) => (
                            <SelectItem key={tz} value={tz}>{tz}</SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
            <Button type="submit" disabled={form.processing}>{t('Save')}</Button>
        </form>
    );
}

function CurrencyForm({ settings }: Props) {
    const { t } = useTranslation();
    const form = useForm({
        currencyCode: settings.currencyCode ?? 'USD',
        currencySymbol: settings.currencySymbol ?? '$',
        currencyPosition: settings.currencyPosition ?? 'before',
        currencyDecimals: settings.currencyDecimals ?? '2',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('settings.currency.update'), { preserveScroll: true });
    };

    const preview = (1234.5).toFixed(Number(form.data.currencyDecimals) || 0);

    return (
        <form onSubmit={submit} className="space-y-5">
            <div className="grid max-w-xl grid-cols-2 gap-4">
                <Field label="Currency code" error={form.errors.currencyCode}>
                    <Input value={form.data.currencyCode} maxLength={3} onChange={(e) => form.setData('currencyCode', e.target.value.toUpperCase())} />
                </Field>
                <Field label="Symbol" error={form.errors.currencySymbol}>
                    <Input value={form.data.currencySymbol} maxLength={5} onChange={(e) => form.setData('currencySymbol', e.target.value)} />
                </Field>
                <Field label="Symbol position" error={form.errors.currencyPosition}>
                    <Select value={form.data.currencyPosition} onValueChange={(v) => form.setData('currencyPosition', v)}>
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="before">Before amount</SelectItem>
                            <SelectItem value="after">After amount</SelectItem>
                        </SelectContent>
                    </Select>
                </Field>
                <Field label="Decimals" error={form.errors.currencyDecimals}>
                    <Input type="number" min={0} max={4} value={form.data.currencyDecimals} onChange={(e) => form.setData('currencyDecimals', e.target.value)} />
                </Field>
            </div>
            <p className="text-sm text-muted-foreground">
                Preview: <span className="font-semibold text-foreground">
                    {form.data.currencyPosition === 'after' ? `${preview}${form.data.currencySymbol}` : `${form.data.currencySymbol}${preview}`}
                </span>
            </p>
            <Button type="submit" disabled={form.processing}>{t('Save')}</Button>
        </form>
    );
}

export default function SettingsIndex({ settings, themeColors }: Props) {
    const { t } = useTranslation();
    const [tab, setTab] = useState<Tab>('Brand');

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Settings')}</h2>}>
            <Head title={t('Settings')} />

            <div className="mx-auto max-w-4xl space-y-4 px-4 py-8 sm:px-6">
                <div className="flex gap-1 rounded-lg bg-muted p-1">
                    {TABS.map((name) => (
                        <button
                            key={name}
                            type="button"
                            onClick={() => setTab(name)}
                            className={cn(
                                'flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                tab === name ? 'bg-background shadow' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {t(name)}
                        </button>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>{t(tab)}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {tab === 'Brand' && <BrandForm settings={settings} themeColors={themeColors} />}
                        {tab === 'System' && <SystemForm settings={settings} themeColors={themeColors} />}
                        {tab === 'Currency' && <CurrencyForm settings={settings} themeColors={themeColors} />}
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
