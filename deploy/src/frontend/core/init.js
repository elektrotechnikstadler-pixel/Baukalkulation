// ============================================================
// init.js – Bootstrap-Einstiegspunkt (Baukalkulation ES)
// ============================================================
// Lädt Session-Daten von api.php, befüllt den Store und
// montiert lizenzierte Zusatzmodule dynamisch.
//
// Ablauf:
//   1.  api.php?action=check → User, Lizenz, freigeschaltete Module
//   2.  Store + window-Globals befüllen (Compat mit script.js)
//   3.  Phase 3+: Modul-Bundles per Dynamic Import laden & mounten
//
// PHASE 5 – COMPANION-MODUS (aktiv ab v2.9.9):
//   index.html lädt SOWOHL script.js (Haupt-Laufzeit) als auch
//   dist/core.js (dieses Modul) als type="module".
//   Beide laufen parallel; script.js bleibt primäre Laufzeit.
//   Dieses Modul befüllt zusätzlich den neuen ES-Modul-Store.
//   Hinweis: es entstehen zwei api.php?action=check-Anfragen –
//   das ist gewollt und harmlos (read-only, kein Seiteneffekt).
//
// ROLLBACK:
//   Die eine <script type="module" src="dist/core.js?v=91">-Zeile
//   in index.html entfernen → vollständiger Rollback auf v2.9.8.
//
// NÄCHSTER SCHRITT (Phase 6+):
//   Feature-Code aus script.js in echte ES-Module extrahieren.
//   Danach script.js aus index.html entfernen (Phase 5 abschließen).
// ============================================================

import { loadSession }   from './auth.js';
import { getState }      from './store.js';

/**
 * Bootstrap der Baukalkulation-ES-Frontend-Module.
 *
 * @returns {Promise<{
 *   loggedIn: boolean,
 *   user:     import('./store.js').BkUser|null,
 *   license:  import('./store.js').BkLicense|null,
 *   modules:  Array,
 *   error?:   string,
 * }>}
 */
export async function bootstrap() {
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
        // und mit dem aktuellen Kontext (Store-Zugriff) gemountet.
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
        console.error('[BK] Bootstrap fehlgeschlagen:', err);
        return { loggedIn: false, user: null, license: null, modules: [], error: err.message };
    }
}

// Automatisch starten wenn dieses Skript direkt geladen wird
// (index.html lädt es in Phase 5 als type="module")
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
} else {
    bootstrap();
}
