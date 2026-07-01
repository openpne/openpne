import { useEffect, useState } from 'react';

export type ThemePreference = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'openpne-theme';

function systemPrefersDark(): boolean {
    if (typeof window === 'undefined') return false;
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function readStored(): ThemePreference {
    if (typeof window === 'undefined') return 'system';
    const v = window.localStorage.getItem(STORAGE_KEY);
    return v === 'light' || v === 'dark' || v === 'system' ? v : 'system';
}

/** Toggle the `.dark` class on <html> and match the browser chrome color to the resolved theme. */
function applyTheme(pref: ThemePreference): void {
    if (typeof document === 'undefined') return;
    const isDark = pref === 'dark' || (pref === 'system' && systemPrefersDark());
    document.documentElement.classList.toggle('dark', isDark);

    const themeColor = isDark ? '#0f172a' : '#2563eb'; // slate-900 / blue-600
    let meta = document.querySelector<HTMLMetaElement>('meta[name="theme-color"]');
    if (!meta) {
        meta = document.createElement('meta');
        meta.name = 'theme-color';
        document.head.appendChild(meta);
    }
    meta.content = themeColor;
}

// Apply the stored preference at module load (the pre-paint script in app.blade.php already set the
// class to avoid a flash; this keeps it in sync) and follow OS changes while in `system` mode.
let initialized = false;
function init(): void {
    if (initialized || typeof window === 'undefined') return;
    initialized = true;
    applyTheme(readStored());
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (readStored() === 'system') applyTheme('system');
    });
}
init();

export function useTheme(): {
    preference: ThemePreference;
    set: (pref: ThemePreference) => void;
} {
    const [preference, setPreference] = useState<ThemePreference>(() => readStored());

    useEffect(() => {
        applyTheme(preference);
    }, [preference]);

    return {
        preference,
        set: (pref: ThemePreference) => {
            window.localStorage.setItem(STORAGE_KEY, pref);
            setPreference(pref);
        },
    };
}
