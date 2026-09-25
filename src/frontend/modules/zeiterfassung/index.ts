// zeiterfassung/index.ts – Adapter + Vue Islands (P5)
// SeEntriesTable.vue: reaktive Eintrags-Tabelle im #stundenerfassungModal.
// Die statische Formular-Logik (addStundenerfassungDesktop etc.) bleibt in script.js.

import { ref, createApp } from 'vue';
import SeEntriesTable      from './SeEntriesTable.vue';

// ── Reaktive Signale ──────────────────────────────────────────
import type { ZeitEntry } from './types.ts';
const entries      = ref<ZeitEntry[]>([]);
const searchQ      = ref('');
const isErweitert  = ref(false);

let _tableApp: ReturnType<typeof createApp> | null = null;
let _installed = false;

export function install(): void {
    if (_installed) return;
    _installed = true;

    // ── REVERT (v2.10.66): Zeiterfassungs-Vue-Island deaktiviert ────────
    // Die native renderSeDesktop() in script.js rendert die Eintrags-Tabelle
    // (#seDesktopThead/#seDesktopBody) inkl. Bearbeiten/Löschen zuverlässig.
    // Grund: Bearbeiten/Löschen der Buchungen funktionierte im Vue-Island nicht.
    // Der User wünscht die alte Struktur. SeEntriesTable.vue bleibt im Code.
    console.log('[bk] zeiterfassung: native script.js-Logik aktiv (Vue-Island deaktiviert)');
}

/** @deprecated Vue-Island deaktiviert – siehe install() */
function _installVueIsland(): void {
    const tableRoot = document.getElementById('bk-se-table-root');
    if (!tableRoot) {
        console.warn('[zeiterfassung] #bk-se-table-root nicht gefunden.');
        _installed = false;
        return;
    }

    // ── Vue Eintrags-Tabelle mounten ──────────────────────────
    _tableApp = createApp(SeEntriesTable, { entries, searchQ, isErweitert });
    _tableApp.mount(tableRoot);

    // ── renderSeDesktop überschreiben ─────────────────────────
    const w = window as unknown as Record<string, unknown>;

    w['renderSeDesktop'] = () => {
        // Einträge + virtuelle Feiertags-Einträge aus Globals lesen
        const rawEntries = (w['zeitEntriesDesktop'] as ZeitEntry[] | undefined) ?? [];
        const sollTag    = (w['seDesktopSollTag']   as number | undefined) ?? 8;
        const atSetRaw   = w['seDesktopArbeitstage'];
        const stw        = (w['seDesktopSollTageWoche'] as number | undefined) ?? 5;
        const atSet: Set<number> = atSetRaw instanceof Set ? atSetRaw
            : ((w['getUserArbeitstage'] as ((o: object) => Set<number>) | undefined)?.({ sollTageWoche: stw }) ?? new Set([1,2,3,4,5]));
        const now = new Date();

        // Virtuelle Feiertags-Einträge berechnen (wie script.js)
        const vtFeiertage: ZeitEntry[] = (w['getVirtuelleFeiertagEintraege'] as
            ((e: ZeitEntry[], s: number, a: Set<number>, y: number, m: number) => ZeitEntry[]) | undefined)
            ?.(rawEntries, sollTag, atSet, now.getFullYear(), now.getMonth()) ?? [];

        entries.value     = [...rawEntries, ...vtFeiertage];
        searchQ.value     = (document.getElementById('seDesktopSearch') as HTMLInputElement | null)?.value ?? '';
        isErweitert.value = !!(w['appSettings'] as Record<string, unknown> | undefined)?.['erweiterte_zeiterfassung'];

        // KPI-Karten werden WEITERHIN von script.js geschrieben (seMonthKpi).
        // Nur tbody ist jetzt Vue-verwaltet.
        const kpiEl = document.getElementById('seMonthKpi');
        if (kpiEl) {
            // Trigger KPI-Berechnung über die ursprüngliche Logik (isoliert im DOM)
            const origKpi = w['_renderSeKpi'] as (() => void) | undefined;
            origKpi?.();
        }
    };

    console.log('[bk] zeiterfassung/SeEntriesTable.vue installiert');
}
void _installVueIsland; // reference to avoid unused warning

export function uninstall(): void {
    _tableApp?.unmount();
    _tableApp = null;
    _installed = false;
}

// ── Öffentliche API ───────────────────────────────────────────
export function openStundenerfassungModal(): void {
    (window as unknown as Record<string, () => void>)['openStundenerfassungModal']?.();
}
export function closeStundenerfassungModal(): void {
    (window as unknown as Record<string, () => void>)['closeStundenerfassungModal']?.();
}
export function openStundenauswertungModal(): void {
    (window as unknown as Record<string, () => void>)['openStundenauswertungModal']?.();
}
export function openZeituebersichtModal(): void {
    (window as unknown as Record<string, () => void>)['openZeituebersichtModal']?.();
}


