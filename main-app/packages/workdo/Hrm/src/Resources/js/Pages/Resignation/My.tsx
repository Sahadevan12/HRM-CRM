import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
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
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface Row {
    id: number;
    notice_date: string;
    last_working_date: string;
    reason: string | null;
    status: 'pending' | 'approved' | 'rejected';
    comment: string | null;
    applied: boolean;
}

interface Props {
    hasProfile: boolean;
    history: Row[];
    canApply: boolean;
    today: string;
}

const tone = { pending: 'secondary', approved: 'default', rejected: 'destructive' } as const;

export default function MyResignation({ hasProfile, history, canApply, today }: Props) {
    const { t } = useTranslation();
    const form = useForm({ last_working_date: '', reason: '' });
    const [confirming, setConfirming] = useState(false);
    const [withdrawing, setWithdrawing] = useState<Row | null>(null);

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setConfirming(true);
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('My Resignation')}</h2>}>
            <Head title={t('My Resignation')} />

            <div className="mx-auto max-w-3xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                {!hasProfile && <Card><CardContent className="py-8 text-center text-muted-foreground">{t('Your login has no employee profile. Ask HR to create one.')}</CardContent></Card>}

                {hasProfile && canApply && (
                    <Card>
                        <CardHeader><CardTitle className="text-base">{t('Hand in my resignation')}</CardTitle></CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="space-y-4">
                                <p className="text-sm text-muted-foreground">{t('Your notice date is today. HR approves or rejects it; nothing changes for you until it is approved and your last working day has come.')}</p>
                                <div className="space-y-1">
                                    <Label>{t('Last working day')}</Label>
                                    <Input type="date" min={today} value={form.data.last_working_date} onChange={(e) => form.setData('last_working_date', e.target.value)} className="max-w-xs" />
                                    {form.errors.last_working_date && <p className="text-sm text-destructive">{form.errors.last_working_date}</p>}
                                </div>
                                <div className="space-y-1">
                                    <Label>{t('Reason')} ({t('optional')})</Label>
                                    <Textarea value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                                </div>
                                <Button type="submit" variant="destructive" disabled={!form.data.last_working_date}>{t('Submit resignation')}</Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {hasProfile && !canApply && history.some((h) => h.status === 'pending') && (
                    <p className="text-sm text-muted-foreground">{t('You have a request waiting for HR. You can withdraw it below.')}</p>
                )}

                {history.map((r) => (
                    <Card key={r.id}>
                        <CardContent className="flex flex-wrap items-start justify-between gap-3 p-4 text-sm">
                            <div className="space-y-1">
                                <div><span className="text-muted-foreground">{t('Notice date')}:</span> {r.notice_date} · <span className="text-muted-foreground">{t('Last working day')}:</span> {r.last_working_date}</div>
                                {r.reason && <div className="text-muted-foreground">{r.reason}</div>}
                                {r.comment && <div>{t('HR comment')}: {r.comment}</div>}
                                {r.status === 'approved' && !r.applied && <div className="text-xs text-muted-foreground">{t('Applies on your last working day')}</div>}
                            </div>
                            <div className="flex items-center gap-2">
                                <Badge variant={tone[r.status]}>{t(r.status)}</Badge>
                                {r.status === 'pending' && <Button size="sm" variant="outline" onClick={() => setWithdrawing(r)}>{t('Withdraw')}</Button>}
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <AlertDialog open={confirming} onOpenChange={setConfirming}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Submit your resignation?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('Your last working day will be')} {form.data.last_working_date}.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => form.post(route('hrm.resignation.store'), { preserveScroll: true, onFinish: () => setConfirming(false) })}>{t('Submit')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog open={!!withdrawing} onOpenChange={(o) => !o && setWithdrawing(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Withdraw your resignation?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('HR will not see it any more.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => withdrawing && router.delete(route('hrm.resignation.withdraw', withdrawing.id), { preserveScroll: true, onFinish: () => setWithdrawing(null) })}>{t('Withdraw')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
