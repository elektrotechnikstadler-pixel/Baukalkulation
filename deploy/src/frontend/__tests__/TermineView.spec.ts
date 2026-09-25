// src/frontend/__tests__/TermineView.spec.ts
// Tests für TermineView.vue

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount }    from '@vue/test-utils';
import { setState } from '@core/store.ts';
import type { BkUser } from '@core/store.ts';
import TermineView  from '../modules/termine/TermineView.vue';
import type { Termin } from '../modules/termine/types.ts';

vi.mock('@core/api.ts', () => ({
    apiGet: vi.fn(),
    apiPost: vi.fn(),
}));

const adminUser: BkUser = {
    username: 'admin', role: 'admin', kuerzel: 'AD',
    permissions: { canManageTermine: true },
    visibility: 'all', isSubunternehmer: false,
    dienstleisterId: null, stundenKategorie: '',
};

const today = new Date().toISOString().split('T')[0];
const tomorrow = new Date(Date.now() + 86400000).toISOString().split('T')[0];
const yesterday = new Date(Date.now() - 86400000).toISOString().split('T')[0];

const sampleTermine: Termin[] = [
    { id: 1, titel: 'Kundentermin', datum: tomorrow, ersteller: 'admin', farbe: '#00B4D8' },
    { id: 2, titel: 'Besprechung',  datum: yesterday, ersteller: 'admin', farbe: '#888' },
    { id: 3, titel: 'Meeting heute', datum: today,   ersteller: 'max', farbe: '#FF5722',
      zugewiesen: ['admin', 'max'], beschreibung: 'Kurzmeeting' },
];

function mountView() {
    return mount(TermineView, {
        attachTo: document.body,
    });
}

beforeEach(() => {
    setState({ user: adminUser });
    (window as unknown as Record<string, unknown>)['appData'] = { baustellen: [] };
});

describe('TermineView – Sichtbarkeit', () => {
    it('ist initial geschlossen (Modal nicht sichtbar)', () => {
        const wrapper = mountView();
        expect(document.getElementById('termineOverlay')).toBeNull();
        wrapper.unmount();
    });
});

describe('TermineView – Laden und Anzeige', () => {
    it('zeigt Termine nach dem Öffnen', async () => {
        const { apiGet } = await import('@core/api.ts');
        vi.mocked(apiGet).mockResolvedValue({ ok: true, termine: sampleTermine });

        const wrapper = mountView();
        const vm = wrapper.vm as { open: () => void };
        await vm.open();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        const overlay = document.getElementById('termineOverlay');
        expect(overlay).not.toBeNull();
        expect(overlay?.textContent).toContain('Kundentermin');
        wrapper.unmount();
    });

    it('zeigt Fehler-Nachricht bei API-Fehler', async () => {
        const { apiGet } = await import('@core/api.ts');
        vi.mocked(apiGet).mockRejectedValue(new Error('Netzwerkfehler'));

        const wrapper = mountView();
        const vm = wrapper.vm as { open: () => void };
        await vm.open();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        const overlay = document.getElementById('termineOverlay');
        expect(overlay?.textContent).toContain('Fehler');
        wrapper.unmount();
    });
});

describe('TermineView – Filter', () => {
    beforeEach(async () => {
        const { apiGet } = await import('@core/api.ts');
        vi.mocked(apiGet).mockResolvedValue({ ok: true, termine: sampleTermine });
    });

    it('zeigt alle Termine nach open() (Default-Filter = "all")', async () => {
        const wrapper = mountView();
        const vm = wrapper.vm as { open: () => void };
        await vm.open();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        const overlay = document.getElementById('termineOverlay');
        expect(overlay?.textContent).toContain('Kundentermin');  // morgen
        expect(overlay?.textContent).toContain('Besprechung');   // gestern – jetzt auch sichtbar
        wrapper.unmount();
    });
});
