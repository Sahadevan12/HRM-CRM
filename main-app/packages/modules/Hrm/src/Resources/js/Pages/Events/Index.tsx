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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, MapPin, Plus } from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

interface EventRow {
    id: number;
    title: string;
    event_type_id: number | null;
    start_date: string;
    end_date: string;
    start_time: string | null;
    location: string | null;
    description: string | null;
    type: { name: string; color: string } | null;
    department_ids: number[];
    departments: { id: number; name: string }[];
}

interface Props {
    events: EventRow[];
    month: string;
    from: string;
    to: string;
    types: { id: number; name: string; color: string }[];
    departments: { id: number; name: string }[];
    can: { create: boolean; edit: boolean; delete: boolean };
}

const NONE = 'none';
const empty = { title: '', event_type_id: NONE, start_date: '', end_date: '', start_time: '', location: '', description: '', department_ids: [] as number[] };
const day = (d: string) => d.slice(0, 10);

const shiftMonth = (month: string, by: number) => {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(Date.UTC(y, m - 1 + by, 1));
    return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
};

/** Month calendar (Monday first). The server sends every event that touches the visible weeks. */
export default function EventsIndex({ events, month, from, to, types, departments, can }: Props) {
    const { t } = useTranslation();
    const [editing, setEditing] = useState<EventRow | null>(null);
    const [open, setOpen] = useState(false);
    const [deleting, setDeleting] = useState<EventRow | null>(null);
    const form = useForm(empty);

    const days = useMemo(() => {
        const out: string[] = [];
        for (let d = new Date(`${from}T00:00:00Z`); d <= new Date(`${to}T00:00:00Z`); d.setUTCDate(d.getUTCDate() + 1)) out.push(d.toISOString().slice(0, 10));
        return out;
    }, [from, to]);

    const go = (m: string) => router.get(route('hrm.events.index'), { month: m }, { preserveState: true, replace: true });

    const openCreate = (date?: string) => {
        setEditing(null);
        form.setData({ ...empty, start_date: date ?? '', end_date: date ?? '' });
        form.clearErrors();
        setOpen(true);
    };

    const openEdit = (e: EventRow) => {
        if (!can.edit) return;
        setEditing(e);
        form.setData({
            title: e.title,
            event_type_id: e.event_type_id ? String(e.event_type_id) : NONE,
            start_date: day(e.start_date),
            end_date: day(e.end_date),
            start_time: e.start_time ? e.start_time.slice(0, 5) : '',
            location: e.location ?? '',
            description: e.description ?? '',
            department_ids: e.department_ids,
        });
        form.clearErrors();
        setOpen(true);
    };

    const submit = (ev: FormEvent) => {
        ev.preventDefault();
        form.transform((d) => ({ ...d, event_type_id: d.event_type_id === NONE ? null : d.event_type_id, start_time: d.start_time || null }));
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };
        if (editing) form.put(route('hrm.events.update', editing.id), options);
        else form.post(route('hrm.events.store'), options);
    };

    const toggleDept = (id: number, on: boolean) => form.setData('department_ids', on ? [...form.data.department_ids, id] : form.data.department_ids.filter((d) => d !== id));
    const err = (k: string) => (form.errors as Record<string, string>)[k] && <p className="text-sm text-destructive">{(form.errors as Record<string, string>)[k]}</p>;
    const label = new Date(`${month}-01T00:00:00Z`).toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' });

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Events')}</h2>}>
            <Head title={t('Events')} />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <Button size="icon" variant="outline" onClick={() => go(shiftMonth(month, -1))}><ChevronLeft className="h-4 w-4" /></Button>
                        <div className="w-44 text-center font-medium">{label}</div>
                        <Button size="icon" variant="outline" onClick={() => go(shiftMonth(month, 1))}><ChevronRight className="h-4 w-4" /></Button>
                    </div>
                    {can.create && <Button onClick={() => openCreate()}><Plus className="mr-1 h-4 w-4" />{t('Add Event')}</Button>}
                </div>

                <Card>
                    <CardContent className="overflow-x-auto p-0">
                        <div className="grid min-w-[44rem] grid-cols-7 border-b text-center text-xs font-medium text-muted-foreground">
                            {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => <div key={d} className="p-2">{t(d)}</div>)}
                        </div>
                        <div className="grid min-w-[44rem] grid-cols-7">
                            {days.map((d) => {
                                const todays = events.filter((e) => day(e.start_date) <= d && day(e.end_date) >= d);
                                const inMonth = d.startsWith(month);
                                return (
                                    <div key={d} className={`min-h-24 border-b border-r p-1 ${inMonth ? '' : 'bg-muted/40 text-muted-foreground'}`} onDoubleClick={() => can.create && openCreate(d)}>
                                        <div className="mb-1 text-right text-xs">{Number(d.slice(8))}</div>
                                        <div className="space-y-1">
                                            {todays.map((e) => (
                                                <button key={e.id} type="button" onClick={() => openEdit(e)} title={e.title}
                                                    className="block w-full truncate rounded px-1 py-0.5 text-left text-xs text-white" style={{ backgroundColor: e.type?.color ?? '#64748b' }}>
                                                    {e.start_time ? `${e.start_time.slice(0, 5)} ` : ''}{e.title}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </CardContent>
                </Card>

                {events.length === 0 && <p className="text-center text-sm text-muted-foreground">{t('No events in this period.')}</p>}
                <div className="space-y-2">
                    {events.filter((e) => day(e.end_date) >= `${month}-01` && day(e.start_date) <= `${month}-31`).map((e) => (
                        <div key={e.id} className="flex items-start gap-3 text-sm">
                            <span className="mt-1 h-3 w-3 shrink-0 rounded-full" style={{ backgroundColor: e.type?.color ?? '#64748b' }} />
                            <div>
                                <div className="font-medium">{e.title} <span className="font-normal text-muted-foreground">· {day(e.start_date)}{day(e.end_date) !== day(e.start_date) ? ` → ${day(e.end_date)}` : ''}</span></div>
                                {e.location && <div className="flex items-center gap-1 text-muted-foreground"><MapPin className="h-3 w-3" />{e.location}</div>}
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogHeader><DialogTitle>{editing ? t('Edit Event') : t('Add Event')}</DialogTitle></DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <div className="space-y-1"><Label>{t('Title')}</Label><Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />{err('title')}</div>
                        <div className="space-y-1">
                            <Label>{t('Type')}</Label>
                            <Select value={form.data.event_type_id} onValueChange={(v) => form.setData('event_type_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>{t('None')}</SelectItem>
                                    {types.map((x) => <SelectItem key={x.id} value={String(x.id)}>{x.name}</SelectItem>)}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid grid-cols-3 gap-3">
                            <div className="space-y-1"><Label>{t('From')}</Label><Input type="date" value={form.data.start_date} onChange={(e) => form.setData('start_date', e.target.value)} />{err('start_date')}</div>
                            <div className="space-y-1"><Label>{t('To')}</Label><Input type="date" value={form.data.end_date} onChange={(e) => form.setData('end_date', e.target.value)} />{err('end_date')}</div>
                            <div className="space-y-1"><Label>{t('Time')}</Label><Input type="time" value={form.data.start_time} onChange={(e) => form.setData('start_time', e.target.value)} />{err('start_time')}</div>
                        </div>
                        <div className="space-y-1"><Label>{t('Location')}</Label><Input value={form.data.location} onChange={(e) => form.setData('location', e.target.value)} />{err('location')}</div>
                        <div className="space-y-1"><Label>{t('Description')}</Label><Textarea value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />{err('description')}</div>
                        <div className="space-y-2">
                            <Label>{t('Departments')} ({t('none selected = everyone')})</Label>
                            <div className="grid grid-cols-2 gap-2">
                                {departments.map((d) => (
                                    <label key={d.id} className="flex items-center gap-2 text-sm">
                                        <Checkbox checked={form.data.department_ids.includes(d.id)} onCheckedChange={(v) => toggleDept(d.id, v === true)} />{d.name}
                                    </label>
                                ))}
                            </div>
                        </div>
                        <DialogFooter>
                            {editing && can.delete && <Button type="button" variant="destructive" className="mr-auto" onClick={() => { setDeleting(editing); setOpen(false); }}>{t('Delete')}</Button>}
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>{t('Cancel')}</Button>
                            <Button type="submit" disabled={form.processing}>{editing ? t('Update') : t('Create')}</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>{t('Delete Event?')}</AlertDialogTitle>
                        <AlertDialogDescription>{t('This action cannot be undone.')}</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>{t('Cancel')}</AlertDialogCancel>
                        <AlertDialogAction onClick={() => deleting && router.delete(route('hrm.events.destroy', deleting.id), { preserveScroll: true, onFinish: () => setDeleting(null) })}>{t('Delete')}</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AuthenticatedLayout>
    );
}
