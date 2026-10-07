import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useTranslation } from 'react-i18next';

interface Props {
    workingDays: number[];
    lateGraceMinutes: number;
}

const DAYS: [number, string][] = [[1, 'Monday'], [2, 'Tuesday'], [3, 'Wednesday'], [4, 'Thursday'], [5, 'Friday'], [6, 'Saturday'], [7, 'Sunday']];

export default function HrmSettings({ workingDays, lateGraceMinutes }: Props) {
    const { t } = useTranslation();
    const form = useForm({ working_days: workingDays, late_grace_minutes: lateGraceMinutes });

    const toggle = (day: number, on: boolean) =>
        form.setData('working_days', on ? [...form.data.working_days, day].sort() : form.data.working_days.filter((d) => d !== day));

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('hrm.settings.update'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('HRM Settings')}</h2>}>
            <Head title={t('HRM Settings')} />

            <div className="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8">
                <form onSubmit={submit}>
                    <Card>
                        <CardHeader><CardTitle>{t('Attendance rules')}</CardTitle></CardHeader>
                        <CardContent className="space-y-6">
                            <div className="space-y-2">
                                <Label>{t('Working days')}</Label>
                                <div className="grid grid-cols-2 gap-2">
                                    {DAYS.map(([n, name]) => (
                                        <label key={n} className="flex items-center gap-2 text-sm">
                                            <Checkbox checked={form.data.working_days.includes(n)} onCheckedChange={(v) => toggle(n, v === true)} />
                                            {t(name)}
                                        </label>
                                    ))}
                                </div>
                                {form.errors.working_days && <p className="text-sm text-destructive">{form.errors.working_days}</p>}
                                <p className="text-xs text-muted-foreground">{t('The other days are weekly offs: they are never counted as absent or as leave days.')}</p>
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="grace">{t('Late grace period (minutes)')}</Label>
                                <Input id="grace" type="number" min={0} max={180} value={form.data.late_grace_minutes} onChange={(e) => form.setData('late_grace_minutes', Number(e.target.value))} className="w-40" />
                                {form.errors.late_grace_minutes && <p className="text-sm text-destructive">{form.errors.late_grace_minutes}</p>}
                                <p className="text-xs text-muted-foreground">{t('Clocking in later than the shift start by more than this is counted as late.')}</p>
                            </div>
                            <Button type="submit" disabled={form.processing}>{t('Save')}</Button>
                        </CardContent>
                    </Card>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
