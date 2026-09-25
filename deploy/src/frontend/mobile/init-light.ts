// ============================================================
// src/frontend/mobile/init-light.ts – mobile_light Vue Bootstrap (P5 Mobile)
// ============================================================
// Companion-Modus für mobile_light.html.
// Mountet Vue-Inseln für SessionBadge + reaktive History-Liste.
//
// ROLLBACK: <script type="module" src="dist/mobile-light-init.…"> entfernen.
// ============================================================

import { createApp, ref } from 'vue';
import { loadSession }    from '@core/auth.ts';
import { getState }       from '@core/store.ts';
import SessionBadge       from '../components/SessionBadge.vue';
import LightHistoryList   from './LightHistoryList.vue';

const histRefreshKey = ref(0);

// ── Sofort mounten ────────────────────────────────────────────
(function mountLightIslands() {
    // Session-Badge im Topbar
    const badgeEl = document.getElementById('bk-light-session');
    if (badgeEl) {
        createApp(SessionBadge).mount(badgeEl);
    }

    // ── REVERT (v2.10.66): LightHistoryList-Island deaktiviert ──────────
    // Die native renderHistory() in mobile_light.html rendert #historySection
    // (inkl. Bearbeiten via editLightEntry / Löschen via deleteEntry) zuverlässig.
    // Grund: Buchungen ließen sich im Vue-Island nicht bearbeiten/löschen.
    // LightHistoryList.vue bleibt im Code, wird aber NICHT gemountet.
    console.log('[BK Light] Island: nur SessionBadge (History: native)');
})();

/** @deprecated Vue-Island deaktiviert – siehe mountLightIslands() */
function _mountLightVueIsland() {
    const ready = document.readyState !== 'loading'
        ? Promise.resolve()
        : new Promise<void>(r => document.addEventListener('DOMContentLoaded', () => r()));

    ready.then(() => {
        const histEl = document.getElementById('historySection');
        if (histEl) {
            histEl.innerHTML = '';
            createApp(LightHistoryList, { refreshKey: histRefreshKey }).mount(histEl);
        }
        const w = window as unknown as Record<string, unknown>;
        w['renderHistory'] = () => { histRefreshKey.value += 1; };
    });
}
void _mountLightVueIsland;

// ── Bootstrap ─────────────────────────────────────────────────
async function bootstrap(): Promise<void> {
    try {
        const session = await loadSession();
        if (!session.loggedIn) {
            console.log('[BK Light] Nicht angemeldet.');
            return;
        }
        const { user } = getState();
        console.log('[BK Light] Session:', user?.username, '| Rolle:', user?.role);
    } catch (err) {
        console.error('[BK Light] Bootstrap fehlgeschlagen:', err);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
} else {
    bootstrap();
}
