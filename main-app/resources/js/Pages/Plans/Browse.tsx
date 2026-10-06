import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Check } from 'lucide-react';

interface Plan {
    id: number;
    name: string;
    description: string | null;
    monthly_price: number;
    yearly_price: number;
    max_users: number;
    free_plan: boolean;
    trial: boolean;
    trial_days: number;
    modules: string[] | null;
}

interface Props {
    plans: Plan[];
    currentPlan: { id: number; name: string | null; expires_at: string | null; is_trial: boolean; expired: boolean };
    moduleNames: Record<string, string>;
}

export default function PlansBrowse({ plans, currentPlan, moduleNames }: Props) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Plans</h2>}>
            <Head title="Plans" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
                <Card className={currentPlan.expired ? 'border-destructive' : ''}>
                    <CardContent className="flex flex-wrap items-center justify-between gap-2 py-4">
                        <div>
                            <div className="text-sm text-muted-foreground">Current plan</div>
                            <div className="text-lg font-semibold">{currentPlan.name ?? 'No active plan'}</div>
                        </div>
                        <div className="text-sm">
                            {currentPlan.expired ? (
                                <Badge variant="destructive">Expired – choose a plan to continue</Badge>
                            ) : currentPlan.expires_at ? (
                                <span>{currentPlan.is_trial ? 'Trial ends' : 'Renews / expires'} on {currentPlan.expires_at}</span>
                            ) : (
                                <Badge variant="secondary">No expiry</Badge>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {plans.map((plan) => (
                        <Card key={plan.id} className={plan.id === currentPlan.id && !currentPlan.expired ? 'border-primary' : ''}>
                            <CardHeader>
                                <CardTitle>{plan.name}</CardTitle>
                                <CardDescription>{plan.description}</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="text-3xl font-bold">
                                    {plan.free_plan ? 'Free' : plan.monthly_price}
                                    {!plan.free_plan && <span className="text-sm font-normal text-muted-foreground"> / month</span>}
                                </div>
                                {!plan.free_plan && <div className="text-sm text-muted-foreground">or {plan.yearly_price} / year</div>}
                                <ul className="space-y-1 text-sm">
                                    <li className="flex items-center gap-2"><Check className="h-4 w-4" /> {plan.max_users === -1 ? 'Unlimited' : plan.max_users} users</li>
                                    {(plan.modules ?? []).map((m) => (
                                        <li key={m} className="flex items-center gap-2"><Check className="h-4 w-4" /> {moduleNames[m] ?? m}</li>
                                    ))}
                                </ul>
                            </CardContent>
                            <CardFooter className="flex gap-2">
                                {plan.free_plan ? (
                                    <Button disabled={plan.id === currentPlan.id && !currentPlan.expired} onClick={() => router.post(route('plans.assign-free', plan.id))}>
                                        {plan.id === currentPlan.id && !currentPlan.expired ? 'Current plan' : 'Use free plan'}
                                    </Button>
                                ) : (
                                    <>
                                        <Button asChild>
                                            <Link href={route('plans.subscribe', plan.id)}>Subscribe</Link>
                                        </Button>
                                        {plan.trial && (
                                            <Button variant="outline" onClick={() => router.post(route('plans.start-trial', plan.id))}>
                                                Try {plan.trial_days} days free
                                            </Button>
                                        )}
                                    </>
                                )}
                            </CardFooter>
                        </Card>
                    ))}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
