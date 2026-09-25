// ============================================================
// oci/index.js – ES-Modul-Adapter (Phase 4)
// ============================================================
// Thin-Wrapper für das OCI-Großhandel-Modul aus script.js.
// OCI ist in den Admin-Einstellungen und im Bestell-Workflow
// eingebettet; kein dedizierter View.
// ============================================================

let _ctx = null;

export default {
    name: 'oci',

    /** @param {object} context Store-Kontext aus init.js */
    init(context) {
        _ctx = context;
    },

    /**
     * Supplier-Picker für Punchout öffnen (Bestellworkflow).
     */
    show() {
        window.openOciSupplierPicker?.();
    },

    /**
     * Admin-Lieferantenliste laden (Einstellungen-Section).
     */
    loadLieferanten() {
        window.loadOciLieferanten?.();
    },

    /**
     * Admin-Formular für einen Lieferanten öffnen.
     * @param {number|null} id  null = neuer Lieferant
     */
    showLieferantForm(id = null) {
        window.showOciLieferantForm?.(id);
    },

    hide() {
        // kein eigenständiger View – nichts zu tun
    },
};
