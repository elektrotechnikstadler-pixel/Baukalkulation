// ============================================================
// kunden/index.ts – Kundenstamm Vue Island Install (P4.2)
// ============================================================
// Strategie: gezielter Mount NUR in #kundenListContainer.
// #kundenstammView-Header (Suche + Button) bleibt statisches HTML.
// Bridges:
//   window.renderKundenListe → aktualisiert searchQ → Vue filtert
//   window.loadKundenData    → erhöht refreshKey → Vue lädt neu
//   window.showKundenstamm   → Navigation + Signale
// ============================================================

import { ref, createApp }  from 'vue';
import KundenList           from './KundenList.vue';

// ── Reaktive Signale ──────────────────────────────────────────
const searchQ   = ref('');
const refreshKey= ref(0);

let _app:      ReturnType<typeof createApp> | null = null;
let _installed = false;

const HIDE_IDS = [
    'emptyState', 'baustelleDetail', 'uebersichtView', 'materialKatalogView',
    'stundenKatalogView', 'offenesMaterialView', 'whatsappView',
    'schnellnotizenView', 'dienstleisterView', 'allgemeinSettingsView',
    'rechnungenView', 'auswertungView', 'dashboardView',
    'din1090View', 'lagerView', 'aufmassView', 'vde0100View',
] as const;

export function install(): void {
    if (_installed) return;
    _installed = true;
    // REVERT v2.10.69 (Option A): Vue-Island deaktiviert – native showKundenstamm()/
    // renderKundenListe() aus script.js aktiv. Vue-Code als Backup erhalten.
    console.log('[bk] kunden: native script.js-Logik aktiv (Vue-Island deaktiviert)');
}

/** @deprecated Vue-Island deaktiviert (Backup) – siehe install() */
function _installVueIsland(): void {
    const container = document.getElementById('kundenListContainer');
    if (!container) {
        console.warn('[kunden] #kundenListContainer nicht gefunden.');
        _installed = false;
        return;
    }

    // ── 1) Vue App mounten ────────────────────────────────────
    container.innerHTML = '';
    _app = createApp(KundenList, { searchQ, refreshKey });
    _app.mount(container);

    // ── 2) Globale Bridges überschreiben ──────────────────────
    const w = window as unknown as Record<string, unknown>;

    // renderKundenListe: liest den Suchbegriff aus dem statischen Input
    // und gibt ihn an Vue weiter (kein innerHTML mehr)
    w['renderKundenListe'] = () => {
        const searchEl = document.getElementById('kundenSearch') as HTMLInputElement | null;
        searchQ.value = searchEl?.value ?? '';
    };

    // loadKundenData: löst Vue-Datenneu-Laden aus
    w['loadKundenData'] = () => { refreshKey.value += 1; };

    // showKundenstamm: Navigation + Signale
    w['showKundenstamm'] = () => {
        if (!(w['canDo'] as Function)?.('canReadKunden')) {
            (w['showNotification'] as Function)?.('Keine Berechtigung.');
            return;
        }
        (w['selectedId'] as unknown) = null;

        // Nav-Buttons aktivieren / deaktivieren
        ['btnUebersicht', 'btnMaterialKatalog', 'btnStundenKatalog',
         'btnOffenesMaterial', 'btnWhatsApp', 'btnSchnellnotizenSidebar',
         'btnDienstleister'].forEach(id => {
            document.getElementById(id)?.classList.remove('active');
        });
        document.getElementById('btnKundenstamm')?.classList.add('active');

        (w['renderSidebar'] as (() => void) | undefined)?.();

        // Andere Views ausblenden
        for (const id of HIDE_IDS) {
            document.getElementById(id)?.classList.add('hidden');
        }

        document.getElementById('kundenstammView')?.classList.remove('hidden');

        // Daten neu laden
        refreshKey.value += 1;
    };

    console.log('[bk] kunden/KundenList.vue installiert');
}
void _installVueIsland; // Backup – nicht mehr aufgerufen

export function uninstall(): void {
    _app?.unmount();
    _app = null;
    _installed = false;
}
