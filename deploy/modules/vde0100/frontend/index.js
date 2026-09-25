// ============================================================
// vde0100/frontend/index.js – ES-Modul-Einstiegspunkt (Phase 3)
// ============================================================
// Thin adapter zwischen dem neuen ES-Modul-System (Phase 2 Core)
// und dem Legacy-IIFE-Script `vde0100.js`.
//
// Hinweis: Das Legacy-Script exportiert window.vde0100Modul
// mit showVde0100View() / hideVde0100View(). Der globale Wrapper
// showVde0100() wird von script.js bereitgestellt.
//
// Aufbau:
//   init(context)  – Context (Store-Zugriff) speichern
//   mount()        – Legacy-Script + CSS laden (einmalig)
//   show()         – View öffnen  → window.vde0100Modul.showVde0100View()
//   hide()         – View schließen → window.vde0100Modul.hideVde0100View()
//   api            – Direktzugriff auf window.vde0100Modul (nach mount)
//
// In Phase 4 wird dieses File durch ein vollständiges ES-Modul
// ersetzt, das die Logik aus vde0100.js direkt enthält.
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
    name: 'vde0100',

    /**
     * @param {{ getState: Function }} context
     */
    init(context) {
        _ctx = context;
    },

    async mount() {
        await loadLegacy(
            'modules/vde0100/vde0100.js',
            'modules/vde0100/vde0100.css',
        );
    },

    show() {
        if (window.vde0100Modul?.showVde0100View) {
            window.vde0100Modul.showVde0100View();
        } else if (typeof window.showVde0100 === 'function') {
            // Fallback: script.js-Wrapper (delegiert ebenfalls an vde0100Modul)
            window.showVde0100();
        } else {
            console.warn('[vde0100] window.vde0100Modul nicht verfügbar – mount() aufgerufen?');
        }
    },

    hide() {
        if (window.vde0100Modul?.hideVde0100View) {
            window.vde0100Modul.hideVde0100View();
        } else {
            document.getElementById('vde0100View')?.classList.add('hidden');
        }
    },

    /**
     * Liefert das öffentliche API-Objekt des Legacy-Scripts.
     * Verfügbar nachdem mount() abgeschlossen ist.
     *
     * @returns {Object|null} window.vde0100Modul oder null
     */
    get api() {
        return window.vde0100Modul ?? null;
    },
};
