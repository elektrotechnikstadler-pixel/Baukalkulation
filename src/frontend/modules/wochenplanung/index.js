// ============================================================
// wochenplanung/index.js – ES-Modul-Adapter (Phase 4)
// ============================================================
// Thin-Wrapper für den Wochenplanungs-Timeline-Kalender aus script.js.
// ============================================================

let _ctx = null;

export default {
    name: 'wochenplanung',

    /** @param {object} context Store-Kontext aus init.js */
    init(context) {
        _ctx = context;
    },

    /** Wochenplanungs-Modal öffnen. */
    show() {
        window.openWochenplanungModal?.();
    },

    /** Wochenplanungs-Modal schließen. */
    hide() {
        window.closeWochenplanungModal?.();
    },
};
