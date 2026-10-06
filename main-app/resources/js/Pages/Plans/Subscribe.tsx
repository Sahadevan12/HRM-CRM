import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { cn } from '@/lib/utils';
import { Head, Link, useForm } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, useState } from 'react';

interface Props {
    plan: { id: number; name: string; monthly_price: number; yearly_price: number; modules: string[] | null };
    bankTransferDetails: string | null;
    moduleNames: Record<string, string>;
}

interface Quote {
    price: number;
    discount: number;
    final_price: number;
}

export default function Subscribe({ plan, bankTransferDetails }: Props) {
    const form = useForm<{ plan_id: number; duration: 'month' | 'year'; coupon_code: string; notes: string; attachment: File | null }>({
        plan_id: plan.id,
        duration: 'month',
        coupon_code: '',
        notes: '',
        attachment: null,
    });
    const [quote, setQuote] = useState<Quote | null>(null);
    const [couponError, setCouponError] = useState<string | null>(null);

    const basePrice = form.data.duration === 'year' ? plan.yearly_price : plan.monthly_price;
    const shown = quote ?? { price: basePrice, discount: 0, final_price: basePrice };

    const changeDuration = (duration: 'month' | 'year') => {
        form.setData('duration', duration);
        setQuote(null); // a quote is only valid for the duration it was requested for
    };

    const applyCoupon = async () => {
        setCouponError(null);
        try {
            const { data } = await axios.post(route('plans.apply-coupon'), {
                plan_id: plan.id,
                duration: form.data.duration,
                coupon_code: form.data.coupon_code,
            });
            setQuote(data);
        } catch (e: any) {
            setQuote(null);
            setCouponError(e.response?.data?.error ?? e.response?.data?.message ?? 'Could not apply coupon');
        }
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('bank-transfers.store'), { forceFormData: true });
    };

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Subscribe to {plan.name}</h2>}>
            <Head title={`Subscribe – ${plan.name}`} />

            <form onSubmit={submit} className="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Card>
                        <CardHeader><CardTitle>Billing period</CardTitle></CardHeader>
                        <CardContent className="grid grid-cols-2 gap-3">
                            {(['month', 'year'] as const).map((d) => (
                                <button
                                    key={d}
                                    type="button"
                                    onClick={() => changeDuration(d)}
                                    className={cn('rounded-lg border p-4 text-left', form.data.duration === d && 'border-primary ring-1 ring-primary')}
                                >
                                    <div className="font-medium capitalize">{d === 'month' ? 'Monthly' : 'Yearly'}</div>
                                    <div className="text-2xl font-bold">{d === 'month' ? plan.monthly_price : plan.yearly_price}</div>
                                </button>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Coupon</CardTitle></CardHeader>
                        <CardContent className="space-y-2">
                            <div className="flex gap-2">
                                <Input value={form.data.coupon_code} placeholder="Coupon code" onChange={(e) => { form.setData('coupon_code', e.target.value.toUpperCase()); setQuote(null); }} />
                                <Button type="button" variant="outline" onClick={applyCoupon} disabled={!form.data.coupon_code}>Apply</Button>
                            </div>
                            {couponError && <p className="text-sm text-destructive">{couponError}</p>}
                            {form.errors.coupon_code && <p className="text-sm text-destructive">{form.errors.coupon_code}</p>}
                            {quote && quote.discount > 0 && <p className="text-sm text-green-600">Coupon applied: -{quote.discount}</p>}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader><CardTitle>Bank transfer</CardTitle></CardHeader>
                        <CardContent className="space-y-4">
                            <div className="whitespace-pre-wrap rounded-md bg-muted p-3 text-sm">
                                {bankTransferDetails || 'Bank details have not been configured yet. Please contact the administrator.'}
                            </div>
                            <div className="space-y-1">
                                <Label>Payment proof (JPG, PNG or PDF, max 4 MB)</Label>
                                <Input type="file" accept=".jpg,.jpeg,.png,.pdf" onChange={(e) => form.setData('attachment', e.target.files?.[0] ?? null)} />
                                {form.errors.attachment && <p className="text-sm text-destructive">{form.errors.attachment}</p>}
                            </div>
                            <div className="space-y-1">
                                <Label>Notes (transaction reference)</Label>
                                <Textarea value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card className="h-fit">
                    <CardHeader><CardTitle>Order summary</CardTitle></CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        <div className="flex justify-between"><span>{plan.name} ({form.data.duration})</span><span>{shown.price}</span></div>
                        <div className="flex justify-between text-green-600"><span>Discount</span><span>-{shown.discount}</span></div>
                        <div className="flex justify-between border-t pt-2 text-base font-bold"><span>Total</span><span>{shown.final_price}</span></div>
                        <Button type="submit" className="mt-3 w-full" disabled={form.processing}>Submit payment</Button>
                        <Button asChild variant="ghost" className="w-full"><Link href={route('plans.index')}>Back to plans</Link></Button>
                    </CardContent>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
