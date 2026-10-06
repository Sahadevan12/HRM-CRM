import { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';

/** Format amounts with the company's currency settings (Settings > Currency). */
export function useMoney() {
    const { companyAllSetting } = usePage<PageProps>().props;
    const symbol = companyAllSetting.currencySymbol ?? '$';
    const decimals = Number(companyAllSetting.currencyDecimals ?? 2);
    const after = companyAllSetting.currencyPosition === 'after';

    return (amount: number | string | null | undefined) => {
        const number = Number(amount ?? 0).toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
        return after ? `${number}${symbol}` : `${symbol}${number}`;
    };
}
