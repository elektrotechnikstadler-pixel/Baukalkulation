// src/frontend/__tests__/SeEntriesTable.spec.ts
// Tests für SeEntriesTable.vue

import { describe, it, expect, beforeEach } from 'vitest';
import { mount }    from '@vue/test-utils';
import { ref }      from 'vue';
import { setState } from '@core/store.ts';
import type { BkUser } from '@core/store.ts';
import SeEntriesTable from '../modules/zeiterfassung/SeEntriesTable.vue';
import type { ZeitEntry } from '../modules/zeiterfassung/types.ts';

const adminUser: BkUser = {
    username: 'admin', role: 'admin', kuerzel: 'AD',
    permissions: {}, visibility: 'all',
    isSubunternehmer: false, dienstleisterId: null, stundenKategorie: '',
};

const sampleEntries: ZeitEntry[] = [
    { id: 1, datum: '2026-07-01', typ: 'arbeit',  stunden: 8,   baustelleName: 'Baustelle A', bemerkung: 'Normal'  },
    { id: 2, datum: '2026-07-02', typ: 'urlaub',  stunden: 8,   baustelleName: '',            bemerkung: ''        },
    { id: 3, datum: '2026-06-20', typ: 'krank',   stunden: 8,   baustelleName: '',            bemerkung: 'Grippe'  },
    { id: 4, datum: '2026-07-01', typ: 'feiertag',stunden: 8,   _virtual: true,               bemerkung: 'Feiertag'},
];

function mountTable(search = '', erweitert = false, ents = sampleEntries) {
    const entries     = ref<ZeitEntry[]>(ents);
    const searchQ     = ref(search);
    const isErweitert = ref(erweitert);
    return mount(SeEntriesTable, { props: { entries, searchQ, isErweitert } });
}

beforeEach(() => setState({ user: adminUser }));

describe('SeEntriesTable – Rendering', () => {
    it('rendert eine Tabelle', () => {
        const wrapper = mountTable();
        expect(wrapper.find('table').exists()).toBe(true);
    });

    it('zeigt alle Einträge', () => {
        const wrapper = mountTable();
        expect(wrapper.text()).toContain('Baustelle A');
        expect(wrapper.text()).toContain('Urlaub');
    });

    it('zeigt Normal-Modus-Spalten (ohne erw. Zeiterfassung)', () => {
        const wrapper = mountTable();
        const ths = wrapper.findAll('thead th');
        expect(ths).toHaveLength(6); // Datum, Typ, Stunden, Baustelle, Bemerkung, Aktionen
    });

    it('zeigt erweiterte Spalten (mit erw. Zeiterfassung)', () => {
        const wrapper = mountTable('', true);
        const ths = wrapper.findAll('thead th');
        expect(ths).toHaveLength(9); // + Von, Bis, Pause
    });

    it('zeigt virtuelle Einträge (Feiertag)', () => {
        const wrapper = mountTable();
        expect(wrapper.text()).toContain('🎉');
    });

    it('zeigt Leer-Zustand wenn keine Einträge', () => {
        const wrapper = mountTable('', false, []);
        expect(wrapper.text()).toContain('Keine Einträge');
    });
});

describe('SeEntriesTable – Suche', () => {
    it('filtert Einträge nach Suchbegriff', () => {
        const wrapper = mountTable('Grippe');
        expect(wrapper.text()).toContain('Grippe');
        expect(wrapper.text()).not.toContain('Baustelle A');
    });

    it('zeigt alle Einträge wenn Suche leer', () => {
        const wrapper = mountTable('');
        expect(wrapper.text()).toContain('Baustelle A');
        expect(wrapper.text()).toContain('Grippe');
    });
});

describe('SeEntriesTable – Datumsformatierung', () => {
    it('konvertiert ISO-Datum ins DE-Format', () => {
        const wrapper = mountTable();
        expect(wrapper.text()).toContain('01.07.2026');
    });
});
