// ============================================================
// termine/index.js – ES-Modul-Adapter (Phase 4)
// ============================================================
// Thin-Wrapper für die Terminplanung (Overlay-Modal) aus script.js.
// ============================================================

let _ctx = null;

export default {
    name: 'termine',

    /** @param {object} context Store-Kontext aus init.js */
    init(context) {
        _ctx = context;
    },

    /** Terminplanungs-Overlay öffnen. */
    show() {
        window.openTermineModal?.();
    },

    /** Terminplanungs-Overlay entfernen. */
    hide() {
        document.getElementById('termineOverlay')?.remove();
    },
};
