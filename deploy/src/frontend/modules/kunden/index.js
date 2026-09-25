// ============================================================
// kunden/index.js – ES-Modul-Adapter (Phase 4)
// ============================================================
// Thin-Wrapper für den Kundenstamm aus script.js.
// ============================================================

let _ctx = null;

export default {
    name: 'kunden',

    /** @param {object} context Store-Kontext aus init.js */
    init(context) {
        _ctx = context;
    },

    show() {
        window.showKundenstamm?.();
    },

    hide() {
        const el = document.getElementById('kundenstammView');
        if (el) el.classList.add('hidden');
    },
};
