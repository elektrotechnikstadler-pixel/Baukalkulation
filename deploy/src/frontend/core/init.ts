// ============================================================
// init.ts – Bootstrap-Einstiegspunkt (TypeScript)
// ============================================================
// Lädt Session-Daten von api.php, befüllt den Store und
// montiert lizenzierte Zusatzmodule dynamisch.
//
// Wird als `core`-Entry in Vite gebaut → dist/core.[hash].js
// und per <script type="module"> in index.html geladen.
//
// COMPANION-MODUS (Phase 5):
//   index.html lädt SOWOHL script.js (Haupt-Laufzeit) als auch
//   dist/core.[hash].js parallel. script.js bleibt primäre Laufzeit;
//   dieses Modul befüllt zusätzlich den ES-Modul-Store.
//
// ROLLBACK: <script type="module" src="dist/core…"> aus index.html entfernen.
// NÄCHSTER SCHRITT (Phase 6+): Feature-Code aus script.js in Vue-Komponenten
//   extrahieren; danach script.js aus index.html entfernen.
// ============================================================

import { loadSession } from './auth.ts';
import { getState }    from './store.ts';
import type { BkUser, BkLicense, BkModule } from './store.ts';

// ── Vue-Imports (P3 Islands) ──────────────────────────────────
import { createApp }   from 'vue';
import SessionBadge    from '../components/SessionBadge.vue';

// ── Typen ─────────────────────────────────────────────────────

export interface BootstrapResult {
    loggedIn: boolean;
    user:     BkUser    | null;
    license:  BkLicense | null;
    modules:  BkModule[];
    error?:   string;
}

// ── Bootstrap ─────────────────────────────────────────────────

/**
 * Bootstrap der Baukalkulation-ES-Frontend-Module.
 * Lädt Session, befüllt Store, gibt strukturiertes Ergebnis zurück.
 */
export async function bootstrap(): Promise<BootstrapResult> {
    try {
        const session = await loadSession();

        if (!session.loggedIn) {
            console.log('[BK] Nicht angemeldet.');
            return { loggedIn: false, user: null, license: null, modules: [] };
        }

        const { user, license, modules } = getState();

        console.log(
            '[BK] Session:', user?.username,
            '| Rolle:', user?.role,
            '| Lizenz-Tier:', license?.tier,
        );
        console.log(
            '[BK] Freigeschaltete Module:',
            modules.length ? modules.map(m => m.name).join(', ') : '(keine)',
        );

        // ── Phase 3+: Modul-Bundles dynamisch laden ────────────────
        // Jedes Modul mit einem `frontend.bundle`-Pfad wird geladen
        // und mit dem aktuellen Kontext gemountet.
        //
        // for (const mod of modules) {
        //     if (!mod.frontend?.bundle) continue;
        //     try {
        //         const { default: m } = await import(/* @vite-ignore */ mod.frontend.bundle);
        //         m.mount({ getState });
        //         console.log('[BK] Modul geladen:', mod.name);
        //     } catch (e) {
        //         console.warn('[BK] Modul-Ladefehler:', mod.name, e);
        //     }
        // }

        return { loggedIn: true, user, license, modules };

    } catch (err) {
        const msg = err instanceof Error ? err.message : String(err);
        console.error('[BK] Bootstrap fehlgeschlagen:', err);
        return { loggedIn: false, user: null, license: null, modules: [], error: msg };
    }
}

// ── Auto-Start ────────────────────────────────────────────────
// Automatisch starten wenn dieses Skript als <script type="module"> geladen wird.

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
} else {
    bootstrap();
}

// ── Vue Islands mounten ───────────────────────────────────────
// Wird direkt nach dem Laden gestartet (nicht nach Bootstrap), damit
// die Komponente sofort im DOM ist und reaktiv auf Store-Änderungen
// wartet. Store-Updates durch bootstrap() lösen dann Vue-Re-Renders aus.

(function mountVueIslands() {
    const badgeEl = document.getElementById('bk-session-island');
    if (badgeEl) {
        createApp(SessionBadge).mount(badgeEl);
    }
})();

// ── P4 Feature-Module installieren ────────────────────────────
// Dynamischer Import: Rechnungen-Modul als eigener Vite-Chunk.
// install() wird sofort aufgerufen, sobald der Chunk geladen ist.
// DOMContentLoaded stellt sicher, dass #rechnungenView im DOM ist.
(async function installFeatureModules() {
    const ready = document.readyState !== 'loading'
        ? Promise.resolve()
        : new Promise<void>(r => document.addEventListener('DOMContentLoaded', () => r()));
    await ready;

    try {
        const { install } = await import(
            /* webpackChunkName: "rechnungen" */
            '@modules/rechnungen/index.ts'
        );
        install();
    } catch (e) {
        console.warn('[bk] rechnungen-Modul konnte nicht geladen werden:', e);
    }

    try {
        const { install: installKunden } = await import(
            /* webpackChunkName: "kunden" */
            '@modules/kunden/index.ts'
        );
        installKunden();
    } catch (e) {
        console.warn('[bk] kunden-Modul konnte nicht geladen werden:', e);
    }

    try {
        const { install: installDashboard } = await import(
            /* webpackChunkName: "dashboard" */
            '@modules/dashboard/index.ts'
        );
        installDashboard();
    } catch (e) {
        console.warn('[bk] dashboard-Modul konnte nicht geladen werden:', e);
    }

    try {
        const { install: installAuswertung } = await import(
            /* webpackChunkName: "auswertung" */
            '@modules/auswertung/index.ts'
        );
        installAuswertung();
    } catch (e) {
        console.warn('[bk] auswertung-Modul konnte nicht geladen werden:', e);
    }
    // P4-Stubs (zeiterfassung, stundenauswertung, termine, wochenplanung, datanorm, oci)
    // haben install() als No-Op und werden nur bei Bedarf geladen.
    // Sie sind als separate Vite-Entries registriert und können in P5 mit
    // Vue-Komponenten befüllt werden.

    // ── P5: zeiterfassung – SeEntriesTable.vue ───────────────
    try {
        const { install: installZeiterfassung } = await import(
            /* webpackChunkName: "zeiterfassung" */
            '@modules/zeiterfassung/index.ts'
        );
        installZeiterfassung();
    } catch (e) {
        console.warn('[bk] zeiterfassung-Modul konnte nicht geladen werden:', e);
    }

    // ── P5: termine – TermineView.vue (Teleport-Modal) ───────
    try {
        const { install: installTermine } = await import(
            /* webpackChunkName: "termine" */
            '@modules/termine/index.ts'
        );
        installTermine();
    } catch (e) {
        console.warn('[bk] termine-Modul konnte nicht geladen werden:', e);
    }

    // ── P5: stundenauswertung – SaContent.vue ────────────────
    try {
        const { install: installSa } = await import(
            /* webpackChunkName: "stundenauswertung" */
            '@modules/stundenauswertung/index.ts'
        );
        installSa();
    } catch (e) {
        console.warn('[bk] stundenauswertung-Modul konnte nicht geladen werden:', e);
    }

    // ── P5: wochenplanung – WochenplanungShell.vue ────────────
    try {
        const { install: installWp } = await import(
            /* webpackChunkName: "wochenplanung" */
            '@modules/wochenplanung/index.ts'
        );
        installWp();
    } catch (e) {
        console.warn('[bk] wochenplanung-Modul konnte nicht geladen werden:', e);
    }
})();
