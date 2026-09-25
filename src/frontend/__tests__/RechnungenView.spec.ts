// src/frontend/__tests__/RechnungenView.spec.ts
// Komponenten-Tests für RechnungenView.vue

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount }    from '@vue/test-utils';
import { ref }      from 'vue';
import { setState } from '@core/store.ts';
import type { BkUser } from '@core/store.ts';
import RechnungenView from '../modules/rechnungen/RechnungenView.vue';
import type { Rechnung } from '../modules/rechnungen/types.ts';

// ── Globale Mocks ─────────────────────────────────────────────

vi.mock('@core/api.ts', () => ({
    apiGet: vi.fn(),
    apiPost: vi.fn(),
}));

// ── Test-Fixtures ─────────────────────────────────────────────

const adminUser: BkUser = {
    username: 'admin', role: 'admin', kuerzel: 'AD',
    permissions: {
        canSeeRechnungen: true, canManageRechnungen: true,
        canSeeAngebote: true,   canManageAngebote: true,
    },
    visibility: 'all', isSubunternehmer: false,
    dienstleisterId: null, stundenKategorie: '',
};

const sampleRechnung: Rechnung = {
    id: 1, typ: 'rechnung', nummer: 'RE-2026-001',
    datum: '2026-07-01', status: 'offen',
    kundeName: 'Mustermann GmbH', baustelleName: 'Baustelle 1',
    projektNr: 'P-001', beschreibung: 'Elektriker',
    positionen: [
        { bezeichnung: 'Material', menge: 10, einheit: 'Stk', einzelpreis: 25, posTyp: 'normal' },
    ],
};

const sampleAngebot: Rechnung = {
    id: 2, typ: 'angebot', nummer: 'AN-2026-001',
    datum: '2026-07-02', status: 'offen',
    kundeName: 'Schmidt KG',
};

// ── Mount-Helper ─────────────────────────────────────────────

function mountComponent(filterVal?: string) {
    const filterTyp  = ref<string | undefined>(filterVal);
    const refreshKey = ref(0);
    const wrapper = mount(RechnungenView, { props: { filterTyp, refreshKey } });
    refreshKey.value = 1; // triggert watch → loadData()
    return wrapper;
}

// ── Tests ─────────────────────────────────────────────────────

describe('RechnungenView – Lade-Zustand', () => {
    beforeEach(() => {
        setState({ user: adminUser });
    });

    it('zeigt Lade-Indikator während API-Aufruf läuft', async () => {
        const { apiGet } = await import('@core/api.ts');
        // Auflösung verzögern
        vi.mocked(apiGet).mockReturnValue(new Promise(() => {}));

        const wrapper = mountComponent();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Laden');
    });

    it('zeigt Fehlermeldung bei API-Fehler', async () => {
        const { apiGet } = await import('@core/api.ts');
        vi.mocked(apiGet).mockResolvedValue({ ok: false, error: 'DB-Fehler' });

        const wrapper = mountComponent();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick(); // warten bis Async abgeschlossen
        expect(wrapper.text()).toContain('DB-Fehler');
    });
});

describe('RechnungenView – Listen-Darstellung', () => {
    beforeEach(async () => {
        setState({ user: adminUser });
        const { apiGet } = await import('@core/api.ts');
        vi.mocked(apiGet).mockResolvedValue({
            ok: true,
            rechnungen: [sampleRechnung, sampleAngebot],
        });
    });

    it('zeigt Rechnungs-Nummer', async () => {
        const wrapper = mountComponent();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('RE-2026-001');
    });

    it('zeigt Angebots-Nummer', async () => {
        const wrapper = mountComponent();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('AN-2026-001');
    });

    it('filtert auf Rechnungen wenn filterTyp="rechnung"', async () => {
        const wrapper = mountComponent('rechnung');
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('RE-2026-001');
        expect(wrapper.text()).not.toContain('AN-2026-001');
    });

    it('filtert auf Angebote wenn filterTyp="angebot"', async () => {
        const wrapper = mountComponent('angebot');
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).not.toContain('RE-2026-001');
        expect(wrapper.text()).toContain('AN-2026-001');
    });
});

describe('RechnungenView – Betrag', () => {
    beforeEach(async () => {
        setState({ user: adminUser });
        const { apiGet } = await import('@core/api.ts');
        vi.mocked(apiGet).mockResolvedValue({
            ok: true,
            rechnungen: [sampleRechnung],
        });
    });

    it('berechnet Netto-Betrag korrekt (10 × 25 = 250)', async () => {
        const wrapper = mountComponent('rechnung');
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('250,00');
    });
});

describe('RechnungenView – Suche', () => {
    beforeEach(async () => {
        setState({ user: adminUser });
        const { apiGet } = await import('@core/api.ts');
        vi.mocked(apiGet).mockResolvedValue({
            ok: true,
            rechnungen: [sampleRechnung, sampleAngebot],
        });
    });

    it('filtert nach Suchbegriff', async () => {
        const wrapper = mountComponent();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();

        const input = wrapper.find('input');
        await input.setValue('Mustermann');
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('RE-2026-001');
        expect(wrapper.text()).not.toContain('AN-2026-001');
    });
});
