// src/frontend/__tests__/KundenList.spec.ts
// Komponenten-Tests für KundenList.vue

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount }    from '@vue/test-utils';
import { ref }      from 'vue';
import { setState } from '@core/store.ts';
import type { BkUser } from '@core/store.ts';
import KundenList from '../modules/kunden/KundenList.vue';
import type { Kunde } from '../modules/kunden/types.ts';

vi.mock('@core/api.ts', () => ({
    apiGet: vi.fn(),
    apiPost: vi.fn(),
}));

const adminUser: BkUser = {
    username: 'admin', role: 'admin', kuerzel: 'AD',
    permissions: { canReadKunden: true, canWriteKunden: true },
    visibility: 'all', isSubunternehmer: false,
    dienstleisterId: null, stundenKategorie: '',
};

const sampleKunden: Kunde[] = [
    { id: 1, firma: 'Mustermann GmbH', kundennummer: 'K-001', ort: 'München', email: 'info@mustermann.de' },
    { id: 2, vorname: 'Hans', nachname: 'Schmidt', kundennummer: 'K-002', ort: 'Berlin', telefon: '030/123456' },
];

function mountKunden(search = '') {
    const searchQ    = ref(search);
    const refreshKey = ref(0);
    const wrapper = mount(KundenList, { props: { searchQ, refreshKey } });
    refreshKey.value = 1; // triggert watch → loadData()
    return wrapper;
}

beforeEach(() => {
    setState({ user: adminUser });
});

describe('KundenList – Rendering', () => {
    it('zeigt Lade-Indikator', async () => {
        const { apiGet } = await import('@core/api.ts');
        vi.mocked(apiGet).mockReturnValue(new Promise(() => {}));
        const wrapper = mountKunden();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Laden');
    });

    it('zeigt Firmennamen', async () => {
        const { apiGet } = await import('@core/api.ts');
        vi.mocked(apiGet).mockResolvedValue({ ok: true, kunden: sampleKunden });
        const wrapper = mountKunden();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Mustermann GmbH');
    });

    it('zeigt Personennamen', async () => {
        const { apiGet } = await import('@core/api.ts');
        vi.mocked(apiGet).mockResolvedValue({ ok: true, kunden: sampleKunden });
        const wrapper = mountKunden();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Hans Schmidt');
    });

    it('zeigt Leer-Meldung wenn keine Kunden', async () => {
        const { apiGet } = await import('@core/api.ts');
        vi.mocked(apiGet).mockResolvedValue({ ok: true, kunden: [] });
        const wrapper = mountKunden();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Noch keine Kunden angelegt');
    });

    it('zeigt Fehler-Meldung bei API-Fehler', async () => {
        const { apiGet } = await import('@core/api.ts');
        vi.mocked(apiGet).mockResolvedValue({ ok: false, error: 'DB-Fehler' });
        const wrapper = mountKunden();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('DB-Fehler');
    });
});

describe('KundenList – Suche', () => {
    beforeEach(async () => {
        const { apiGet } = await import('@core/api.ts');
        vi.mocked(apiGet).mockResolvedValue({ ok: true, kunden: sampleKunden });
    });

    it('filtert nach Firma', async () => {
        const wrapper = mountKunden('Mustermann');
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Mustermann GmbH');
        expect(wrapper.text()).not.toContain('Hans Schmidt');
    });

    it('filtert nach Name', async () => {
        const wrapper = mountKunden('Schmidt');
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Hans Schmidt');
        expect(wrapper.text()).not.toContain('Mustermann GmbH');
    });

    it('zeigt "Keine gefunden" bei leerem Ergebnis', async () => {
        const wrapper = mountKunden('XYZUnbekannt');
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Keine Kunden gefunden');
    });
});
