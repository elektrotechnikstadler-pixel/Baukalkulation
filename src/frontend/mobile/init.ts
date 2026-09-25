// ============================================================
// src/frontend/mobile/init.ts – Mobile Vue Bootstrap (P5 Mobile)
// ============================================================
// Companion-Modus für mobile.html: läuft parallel zur bestehenden
// mobilen JS-Laufzeit. Mountet Vue-Inseln in mobile-spezifische
// DOM-Container und bootstrapped den gemeinsamen Store.
//
// ROLLBACK: <script type="module" src="dist/mobile-init.…"> aus
//            mobile.html entfernen.
// ============================================================

import { createApp, ref } from 'vue';
import { loadSession }    from '@core/auth.ts';
import { getState }       from '@core/store.ts';
import SessionBadge          from '../components/SessionBadge.vue';
import MobileSeEntries       from './MobileSeEntries.vue';
import MobileWarningBanner   from './MobileWarningBanner.vue';
// MobileBaustellenList.vue: REVERT v2.10.65 – nicht mehr gemountet (native renderList)

// ── Reaktive Signale ──────────────────────────────────────────
const seRefreshKey      = ref(0);
const warnRefreshKey    = ref(0);

// ── Session-Badge mounten ──────────────────────────────────────
// Sofort mounten, bevor der Store befüllt ist – der Badge reagiert
// reaktiv sobald loadSession() den Store aktualisiert.
(function mountMobileIslands() {
    const badgeEl = document.getElementById('bk-mobile-session');
    if (badgeEl) {
        createApp(SessionBadge).mount(badgeEl);
    }

    // ── REVERT (v2.10.66): Mobile Zeiterfassungs-Islands deaktiviert ──────
    // MobileSeEntries.vue + MobileWarningBanner.vue werden NICHT mehr gemountet
    // und renderStundenerfassung() NICHT überschrieben. Grund: Buchungen ließen
    // sich im Vue-Island nicht bearbeiten/löschen. Die native
    // renderStundenerfassung() in mobile.html rendert #stundenerfassungScrollView
    // inkl. Bearbeiten/Löschen (showStundenerfassungSheet, delStundenerfassung)
    // + Warnhinweise (_renderSeMobileWarnings) zuverlässig. Alte Struktur.
    // Baustellen-Liste (P6) ebenfalls nativ (REVERT v2.10.65).
    console.log('[BK Mobile] Islands: nur SessionBadge (Zeiterfassung/Baustellen: native)');
})();

/** @deprecated Vue-Islands deaktiviert – siehe mountMobileIslands() */
function _mountMobileVueIslands() {
    const ready = document.readyState !== 'loading'
        ? Promise.resolve()
        : new Promise<void>(r => document.addEventListener('DOMContentLoaded', () => r()));
    ready.then(() => {
        const seEl = document.getElementById('stundenerfassungScrollView');
        if (seEl) {
            seEl.innerHTML = '';
            createApp(MobileSeEntries, { refreshKey: seRefreshKey }).mount(seEl);
        }
        const warnEl = document.getElementById('seMobileWarningBanner');
        if (warnEl) {
            warnEl.style.display = '';
            createApp(MobileWarningBanner, { refreshKey: warnRefreshKey }).mount(warnEl);
        }
        const w = window as unknown as Record<string, unknown>;
        w['renderStundenerfassung'] = () => {
            seRefreshKey.value   += 1;
            warnRefreshKey.value += 1;
        };
    });
}
void _mountMobileVueIslands;

// ── Mobile Bootstrap ────────────────────────────────────────────
// Lädt Session und befüllt den Store. Analog zum Desktop-Bootstrap
// aber ohne Feature-Module-Lazy-Imports (mobile hat eigene Module).
async function bootstrap(): Promise<void> {
    try {
        const session = await loadSession();
        if (!session.loggedIn) {
            console.log('[BK Mobile] Nicht angemeldet.');
            return;
        }
        const { user } = getState();
        console.log('[BK Mobile] Session:', user?.username, '| Rolle:', user?.role);
    } catch (err) {
        console.error('[BK Mobile] Bootstrap fehlgeschlagen:', err);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
} else {
    bootstrap();
}
