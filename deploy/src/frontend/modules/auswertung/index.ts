// ============================================================
// auswertung/index.ts – Auswertung Vue Island Install (P4.4)
// ============================================================

import { ref, createApp } from 'vue';
import AuswertungView      from './AuswertungView.vue';

const refreshKey = ref(0);

let _app:      ReturnType<typeof createApp> | null = null;
let _installed = false;

const HIDE_IDS = [
    'emptyState', 'baustelleDetail', 'uebersichtView', 'materialKatalogView',
    'stundenKatalogView', 'offenesMaterialView', 'whatsappView',
    'schnellnotizenView', 'kundenstammView', 'dienstleisterView',
    'allgemeinSettingsView', 'rechnungenView', 'dashboardView',
    'din1090View', 'lagerView', 'aufmassView', 'vde0100View',
] as const;

export function install(): void {
    if (_installed) return;
    _installed = true;
    // REVERT v2.10.69 (Option A): Vue-Island deaktiviert – native showAuswertung()/
    // renderAuswertung() aus script.js aktiv. Vue-Code als Backup erhalten.
    console.log('[bk] auswertung: native script.js-Logik aktiv (Vue-Island deaktiviert)');
}

/** @deprecated Vue-Island deaktiviert (Backup) – siehe install() */
function _installVueIsland(): void {
    const container = document.getElementById('auswertungView');
    if (!container) {
        console.warn('[auswertung] #auswertungView nicht gefunden.');
        _installed = false;
        return;
    }

    container.innerHTML = '';
    _app = createApp(AuswertungView, { refreshKey });
    _app.mount(container);

    const w = window as unknown as Record<string, unknown>;

    w['showAuswertung'] = async () => {
        (w['selectedId'] as unknown) = null;
        document.querySelectorAll('.baustelle-item').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.sidebar-overview-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('btnAuswertung')?.classList.add('active');
        (w['renderSidebar'] as (() => void) | undefined)?.();

        for (const id of HIDE_IDS) {
            document.getElementById(id)?.classList.add('hidden');
        }
        container.classList.remove('hidden');
        refreshKey.value += 1;
    };

    // renderAuswertung: wird von script.js nach Daten-Patches aufgerufen
    w['renderAuswertung'] = () => { refreshKey.value += 1; };

    console.log('[bk] auswertung/AuswertungView.vue installiert');
}
void _installVueIsland; // Backup – nicht mehr aufgerufen

export function uninstall(): void {
    _app?.unmount();
    _app = null;
    _installed = false;
}
