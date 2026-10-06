import { PageProps } from '@/types';
import { applyTheme, readStoredMode, storeMode, ThemeMode } from '@/utils/theme';
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

/**
 * Light/dark/system mode + primary colour.
 * Colour = company setting. Mode = the user's own choice (localStorage), defaulting to the company setting.
 */
export function useAppearance() {
    const { companyAllSetting, adminAllSetting } = usePage<PageProps>().props;
    const settings = { ...adminAllSetting, ...companyAllSetting };

    const color = settings.themeColor ?? 'slate';
    const [mode, setModeState] = useState<ThemeMode>(readStoredMode() ?? (settings.themeMode as ThemeMode) ?? 'light');

    useEffect(() => {
        applyTheme(mode, color);

        if (mode !== 'system') return;
        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const listener = () => applyTheme(mode, color);
        media.addEventListener('change', listener);
        return () => media.removeEventListener('change', listener);
    }, [mode, color]);

    const setMode = useCallback((next: ThemeMode) => {
        storeMode(next);
        setModeState(next);
    }, []);

    return { mode, setMode };
}
