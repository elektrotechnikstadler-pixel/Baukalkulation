// ============================================================
// dashboard/index.ts – Dashboard Vue Island Install (P4.3)
// ============================================================
// Override von window.showDashboard → Navigation + Vue-Signals.
// Override von window.renderDashboard → Vue übernimmt Rendering
// (kein innerHTML mehr auf #dashboardView).
// ============================================================

import { ref, createApp } from 'vue';
import DashboardView       from './DashboardView.vue';

const refreshKey = ref(0);

let _app:       ReturnType<typeof createApp> | null = null;
let _installed  = false;

const HIDE_IDS = [
    'emptyState', 'baustelleDetail', 'uebersichtView', 'materialKatalogView',
    'stundenKatalogView', 'offenesMaterialView', 'whatsappView',
    'schnellnotizenView', 'kundenstammView', 'dienstleisterView',
    'allgemeinSettingsView', 'rechnungenView', 'auswertungView',
    'din1090View', 'lagerView', 'aufmassView', 'vde0100View',
] as const;

export function install(): void {
    if (_installed) return;
    _installed = true;
    // REVERT v2.10.69 (Option A): Vue-Island deaktiviert – native showDashboard()/
    // renderDashboard() aus script.js aktiv. Vue-Code als Backup erhalten.
    console.log('[bk] dashboard: native script.js-Logik aktiv (Vue-Island deaktiviert)');
}

/** @deprecated Vue-Island deaktiviert (Backup) – siehe install() */
function _installVueIsland(): void {
    const container = document.getElementById('dashboardView');
    if (!container) {
        console.warn('[dashboard] #dashboardView nicht gefunden.');
        _installed = false;
        return;
    }

    // ── 1) Vue App mounten ────────────────────────────────────
    container.innerHTML = '';
    _app = createApp(DashboardView, { refreshKey });
    _app.mount(container);

    // ── 2) Globale Overrides ──────────────────────────────────
    const w = window as unknown as Record<string, unknown>;

    // showDashboard: Navigation + Vue-Refresh
    w['showDashboard'] = async () => {
        if (!(w['canDo'] as Function)?.('canReadDashboard')) return;
        (w['selectedId'] as unknown) = null;

        document.querySelectorAll('.sidebar-overview-btn')
            .forEach(b => b.classList.remove('active'));
        document.getElementById('btnDashboard')?.classList.add('active');
        (w['renderSidebar'] as (() => void) | undefined)?.();

        for (const id of HIDE_IDS) {
            document.getElementById(id)?.classList.add('hidden');
        }
        container.classList.remove('hidden');

        refreshKey.value += 1;
    };

    // renderDashboard: wird von script.js nach toggleDashboardStatus /
    // moveDashboardItem etc. aufgerufen → Vue neu laden
    w['renderDashboard'] = () => { refreshKey.value += 1; };

    // loadDashboard: analog zu script.js (Vue übernimmt Laden)
    w['loadDashboard'] = () => { refreshKey.value += 1; };

    console.log('[bk] dashboard/DashboardView.vue installiert');
}
void _installVueIsland; // Backup – nicht mehr aufgerufen

export function uninstall(): void {
    _app?.unmount();
    _app = null;
    _installed = false;
}
