// wochenplanung/index.ts – Vue Shell (P5)
// WochenplanungShell.vue liefert Lade-State + Fehler-Chrome.
// Die komplexe Timeline (Drag&Drop, 15s-Polling) bleibt in script.js.

import { createApp } from 'vue';
import WochenplanungShell from './WochenplanungShell.vue';

let _app:       ReturnType<typeof createApp> | null = null;
let _shellRef:  { setLoading: (v: boolean) => void; setError: (msg: string|null) => void } | null = null;
let _installed  = false;

export function install(): void {
    if (_installed) return;
    _installed = true;

    // #bk-wp-shell wird vor #wpContent in index.html platziert
    const shellEl = document.getElementById('bk-wp-shell');
    if (!shellEl) {
        console.warn('[wochenplanung] #bk-wp-shell nicht gefunden.');
        _installed = false;
        return;
    }

    _app      = createApp(WochenplanungShell);
    _shellRef = _app.mount(shellEl) as unknown as typeof _shellRef;

    const w = window as unknown as Record<string, unknown>;

    // Override: openWochenplanungModal → Loading-State + Original
    const origOpen = w['openWochenplanungModal'] as (() => Promise<void>) | undefined;
    w['openWochenplanungModal'] = async () => {
        _shellRef?.setLoading(true);
        _shellRef?.setError(null);
        try {
            await origOpen?.();
        } catch (e) {
            _shellRef?.setError('Fehler beim Laden des Wochenplans.');
        } finally {
            _shellRef?.setLoading(false);
        }
    };

    // Override: renderWochenplanung → Loading beenden
    const origRender = w['renderWochenplanung'] as (() => void) | undefined;
    w['renderWochenplanung'] = () => {
        _shellRef?.setLoading(false);
        origRender?.();
    };

    console.log('[bk] wochenplanung/WochenplanungShell.vue installiert');
}

export function uninstall(): void {
    _app?.unmount();
    _app = null;
    _shellRef = null;
    _installed = false;
}

export function openWochenplanungModal(): void {
    (window as unknown as Record<string, () => void>)['openWochenplanungModal']?.();
}
export function closeWochenplanungModal(): void {
    (window as unknown as Record<string, () => void>)['closeWochenplanungModal']?.();
}
