// ============================================================
// aufmass/frontend/index.js – ES-Modul-Einstiegspunkt (Phase 3)
// ============================================================
// Thin adapter zwischen dem neuen ES-Modul-System (Phase 2 Core)
// und dem Legacy-IIFE-Script `aufmass.js`.
//
// Aufbau:
//   init(context)  – Context (Store-Zugriff) speichern
//   mount()        – Legacy-Script + CSS laden (einmalig)
//   show()         – View öffnen  → window.showAufmass()
//   hide()         – View schließen → window.hideAufmass()
//   showForBaustelle(id) – View gefiltert nach Baustelle öffnen
//   api            – Direktzugriff auf window.aufmassModul (nach mount)
//
// In Phase 4 wird dieses File durch ein vollständiges ES-Modul
// ersetzt, das die Logik aus aufmass.js direkt enthält.
// ============================================================

/** @type {{ getState: Function }|null} */
let _ctx = null;

// ── Legacy-Loader ───────────────────────────────────────────────
/**
 * @param {string} jsPath
 * @param {string} [cssPath]
 * @returns {Promise<void>}
 */
function loadLegacy(jsPath, cssPath) {
    return new Promise(resolve => {
        const scriptBase = jsPath.split('?')[0];
        if (document.querySelector(`script[src*="${scriptBase}"]`)) {
            resolve(); return;
        }
        if (cssPath && !document.querySelector(`link[href*="${cssPath.split('?')[0]}"]`)) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = cssPath;
            document.head.appendChild(link);
        }
        const script = document.createElement('script');
        script.src = jsPath;
        script.onload  = resolve;
        script.onerror = resolve;
        document.body.appendChild(script);
    });
}

// ── Modul-Export ────────────────────────────────────────────────
export default {
    name: 'aufmass',

    /**
     * @param {{ getState: Function }} context
     */
    init(context) {
        _ctx = context;
    },

    async mount() {
        await loadLegacy(
            'modules/aufmass/aufmass.js',
            'modules/aufmass/aufmass.css',
        );
    },

    show() {
        if (typeof window.showAufmass === 'function') {
            window.showAufmass();
        } else {
            console.warn('[aufmass] window.showAufmass nicht verfügbar – mount() aufgerufen?');
        }
    },

    hide() {
        if (typeof window.hideAufmass === 'function') {
            window.hideAufmass();
        } else {
            document.getElementById('aufmassView')?.classList.add('hidden');
        }
    },

    /**
     * Aufmaß-View öffnen und auf eine bestimmte Baustelle filtern.
     * Entspricht window.showAufmassForBaustelle(id) des Legacy-Scripts.
     *
     * @param {number} baustelleId
     */
    showForBaustelle(baustelleId) {
        if (typeof window.showAufmassForBaustelle === 'function') {
            window.showAufmassForBaustelle(baustelleId);
        } else {
            this.show();
        }
    },

    /**
     * Liefert das öffentliche API-Objekt des Legacy-Scripts.
     * Verfügbar nachdem mount() abgeschlossen ist.
     *
     * @returns {Object|null} window.aufmassModul oder null
     */
    get api() {
        return window.aufmassModul ?? null;
    },
};
