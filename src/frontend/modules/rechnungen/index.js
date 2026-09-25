// ============================================================
// rechnungen/index.js – ES-Modul-Adapter (Phase 4)
// ============================================================
// Thin-Wrapper für das Rechnungen-&-Angebote-Feature aus script.js.
// Delegiert an die globalen Funktionen von script.js.
// script.js bleibt bis Phase 5 die einzige Ladestelle.
// ============================================================

let _ctx = null;

export default {
    name: 'rechnungen',

    /** @param {object} context Store-Kontext aus init.js */
    init(context) {
        _ctx = context;
    },

    /**
     * Rechnungen-View öffnen.
     * @param {'rechnung'|'angebot'|undefined} filterTyp
     */
    show(filterTyp) {
        window.showRechnungenView?.(filterTyp);
    },

    hide() {
        const el = document.getElementById('rechnungenView');
        if (el) el.classList.add('hidden');
    },
};
