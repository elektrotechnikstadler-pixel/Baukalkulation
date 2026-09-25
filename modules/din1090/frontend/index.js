// ============================================================
// din1090/frontend/index.js – ES-Modul-Einstiegspunkt (Phase 3)
// ============================================================
// Thin adapter zwischen dem neuen ES-Modul-System (Phase 2 Core)
// und dem Legacy-IIFE-Script `din1090.js`, das die gesamte UI-
// Logik enthält.
//
// Aufbau:
//   init(context)  – Context (Store-Zugriff) speichern
//   mount()        – Legacy-Script + CSS laden (einmalig)
//   show()         – View öffnen  → window.showDin1090()
//   hide()         – View schließen
//
// In Phase 4 wird dieses File durch ein vollständiges ES-Modul
// ersetzt, das die Logik aus din1090.js direkt enthält.
// ============================================================

/** @type {{ getState: Function }|null} */
let _ctx = null;

// ── Legacy-Loader (analog zu loadOptionalModule in script.js) ──
/**
 * Lädt das Legacy-Script und CSS per <script>/<link> ins DOM.
 * Idempotent: wird nur einmal ausgeführt.
 *
 * @param {string} jsPath
 * @param {string} [cssPath]
 * @returns {Promise<void>}
 */
function loadLegacy(jsPath, cssPath) {
    return new Promise(resolve => {
        // Guard: bereits geladen?
        const scriptBase = jsPath.split('?')[0];
        if (document.querySelector(`script[src*="${scriptBase}"]`)) {
            resolve(); return;
        }
        // CSS
        if (cssPath && !document.querySelector(`link[href*="${cssPath.split('?')[0]}"]`)) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = cssPath;
            document.head.appendChild(link);
        }
        // JS
        const script = document.createElement('script');
        script.src = jsPath;
        script.onload  = resolve;
        script.onerror = resolve; // Fehler nicht fatal – Mount trotzdem fortsetzen
        document.body.appendChild(script);
    });
}

// ── Modul-Export ────────────────────────────────────────────────
export default {
    /** Name entspricht module.json → wird vom Modul-Registry genutzt. */
    name: 'din1090',

    /**
     * Wird von init.js aufgerufen.
     * @param {{ getState: Function }} context
     */
    init(context) {
        _ctx = context;
    },

    /**
     * Legacy-Script laden und View-Container bereitstellen.
     * Delegiert ab Phase 5 an das vollständige ES-Modul.
     */
    async mount() {
        await loadLegacy(
            'modules/din1090/din1090.js',
            'modules/din1090/din1090.css',
        );
    },

    /**
     * DIN EN 1090 Ansicht öffnen.
     * Delegiert an window.showDin1090() des Legacy-Scripts.
     */
    show() {
        if (typeof window.showDin1090 === 'function') {
            window.showDin1090();
        } else {
            console.warn('[din1090] window.showDin1090 nicht verfügbar – mount() aufgerufen?');
        }
    },

    /**
     * DIN EN 1090 Ansicht schließen.
     */
    hide() {
        document.getElementById('din1090View')?.classList.add('hidden');
    },
};
