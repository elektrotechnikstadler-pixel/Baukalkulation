// ============================================================
// auswertung/index.js – ES-Modul-Adapter (Phase 4)
// ============================================================
// Thin-Wrapper für die Auswertungs-View (Projekt- & Mitarbeiter-
// Kennzahlen) aus script.js.
// ============================================================

let _ctx = null;

export default {
    name: 'auswertung',

    /** @param {object} context Store-Kontext aus init.js */
    init(context) {
        _ctx = context;
    },

    /** Auswertungs-View anzeigen. */
    show() {
        window.showAuswertung?.();
    },

    /** Auswertungs-View ausblenden. */
    hide() {
        const el = document.getElementById('auswertungView');
        if (el) el.classList.add('hidden');
    },
};
