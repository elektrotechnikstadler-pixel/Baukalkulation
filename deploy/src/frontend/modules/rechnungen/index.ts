// ============================================================
// rechnungen/index.ts – Vue Island Install (P4)
// ============================================================
// Übernimmt die Rechnungen & Angebote-Ansicht von script.js:
//  1. Mounted RechnungenView.vue einmalig in #rechnungenView
//  2. Überschreibt window.showRechnungenView mit einer Vue-
//     kompatiblen Version (kein innerHTML mehr, nur reaktive Signale)
//
// Rollback: diese Datei / den dynamischen Import in init.ts entfernen.
// ============================================================

import { ref, createApp }  from 'vue';
import RechnungenView       from './RechnungenView.vue';

// ── Reaktive Signale (geteilt zwischen Override + Komponente) ─
const filterTyp  = ref<string | undefined>(undefined);
const refreshKey = ref(0);

// ── Interne Zustandsverwaltung ─────────────────────────────
let _app:       ReturnType<typeof createApp> | null = null;
let _installed  = false;

/** IDs aller Views die beim Navigieren zu Rechnungen versteckt werden */
const HIDE_IDS = [
    'emptyState', 'baustelleDetail', 'uebersichtView', 'materialKatalogView',
    'stundenKatalogView', 'offenesMaterialView', 'whatsappView',
    'schnellnotizenView', 'kundenstammView', 'dienstleisterView',
    'allgemeinSettingsView', 'auswertungView', 'dashboardView',
    'din1090View', 'lagerView', 'aufmassView', 'vde0100View',
] as const;

// ── Öffentliche API ───────────────────────────────────────────

/**
 * Installiert das Modul:
 *  - Mounted RechnungenView.vue in #rechnungenView
 *  - Überschreibt window.showRechnungenView
 * Idempotent – mehrfache Aufrufe sind sicher.
 */
export function install(): void {
    if (_installed) return;
    _installed = true;
    // REVERT v2.10.69 (Option A): Vue-Island deaktiviert – native showRechnungenView()
    // aus script.js aktiv. Vue-Code als Backup in _installVueIsland() erhalten.
    console.log('[bk] rechnungen: native script.js-Logik aktiv (Vue-Island deaktiviert)');
}

/** @deprecated Vue-Island deaktiviert (Backup) – siehe install() */
function _installVueIsland(): void {
    const container = document.getElementById('rechnungenView');
    if (!container) {
        console.warn('[rechnungen] #rechnungenView nicht gefunden – Modul nicht installiert.');
        _installed = false;
        return;
    }

    // ── 1) Vue App mounten ───────────────────────────────────
    // Container-Inhalt leeren damit Vue sauber mounten kann.
    container.innerHTML = '';
    _app = createApp(RechnungenView, { filterTyp, refreshKey });
    _app.mount(container);

    // ── 2) showRechnungenView überschreiben ──────────────────
    const w = window as unknown as Record<string, unknown>;
    w['showRechnungenView'] = (ft?: string) => {
        // Navigations-Logik (analog script.js, ohne innerHTML) ────
        (w['selectedId'] as unknown) = null;

        document.querySelectorAll('.baustelle-item')
            .forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.sidebar-overview-btn')
            .forEach(b => b.classList.remove('active'));

        document.getElementById('btnRechnungen')?.classList.add('active');
        if (ft === 'rechnung') document.getElementById('btnRechnungenSub')?.classList.add('active');
        if (ft === 'angebot')  document.getElementById('btnAngeboteSub')?.classList.add('active');

        // Sidebar neu rendern (script.js-Funktion)
        (w['renderSidebar'] as (() => void) | undefined)?.();

        // Andere Views ausblenden
        for (const id of HIDE_IDS) {
            document.getElementById(id)?.classList.add('hidden');
        }

        // Rechnungen-View einblenden
        container.classList.remove('hidden');

        // ── Reaktive Signale für Vue setzen ─────────────────
        filterTyp.value  = ft;
        refreshKey.value += 1;  // löst Datenneu-Laden aus
    };

    console.log('[bk] rechnungen/RechnungenView.vue installiert');
}
void _installVueIsland; // Backup – nicht mehr aufgerufen

/**
 * Modul deinstallieren (für Hot-Module-Replacement im Dev-Modus).
 * Restores the original showRechnungenView (falls gesichert).
 */
export function uninstall(): void {
    _app?.unmount();
    _app = null;
    _installed = false;
}
