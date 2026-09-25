// ============================================================
// whatsapp/index.js – ES-Modul-Adapter (Phase 4)
// ============================================================
// Thin-Wrapper für das WhatsApp-Messenger-Modul aus script.js.
// ============================================================

let _ctx = null;

export default {
    name: 'whatsapp',

    /** @param {object} context Store-Kontext aus init.js */
    init(context) {
        _ctx = context;
    },

    /**
     * WhatsApp-View anzeigen.
     * initWhatsApp() wird beim App-Start von script.js aufgerufen;
     * hier nur der View-Switch.
     */
    show() {
        window.showWhatsApp?.();
    },

    /** WhatsApp-View ausblenden. */
    hide() {
        const el = document.getElementById('whatsappView');
        if (el) el.classList.add('hidden');
    },

    /** Bridge-Verbindung initialisieren (wird von script.js beim init() aufgerufen). */
    init_bridge() {
        window.initWhatsApp?.();
    },
};
