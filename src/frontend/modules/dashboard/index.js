// ============================================================
// dashboard/index.js – ES-Modul-Adapter (Phase 4)
// ============================================================
// Thin-Wrapper für das Dashboard (v2.4) aus script.js.
// ============================================================

let _ctx = null;

export default {
    name: 'dashboard',

    /** @param {object} context Store-Kontext aus init.js */
    init(context) {
        _ctx = context;
    },

    /** Dashboard-View anzeigen. */
    show() {
        window.showDashboard?.();
    },

    /** Dashboard-View ausblenden. */
    hide() {
        const el = document.getElementById('dashboardView');
        if (el) el.classList.add('hidden');
    },
};
