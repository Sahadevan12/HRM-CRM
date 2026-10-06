import { Button } from '@/Components/ui/button';
import { useMoney } from '@/hooks/useMoney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { Plus, Printer } from 'lucide-react';
import { useTranslation } from 'react-i18next';

interface Props {
    sale: {
        id: number;
        payment_method: string;
        amount_paid: number;
        amount_tendered: number;
        change_due: number;
        created_at: string;
        cashier: { name: string } | null;
        document: {
            id: number;
            number: string;
            doc_date: string;
            subtotal: number;
            discount_amount: number;
            tax_amount: number;
            total_amount: number;
            party: { name: string };
            warehouse: { name: string };
            items: { id: number; name: string; quantity: number; unit_price: number; discount_amount: number; total_amount: number; taxes: { id: number; name: string; rate: number }[] }[];
        };
    };
    companyName: string;
}

const METHOD_LABEL: Record<string, string> = { cash: 'Cash', card: 'Card', bank_transfer: 'Bank transfer', credit: 'On account' };

export default function Receipt({ sale, companyName }: Props) {
    const { t } = useTranslation();
    const money = useMoney();
    const doc = sale.document;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{t('Receipt')} {doc.number}</h2>}>
            <Head title={`${t('Receipt')} ${doc.number}`} />

            <div className="mx-auto max-w-sm space-y-4 px-4 py-8">
                <div className="flex gap-2 print:hidden">
                    <Button asChild className="flex-1"><Link href={route('pos.terminal')}><Plus className="mr-1 h-4 w-4" /> {t('New sale')}</Link></Button>
                    <Button variant="outline" className="flex-1" onClick={() => window.print()}><Printer className="mr-1 h-4 w-4" /> {t('Print')}</Button>
                </div>

                {/* thermal-printer style slip */}
                <div className="rounded-lg border bg-card p-5 font-mono text-sm print:border-0 print:p-0">
                    <div className="text-center">
                        <div className="text-base font-bold">{companyName}</div>
                        <div>{doc.warehouse.name}</div>
                        <div className="mt-1">{doc.number} · {doc.doc_date}</div>
                        <div>{t('Cashier')}: {sale.cashier?.name ?? '-'}</div>
                        {doc.party.name !== 'Walk-in Customer' && <div>{t('Customer')}: {doc.party.name}</div>}
                    </div>

                    <div className="my-3 border-y border-dashed py-2">
                        {doc.items.map((i) => (
                            <div key={i.id} className="py-1">
                                <div className="flex justify-between gap-2"><span>{i.name}</span><span>{money(i.total_amount)}</span></div>
                                <div className="text-xs text-muted-foreground">
                                    {i.quantity} × {money(i.unit_price)}
                                    {i.discount_amount > 0 && ` − ${money(i.discount_amount)}`}
                                    {i.taxes.length > 0 && ` (${i.taxes.map((x) => `${x.name} ${x.rate}%`).join(', ')})`}
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="space-y-1">
                        <div className="flex justify-between"><span>{t('Subtotal')}</span><span>{money(doc.subtotal)}</span></div>
                        {doc.discount_amount > 0 && <div className="flex justify-between"><span>{t('Discount')}</span><span>-{money(doc.discount_amount)}</span></div>}
                        {doc.tax_amount > 0 && <div className="flex justify-between"><span>{t('Tax')}</span><span>{money(doc.tax_amount)}</span></div>}
                        <div className="flex justify-between border-t border-dashed pt-1 text-base font-bold"><span>{t('TOTAL')}</span><span>{money(doc.total_amount)}</span></div>
                        <div className="flex justify-between pt-1"><span>{t(METHOD_LABEL[sale.payment_method] ?? sale.payment_method)}</span><span>{money(sale.payment_method === 'credit' ? 0 : sale.amount_tendered)}</span></div>
                        {sale.change_due > 0 && <div className="flex justify-between"><span>{t('Change')}</span><span>{money(sale.change_due)}</span></div>}
                        {sale.payment_method === 'credit' && <div className="flex justify-between"><span>{t('Balance due')}</span><span>{money(doc.total_amount)}</span></div>}
                    </div>

                    <div className="mt-4 text-center text-xs text-muted-foreground">{t('Thank you!')}</div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
