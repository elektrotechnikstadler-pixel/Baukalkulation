// ============================================================
// auth.js – Session-Management (Baukalkulation ES)
// ============================================================
// Lädt die Session-Daten von api.php?action=check und befüllt
// den zentralen Store. Enthält außerdem Compat-Hilfsfunktionen,
// die der globalen canDo() / currentRole-Logik in script.js
// entsprechen und in Phase-3+-Modulen verwendet werden können.
//
// Verwendung:
//   import { loadSession, canDo, getUser } from '@core/auth.js';
//   const session = await loadSession();
//   if (canDo('canSeeRechnungen')) { ... }
// ============================================================

import { apiGet }                from './api.js';
import { setState, getState, _syncGlobals } from './store.js';

// ── Session laden ─────────────────────────────────────────────

/**
 * Session-Daten von der API laden und Store + Globals befüllen.
 *
 * Gibt das rohe API-Objekt zurück, damit init.js bei Bedarf
 * weitere Felder (z.B. needSetup, mustChangePassword) auswerten kann.
 *
 * @returns {Promise<{loggedIn: boolean, [key: string]: any}>}
 */
export async function loadSession() {
    const data = await apiGet('check');

    if (data.loggedIn) {
        setState({
            user: {
                username:           data.username,
                role:               data.role               ?? 'normal',
                kuerzel:            data.kuerzel            ?? '',
                permissions:        data.permissions        ?? {},
                visibility:         data.visibility         ?? 'all',
                isSubunternehmer:   data.isSubunternehmer   ?? false,
                dienstleisterId:    data.dienstleisterId    ?? null,
                stundenKategorie:   data.stundenKategorie   ?? '',
            },
            license:  data.license  ?? { tier: 'dev', customer: null, expires: null },
            modules:  data.modules  ?? [],
            settings: data.settings ?? {},
        });

        // Compat: script.js-Globals synchronisieren (Phase 2–4)
        _syncGlobals();
    }

    return data;
}

// ── State-Zugriff ──────────────────────────────────────────────

/**
 * Aktuellen eingeloggten User zurückgeben (oder null).
 * @returns {import('./store.js').BkUser|null}
 */
export function getUser() {
    return getState().user;
}

/**
 * Prüft ob der eingeloggte User eine bestimmte Berechtigung hat.
 * Admins dürfen immer alles.
 *
 * Entspricht der globalen `canDo()` in script.js.
 *
 * @param {string} perm - Berechtigungs-Schlüssel (z.B. 'canSeeRechnungen')
 * @returns {boolean}
 */
export function canDo(perm) {
    const user = getState().user;
    if (!user) return false;
    if (user.role === 'admin') return true;
    return !!user.permissions?.[perm];
}

/**
 * Gibt die Rolle des aktuellen Users zurück.
 * @returns {'admin'|'master'|'normal'|null}
 */
export function getRole() {
    return getState().user?.role ?? null;
}

/**
 * Ist der aktuelle User mindestens im Rang „master"?
 * @returns {boolean}
 */
export function isMasterOrAbove() {
    const role = getRole();
    return role === 'admin' || role === 'master';
}
