// ============================================================
// stundenauswertung/index.js – ES-Modul-Adapter (Phase 4)
// ============================================================
// Thin-Wrapper für die Admin-Stundenauswertung aus script.js.
// ============================================================

let _ctx = null;

export default {
    name: 'stundenauswertung',

    /** @param {object} context Store-Kontext aus init.js */
    init(context) {
        _ctx = context;
    },

    /** Stundenauswertungs-Modal öffnen. */
    show() {
        window.openStundenauswertungModal?.();
    },

    /** Stundenauswertungs-Modal schließen. */
    hide() {
        window.closeStundenauswertungModal?.();
    },
};
