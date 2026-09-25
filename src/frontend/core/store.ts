// ============================================================
// store.ts – Reaktiver Zentralspeicher (TypeScript)
// ============================================================
// Typisierte Version von store.js. Alle Typen werden aus diesem
// Modul re-exportiert und stehen Komponenten + Tests zur Verfügung.
//
// Verwendung:
//   import { getState, setState, subscribe } from '@core/store.ts';
//   import type { BkUser, BkState }          from '@core/store.ts';
// ============================================================

// ── Typen ─────────────────────────────────────────────────────

export type BkRole = 'admin' | 'master' | 'normal';

export interface BkUser {
    username:           string;
    role:               BkRole;
    kuerzel:            string;
    /** Berechtigungs-Map (z.B. { canSeeRechnungen: true }) */
    permissions:        Record<string, boolean>;
    /** 'all' oder Array von sichtbaren Baustellen-IDs (als Strings) */
    visibility:         'all' | string[];
    isSubunternehmer:   boolean;
    dienstleisterId:    number | null;
    stundenKategorie:   string;
}

export interface BkLicense {
    tier:     'dev' | 'basis' | 'standard' | 'professional' | 'enterprise' | 'expired';
    customer: string | null;
    /** ISO-Datum oder null */
    expires:  string | null;
}

export interface BkModule {
    name: string;
    [key: string]: unknown;
}

export interface BkState {
    user:               BkUser | null;
    license:            BkLicense | null;
    /** Freigeschaltete Modul-Metadaten */
    modules:            BkModule[];
    /** App-Einstellungen (flaches Key-Value-Objekt) */
    settings:           Record<string, unknown>;
    /** Aktuell ausgewählte Baustelle (null = keine) */
    activeBaustelleId:  number | null;
    isOffline:          boolean;
}

// ── Interne Typen ─────────────────────────────────────────────

type StateKey = keyof BkState;
type SubscriberFn = (value: unknown) => void;

// ── State + Subscriber-Registry ──────────────────────────────

const _state: BkState = {
    user:               null,
    license:            null,
    modules:            [],
    settings:           {},
    activeBaustelleId:  null,
    isOffline:          false,
};

const _subs = new Map<StateKey, Set<SubscriberFn>>();

// ── Öffentliche API ───────────────────────────────────────────

/**
 * Liefert eine flache Kopie des aktuellen State.
 * Für Tiefenänderungen (z.B. settings-Felder) muss `setState` genutzt werden.
 */
export function getState(): BkState {
    return { ..._state };
}

/**
 * State partiell aktualisieren.
 * Alle geänderten Keys werden an ihre registrierten Subscriber gemeldet.
 */
export function setState(patch: Partial<BkState>): void {
    Object.assign(_state, patch);
    for (const key of Object.keys(patch) as StateKey[]) {
        _subs.get(key)?.forEach(cb => {
            try { cb(_state[key]); }
            catch (e) { console.error('[store] Subscriber-Fehler:', e); }
        });
    }
}

/**
 * Auf Änderungen eines einzelnen State-Feldes reagieren.
 *
 * @param key  State-Schlüssel (z.B. 'user', 'isOffline')
 * @param cb   Callback, erhält den neuen Wert mit dem korrekten Typ
 * @returns    Funktion zum Abmelden des Subscribers
 */
export function subscribe<K extends StateKey>(
    key: K,
    cb: (value: BkState[K]) => void,
): () => void {
    if (!_subs.has(key)) _subs.set(key, new Set());
    _subs.get(key)!.add(cb as SubscriberFn);
    return () => _subs.get(key)?.delete(cb as SubscriberFn);
}

// ── Compat-Layer (Phase 2–4) ──────────────────────────────────

/**
 * Schreibt Store-Werte in die window-Globals von script.js zurück.
 * Wird aufgerufen während Companion-Modus (Phase 2–4).
 * In Phase 5 (vollständige Migration) wird diese Funktion entfernt.
 *
 * @internal
 */
export function _syncGlobals(): void {
    if (typeof window === 'undefined') return;
    const { user, license, modules, settings } = _state;
    const w = window as unknown as Record<string, unknown>;

    if (user) {
        w['currentUser']              = user.username;
        w['currentRole']              = user.role;
        w['currentKuerzel']           = user.kuerzel;
        w['currentPerms']             = user.permissions;
        w['currentVisibility']        = user.visibility;
        w['currentIsSubunternehmer']  = user.isSubunternehmer;
        w['currentDienstleisterId']   = user.dienstleisterId;
        w['currentStundenKategorie']  = user.stundenKategorie;
    }
    if (Object.keys(settings).length) w['appSettings'] = settings;
    w['enabledModules'] = modules;
    w['bkLicense']      = license;
}
