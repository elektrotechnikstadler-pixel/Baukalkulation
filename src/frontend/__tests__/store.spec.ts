// src/frontend/__tests__/store.spec.ts
// Tests für den reaktiven Store (src/frontend/core/store.ts).

import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
    getState, setState, subscribe,
    type BkState, type BkUser, type BkLicense,
} from '@core/store.ts';

// Jeder Test bekommt frische State-Initialisierung (Store ist Singleton-Modul,
// daher nur via setState zurücksetzen, kein Re-Import nötig)
beforeEach(() => {
    setState({ user: null, license: null, modules: [], settings: {},
               activeBaustelleId: null, isOffline: false });
});

// ── getState ─────────────────────────────────────────────────

describe('getState', () => {
    it('gibt flache Kopie zurück (Mutation ändert nicht den Store)', () => {
        const s = getState();
        s.isOffline = true;          // mutiere Kopie
        expect(getState().isOffline).toBe(false); // Store unverändert
    });

    it('hat alle erwarteten Keys', () => {
        const expected: (keyof BkState)[] = [
            'user', 'license', 'modules', 'settings', 'activeBaustelleId', 'isOffline',
        ];
        const s = getState();
        for (const k of expected) expect(s).toHaveProperty(k);
    });
});

// ── setState ─────────────────────────────────────────────────

describe('setState', () => {
    it('aktualisiert einzelne Keys', () => {
        setState({ isOffline: true });
        expect(getState().isOffline).toBe(true);

        setState({ activeBaustelleId: 42 });
        expect(getState().activeBaustelleId).toBe(42);
        expect(getState().isOffline).toBe(true); // anderer Key bleibt
    });

    it('löst Subscriber für geänderte Keys aus', () => {
        const cb = vi.fn();
        subscribe('isOffline', cb);
        setState({ isOffline: true });
        expect(cb).toHaveBeenCalledOnce();
        expect(cb).toHaveBeenCalledWith(true);
    });

    it('löst KEINEN Subscriber für nicht geänderte Keys aus', () => {
        const cb = vi.fn();
        subscribe('activeBaustelleId', cb);
        setState({ isOffline: true }); // anderer Key
        expect(cb).not.toHaveBeenCalled();
    });
});

// ── subscribe ────────────────────────────────────────────────

describe('subscribe', () => {
    it('Rückgabewert meldet Subscriber ab', () => {
        const cb = vi.fn();
        const unsub = subscribe('isOffline', cb);

        setState({ isOffline: true });
        expect(cb).toHaveBeenCalledTimes(1);

        unsub();
        setState({ isOffline: false });
        expect(cb).toHaveBeenCalledTimes(1); // kein weiterer Call
    });

    it('mehrere Subscriber auf demselben Key werden alle aufgerufen', () => {
        const a = vi.fn();
        const b = vi.fn();
        subscribe('isOffline', a);
        subscribe('isOffline', b);
        setState({ isOffline: true });
        expect(a).toHaveBeenCalledOnce();
        expect(b).toHaveBeenCalledOnce();
    });
});

// ── Typen: User- und License-Objekte ────────────────────────

describe('Typen', () => {
    it('BkUser kann gesetzt und gelesen werden', () => {
        const user: BkUser = {
            username: 'testUser', role: 'admin', kuerzel: 'TU',
            permissions: { canSeeRechnungen: true }, visibility: 'all',
            isSubunternehmer: false, dienstleisterId: null, stundenKategorie: '',
        };
        setState({ user });
        expect(getState().user?.username).toBe('testUser');
        expect(getState().user?.role).toBe('admin');
    });

    it('BkLicense kann gesetzt und gelesen werden', () => {
        const license: BkLicense = { tier: 'professional', customer: 'ACME', expires: '2027-12-31' };
        setState({ license });
        expect(getState().license?.tier).toBe('professional');
    });
});
