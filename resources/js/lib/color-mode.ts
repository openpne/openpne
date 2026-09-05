import { useEffect, useState } from 'react';

export type ColorMode = 'light' | 'dark' | 'system';

const STORAGE_KEY = 'openpne-color-mode';

function systemPrefersDark(): boolean {
    if (typeof window === 'undefined') return false;
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function readStored(): ColorMode {
    if (typeof window === 'undefined') return 'system';
    const v = window.localStorage.getItem(STORAGE_KEY);
    return v === 'light' || v === 'dark' || v === 'system' ? v : 'system';
}

function applyColorMode(mode: ColorMode): void {
    if (typeof document === 'undefined') return;
    const isDark = mode === 'dark' || (mode === 'system' && systemPrefersDark());
    document.documentElement.classList.toggle('dark', isDark);

    // Light follows the per-site brand color (app.blade.php stamps it on <html>); dark keeps the
    // shell's own slate-900 chrome, which the brand color never overrides.
    const chromeColor = isDark ? '#0f172a' : (document.documentElement.dataset.themeColorLight ?? '#2563eb');
    let meta = document.querySelector<HTMLMetaElement>('meta[name="theme-color"]');
    if (!meta) {
        meta = document.createElement('meta');
        meta.name = 'theme-color';
        document.head.appendChild(meta);
    }
    meta.content = chromeColor;
}

// Apply the stored mode at module load (the pre-paint script in app.blade.php already set the class
// to avoid a flash; this keeps it in sync) and follow OS changes while in `system` mode.
let initialized = false;
function init(): void {
    if (initialized || typeof window === 'undefined') return;
    initialized = true;
    applyColorMode(readStored());
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (readStored() === 'system') applyColorMode('system');
    });
}
init();

export function useColorMode(): {
    preference: ColorMode;
    set: (mode: ColorMode) => void;
} {
    const [preference, setPreference] = useState<ColorMode>(() => readStored());

    useEffect(() => {
        applyColorMode(preference);
    }, [preference]);

    return {
        preference,
        set: (mode: ColorMode) => {
            window.localStorage.setItem(STORAGE_KEY, mode);
            setPreference(mode);
        },
    };
}
