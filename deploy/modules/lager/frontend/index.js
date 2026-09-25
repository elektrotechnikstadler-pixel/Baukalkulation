// ============================================================
// lager/frontend/index.js – ES-Modul-Einstiegspunkt (Phase 3)
// ============================================================
// Thin adapter zwischen dem neuen ES-Modul-System (Phase 2 Core)
// und dem Legacy-IIFE-Script `lager.js`.
//
// Aufbau:
//   init(context)  – Context (Store-Zugriff) speichern
//   mount()        – Legacy-Script + CSS laden (einmalig)
//   show()         – View öffnen  → window.showLager()
//   hide()         – View schließen → window.hideLager()
//   api            – Direktzugriff auf window.lagerModul (nach mount)
//
// In Phase 4 wird dieses File durch ein vollständiges ES-Modul
// ersetzt, das die Logik aus lager.js direkt enthält.
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
    name: 'lager',

    /**
     * @param {{ getState: Function }} context
     */
    init(context) {
        _ctx = context;
    },

    async mount() {
        await loadLegacy(
            'modules/lager/lager.js',
            'modules/lager/lager.css',
        );
    },

    show() {
        if (typeof window.showLager === 'function') {
            window.showLager();
        } else {
            console.warn('[lager] window.showLager nicht verfügbar – mount() aufgerufen?');
        }
    },

    hide() {
        if (typeof window.hideLager === 'function') {
            window.hideLager();
        } else {
            document.getElementById('lagerView')?.classList.add('hidden');
        }
    },

    /**
     * Liefert das öffentliche API-Objekt des Legacy-Scripts.
     * Verfügbar nachdem mount() abgeschlossen ist.
     *
     * @returns {Object|null} window.lagerModul oder null
     */
    get api() {
        return window.lagerModul ?? null;
    },
};
