import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useTranslation } from 'react-i18next';

interface Item {
    id: number;
    title: string;
    body: string;
    start_date: string;
    requires_acknowledgment: boolean;
    acknowledged: boolean;
    category: { name: string } | null;
}

export default function MyAnnouncements({ announcements, hasProfile }: { announcements: Item[]; hasProfile: boolean }) {
    const { t } = useTranslation();

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('My Announcements')}</h2>}>
            <Head title={t('My Announcements')} />

            <div className="mx-auto max-w-3xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                {!hasProfile && <Card><CardContent className="py-8 text-center text-muted-foreground">{t('Your login has no employee profile. Ask HR to create one.')}</CardContent></Card>}
                {hasProfile && announcements.length === 0 && <Card><CardContent className="py-8 text-center text-muted-foreground">{t('No announcements right now.')}</CardContent></Card>}
                {announcements.map((a) => (
                    <Card key={a.id}>
                        <CardContent className="space-y-3 p-5">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="text-lg font-semibold">{a.title}</h3>
                                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                    {a.category && <Badge variant="outline">{a.category.name}</Badge>}
                                    {a.start_date.slice(0, 10)}
                                </div>
                            </div>
                            <p className="whitespace-pre-line text-sm">{a.body}</p>
                            {a.requires_acknowledgment && (
                                a.acknowledged ? (
                                    <Badge><Check className="mr-1 h-3 w-3" />{t('Acknowledged')}</Badge>
                                ) : (
                                    <Button size="sm" onClick={() => router.post(route('hrm.announcements.acknowledge', a.id), {}, { preserveScroll: true })}>{t('I have read this')}</Button>
                                )
                            )}
                        </CardContent>
                    </Card>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
