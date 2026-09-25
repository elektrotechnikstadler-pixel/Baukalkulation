// ============================================================
// zeiterfassung/index.js – ES-Modul-Adapter (Phase 4)
// ============================================================
// Thin-Wrapper für die Stundenerfassung (Desktop-Modal) aus script.js.
// ============================================================

let _ctx = null;

export default {
    name: 'zeiterfassung',

    /** @param {object} context Store-Kontext aus init.js */
    init(context) {
        _ctx = context;
    },

    /** Stundenerfassungs-Modal öffnen. */
    show() {
        window.openStundenerfassungModal?.();
    },

    /** Stundenerfassungs-Modal schließen. */
    hide() {
        window.closeStundenerfassungModal?.();
    },
};
