// ============================================================
// datanorm/index.js – ES-Modul-Adapter (Phase 4)
// ============================================================
// Thin-Wrapper für die Datanorm-Großhandels-Katalog-Funktionen
// aus script.js. Datanorm ist in die Material-Katalog-View
// eingebettet; kein dedizierter View.
// ============================================================

let _ctx = null;

export default {
    name: 'datanorm',

    /** @param {object} context Store-Kontext aus init.js */
    init(context) {
        _ctx = context;
    },

    /** Datanorm-Index neu aufbauen. */
    reindex() {
        window.datanormReindex?.();
    },

    /** Status der hochgeladenen Datanorm-Dateien abrufen. */
    status() {
        window.datanormStatus?.();
    },

    /**
     * Eine Datanorm-Datei hochladen.
     * @param {'datanorm.001'|'datanorm.wrg'|'datpreis.001'} name
     */
    upload(name) {
        window.datanormUpload?.(name);
    },

    /** Alle Datanorm-Dateien und den Index löschen. */
    clear() {
        window.datanormClear?.();
    },

    show() {
        // kein eigenständiger View – wird über Material-Katalog-View sichtbar
    },

    hide() {
        // kein eigenständiger View – nichts zu tun
    },
};
