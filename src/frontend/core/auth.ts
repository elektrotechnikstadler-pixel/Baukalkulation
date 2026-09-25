// ============================================================
// auth.ts – Session-Management (TypeScript)
// ============================================================
// Lädt Session-Daten von api.php?action=check, befüllt den Store
// und stellt Compat-Hilfsfunktionen bereit.
//
// Verwendung:
//   import { loadSession, canDo, getUser, useCanDo } from '@core/auth.ts';
//   const session = await loadSession();
//   if (canDo('canSeeRechnungen')) { ... }
//   // Reaktiv in Vue-Komponenten:
//   const canSeeRe = useCanDo('canSeeRechnungen');
// ============================================================

import { apiGet }                        from './api.ts';
import { setState, getState, _syncGlobals } from './store.ts';
import type { BkUser, BkLicense, BkModule } from './store.ts';
import { computed }                      from 'vue';
import type { ComputedRef }              from 'vue';
import { useStore }                      from './useStore.ts';

// ── API-Antwort-Typ für action=check ─────────────────────────

interface CheckResponse {
    loggedIn:           boolean;
    username?:          string;
    role?:              'admin' | 'master' | 'normal';
    kuerzel?:           string;
    permissions?:       Record<string, boolean>;
    visibility?:        'all' | string[];
    isSubunternehmer?:  boolean;
    dienstleisterId?:   number | null;
    stundenKategorie?:  string;
    license?:           BkLicense;
    modules?:           BkModule[];
    settings?:          Record<string, unknown>;
    /** Weitere Felder (z.B. needSetup, mustChangePassword) */
    [key: string]: unknown;
}

// ── Session laden ─────────────────────────────────────────────

/**
 * Session-Daten von der API laden und Store + Globals befüllen.
 * Gibt die rohe API-Antwort zurück, damit der Aufrufer weitere
 * Felder (z.B. needSetup, mustChangePassword) auswerten kann.
 */
export async function loadSession(): Promise<CheckResponse> {
    const data = await apiGet<CheckResponse>('check');

    if (data.loggedIn) {
        setState({
            user: {
                username:           data.username           ?? '',
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

// ── State-Zugriff ─────────────────────────────────────────────

/** Aktuell eingeloggten User zurückgeben (oder null). */
export function getUser(): BkUser | null {
    return getState().user;
}

/**
 * Prüft ob der eingeloggte User eine Berechtigung hat.
 * Admins dürfen immer alles.
 * Entspricht der globalen `canDo()` in script.js.
 */
export function canDo(perm: string): boolean {
    const user = getState().user;
    if (!user) return false;
    if (user.role === 'admin') return true;
    return !!user.permissions?.[perm];
}

/** Rolle des aktuellen Users (oder null wenn nicht eingeloggt). */
export function getRole(): 'admin' | 'master' | 'normal' | null {
    return getState().user?.role ?? null;
}

/** Ist der aktuelle User mindestens im Rang „master"? */
export function isMasterOrAbove(): boolean {
    const role = getRole();
    return role === 'admin' || role === 'master';
}

// ── Vue-reaktive Variante ─────────────────────────────────────

/**
 * Vue Composable: reaktives `ComputedRef<boolean>` für eine Berechtigung.
 * Tracking über Vue-Reaktivität → wertet nach Session-Laden automatisch neu aus.
 *
 * @example
 *   const canSeeRe = useCanDo('canSeeRechnungen');
 *   const canSeeOrManage = computed(() => useCanDo('canSeeRechnungen').value || useCanDo('canManageRechnungen').value);
 */
export function useCanDo(perm: string): ComputedRef<boolean> {
    const _user = useStore('user');
    return computed(() => {
        const u = _user.value;
        if (!u) return false;
        if (u.role === 'admin') return true;
        return !!u.permissions?.[perm];
    });
}
