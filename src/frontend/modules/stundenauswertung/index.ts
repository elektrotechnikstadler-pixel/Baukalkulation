// stundenauswertung/index.ts – Vue Island (P5.3)
// SaContent.vue übernimmt #saContent. Die statischen Filterelemente
// (#saSearch, #saUserSelect etc.) bleiben im HTML; Vue bridget sie via
// onMounted-EventListeners. renderStundenauswertung() triggert Vue-Refresh.

import { ref, createApp } from 'vue';
import SaContent           from './SaContent.vue';

const refreshKey = ref(0);
let _app:       ReturnType<typeof createApp> | null = null;
let _installed  = false;

export function install(): void {
    if (_installed) return;
    _installed = true;

    // ── REVERT (v2.10.67): Stundenauswertungs-Vue-Island deaktiviert ────────
    // Die native renderStundenauswertung() in script.js rendert #saContent
    // inkl. Fehlbuchungs-Reparatur (repairFehlbuchung/repairAllFehlbuchungen →
    // reloadSaAfterRepair → renderStundenauswertung) korrekt. Grund: nach einer
    // Reparatur aktualisierte reloadSaAfterRepair nur die lokale saAllZeit, nicht
    // window.saAllZeit → das Vue-Island rechnete auf veralteten Daten. Der User
    // wünscht die alte, funktionierende Struktur. SaContent.vue bleibt im Code.
    console.log('[bk] stundenauswertung: native script.js-Logik aktiv (Vue-Island deaktiviert)');
}

/** @deprecated Vue-Island deaktiviert – siehe install() */
function _installVueIsland(): void {
    const container = document.getElementById('saContent');
    if (!container) {
        console.warn('[stundenauswertung] #saContent nicht gefunden.');
        _installed = false;
        return;
    }

    container.innerHTML = '';
    _app = createApp(SaContent, { refreshKey });
    _app.mount(container);

    const w = window as unknown as Record<string, unknown>;

    // Override: renderStundenauswertung → Vue-Refresh
    // (original schreibt content.innerHTML – Vue übernimmt das jetzt)
    w['renderStundenauswertung'] = () => { refreshKey.value += 1; };

    // Override: openStundenauswertungModal → lädt Daten + öffnet Modal
    const origOpen = w['openStundenauswertungModal'] as (() => Promise<void>) | undefined;
    w['openStundenauswertungModal'] = async () => {
        // Lade-Anzeige setzen (SaContent lauscht auf refreshKey)
        refreshKey.value += 1;
        // Original öffnen (zeigt Modal, lädt Daten, füllt User-Dropdown)
        await origOpen?.();
        // Nach Datenladen Vue neu rendern
        refreshKey.value += 1;
    };

    console.log('[bk] stundenauswertung/SaContent.vue installiert');
}
void _installVueIsland; // reference to avoid unused warning

export function uninstall(): void {
    _app?.unmount();
    _app = null;
    _installed = false;
}

export function openStundenauswertungModal(): void {
    (window as unknown as Record<string, () => void>)['openStundenauswertungModal']?.();
}
export function closeStundenauswertungModal(): void {
    (window as unknown as Record<string, () => void>)['closeStundenauswertungModal']?.();
}
