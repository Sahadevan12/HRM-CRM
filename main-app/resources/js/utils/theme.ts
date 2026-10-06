/** Primary colour presets (HSL triplets for the --primary CSS variable) – [light, dark]. */
export const THEME_COLORS: Record<string, { light: string; dark: string; swatch: string }> = {
    slate: { light: '222.2 47.4% 11.2%', dark: '210 40% 98%', swatch: '#0f172a' },
    blue: { light: '221.2 83.2% 53.3%', dark: '217.2 91.2% 59.8%', swatch: '#2563eb' },
    green: { light: '142.1 76.2% 36.3%', dark: '142.1 70.6% 45.3%', swatch: '#16a34a' },
    violet: { light: '262.1 83.3% 57.8%', dark: '263.4 70% 50.4%', swatch: '#7c3aed' },
    rose: { light: '346.8 77.2% 49.8%', dark: '346.8 77.2% 49.8%', swatch: '#e11d48' },
    orange: { light: '24.6 95% 53.1%', dark: '20.5 90.2% 48.2%', swatch: '#f97316' },
};

export type ThemeMode = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'erp-theme-mode';

export function readStoredMode(): ThemeMode | null {
    try {
        const v = localStorage.getItem(STORAGE_KEY);
        return v === 'light' || v === 'dark' || v === 'system' ? v : null;
    } catch {
        return null;
    }
}

export function storeMode(mode: ThemeMode) {
    try {
        localStorage.setItem(STORAGE_KEY, mode);
    } catch {
        /* storage unavailable (private mode) – theme just won't persist */
    }
}

export function isDark(mode: ThemeMode): boolean {
    return mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
}

export function applyTheme(mode: ThemeMode, color: string) {
    const dark = isDark(mode);
    const preset = THEME_COLORS[color] ?? THEME_COLORS.slate;
    const root = document.documentElement;

    root.classList.toggle('dark', dark);
    root.style.setProperty('--primary', dark ? preset.dark : preset.light);
    // keep text on the primary colour readable
    root.style.setProperty('--primary-foreground', dark && color === 'slate' ? '222.2 47.4% 11.2%' : '210 40% 98%');
}
