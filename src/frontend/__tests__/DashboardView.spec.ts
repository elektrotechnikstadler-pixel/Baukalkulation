// src/frontend/__tests__/DashboardView.spec.ts
// Komponenten-Tests für DashboardView.vue

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount }    from '@vue/test-utils';
import { ref }      from 'vue';
import { setState } from '@core/store.ts';
import type { BkUser } from '@core/store.ts';
import DashboardView from '../modules/dashboard/DashboardView.vue';
import type { DashboardItem } from '../modules/dashboard/types.ts';

vi.mock('@core/api.ts', () => ({
    apiGet: vi.fn(),
    apiPost: vi.fn(),
}));

const adminUser: BkUser = {
    username: 'admin', role: 'admin', kuerzel: 'AD',
    permissions: { canReadDashboard: true, canWriteDashboard: true },
    visibility: 'all', isSubunternehmer: false,
    dienstleisterId: null, stundenKategorie: '',
};

const sampleItems: DashboardItem[] = [
    { id: 1, typ: 'aufgabe',    titel: 'Aufgabe 1', status: 'offen',    prioritaet: 'hoch', ersteller: 'admin' },
    { id: 2, typ: 'notiz',      titel: 'Notiz 1',   status: 'erledigt', prioritaet: 'normal', ersteller: 'admin' },
    { id: 3, typ: 'erinnerung', titel: 'Erinnerung 1', status: 'offen', prioritaet: 'dringend', ersteller: 'admin',
      faelligAm: '2020-01-01' }, // überfällig
];

function mountDashboard() {
    const refreshKey = ref(0);
    const wrapper = mount(DashboardView, { props: { refreshKey } });
    refreshKey.value = 1; // triggert watch → loadData()
    return wrapper;
}

beforeEach(() => {
    setState({ user: adminUser });
});

describe('DashboardView – Rendering', () => {
    it('zeigt Lade-Indikator', async () => {
        const { apiPost } = await import('@core/api.ts');
        vi.mocked(apiPost).mockReturnValue(new Promise(() => {}));
        const wrapper = mountDashboard();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Laden');
    });

    it('zeigt Items nach erfolgreichem Laden', async () => {
        const { apiPost } = await import('@core/api.ts');
        vi.mocked(apiPost).mockResolvedValue({ ok: true, items: sampleItems, canManage: false });
        const wrapper = mountDashboard();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Aufgabe 1');
        expect(wrapper.text()).toContain('Notiz 1');
    });

    it('zeigt Überfällig-Indikator für abgelaufene Items', async () => {
        const { apiPost } = await import('@core/api.ts');
        vi.mocked(apiPost).mockResolvedValue({ ok: true, items: sampleItems, canManage: false });
        const wrapper = mountDashboard();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('⚠️');
    });

    it('zeigt Fehler bei API-Fehler', async () => {
        const { apiPost } = await import('@core/api.ts');
        vi.mocked(apiPost).mockResolvedValue({ ok: false, error: 'DB-Fehler' });
        const wrapper = mountDashboard();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('DB-Fehler');
    });
});

describe('DashboardView – Filter', () => {
    beforeEach(async () => {
        const { apiPost } = await import('@core/api.ts');
        vi.mocked(apiPost).mockResolvedValue({ ok: true, items: sampleItems, canManage: false });
    });

    it('zeigt alle Items bei Filter "alle"', async () => {
        const wrapper = mountDashboard();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Aufgabe 1');
        expect(wrapper.text()).toContain('Notiz 1');
        expect(wrapper.text()).toContain('Erinnerung 1');
    });

    it('filtert auf Aufgaben', async () => {
        const wrapper = mountDashboard();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        await wrapper.find('.dashboard-filter-btn:nth-child(2)').trigger('click');
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Aufgabe 1');
        expect(wrapper.text()).not.toContain('Notiz 1');
    });

    it('filtert auf erledigte Items', async () => {
        const wrapper = mountDashboard();
        await wrapper.vm.$nextTick();
        await wrapper.vm.$nextTick();
        const buttons = wrapper.findAll('.dashboard-filter-btn');
        const erledigt = buttons.find(b => b.text() === 'Erledigt');
        await erledigt?.trigger('click');
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain('Notiz 1');
        expect(wrapper.text()).not.toContain('Aufgabe 1');
    });
});
