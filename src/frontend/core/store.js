// ============================================================
// store.js – Reaktiver Zentralspeicher (Baukalkulation ES)
// ============================================================
// Einfacher, abhängigkeitsfreier State-Store.
// Zustandsänderungen werden an registrierte Subscriber gemeldet.
//
// Verwendung in Phase-3+-Modulen:
//   import { getState, setState, subscribe } from '@core/store.js';
//
//   // State lesen
//   const { user } = getState();
//
//   // State ändern (löst Subscriber aus)
//   setState({ activeBaustelleId: 42 });
//
//   // Auf Änderungen reagieren
//   const unsub = subscribe('user', newUser => console.log(newUser));
//   unsub(); // abmelden
// ============================================================

/**
 * @typedef {Object} BkUser
 * @property {string}   username
 * @property {string}   role              - 'admin' | 'master' | 'normal'
 * @property {string}   kuerzel
 * @property {Object}   permissions
 * @property {string|string[]} visibility - 'all' oder Array von Baustellen-IDs
 * @property {boolean}  isSubunternehmer
 * @property {number|null} dienstleisterId
 * @property {string}   stundenKategorie
 */

/**
 * @typedef {Object} BkLicense
 * @property {string}      tier       - 'dev'|'basis'|'standard'|'professional'|'enterprise'|'expired'
 * @property {string|null} customer
 * @property {string|null} expires    - ISO-Datum oder null
 */

/**
 * @typedef {Object} BkState
 * @property {BkUser|null}    user
 * @property {BkLicense|null} license
 * @property {Array}          modules           - freigeschaltete Modul-Metadaten
 * @property {Object}         settings          - App-Einstellungen
 * @property {number|null}    activeBaustelleId - aktuell ausgewählte Baustelle
 * @property {boolean}        isOffline
 */

/** @type {BkState} */
const _state = {
    user:               null,
    license:            null,
    modules:            [],
    settings:           {},
    activeBaustelleId:  null,
    isOffline:          false,
};

/** @type {Map<string, Set<Function>>} */
const _subs = new Map();

// ── Öffentliche API ──────────────────────────────────────────

/**
 * Liefert eine flache Kopie des aktuellen State.
 * @returns {BkState}
 */
export function getState() {
    return { ..._state };
}

/**
 * State partiell aktualisieren.
 * Alle geänderten Keys werden ihren Subscribern gemeldet.
 *
 * @param {Partial<BkState>} patch
 */
export function setState(patch) {
    Object.assign(_state, patch);
    for (const key of Object.keys(patch)) {
        _subs.get(key)?.forEach(cb => {
            try { cb(_state[key]); } catch (e) { console.error('[store] Subscriber-Fehler:', e); }
        });
    }
}

/**
 * Auf Änderungen eines einzelnen State-Feldes reagieren.
 *
 * @param {string}   key  - State-Schlüssel (z.B. 'user', 'isOffline')
 * @param {Function} cb   - Callback, erhält den neuen Wert
 * @returns {Function}    - Aufruf entfernt den Subscriber wieder
 */
export function subscribe(key, cb) {
    if (!_subs.has(key)) _subs.set(key, new Set());
    _subs.get(key).add(cb);
    return () => _subs.get(key)?.delete(cb);
}

// ── Compat-Layer ─────────────────────────────────────────────

/**
 * Schreibt Store-Werte in die window-Globals von script.js zurück.
 * Wird aufgerufen, wenn die neuen ES-Module neben dem alten script.js
 * koexistieren (Übergangsphase 2–4).
 *
 * In Phase 5 (vollständige Migration) wird diese Funktion entfernt.
 *
 * @internal
 */
export function _syncGlobals() {
    if (typeof window === 'undefined') return;
    const { user, license, modules, settings } = _state;

    if (user) {
        window.currentUser              = user.username;
        window.currentRole              = user.role;
        window.currentKuerzel           = user.kuerzel;
        window.currentPerms             = user.permissions;
        window.currentVisibility        = user.visibility;
        window.currentIsSubunternehmer  = user.isSubunternehmer;
        window.currentDienstleisterId   = user.dienstleisterId;
        window.currentStundenKategorie  = user.stundenKategorie;
    }
    if (Object.keys(settings).length) window.appSettings = settings;

    // Freigeschaltete Module als window.enabledModules für Phase-3-Compat
    window.enabledModules = modules;
    window.bkLicense      = license;
}
