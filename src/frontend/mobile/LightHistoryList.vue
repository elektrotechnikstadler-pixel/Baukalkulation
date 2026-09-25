<script setup lang="ts">
// ============================================================
// LightHistoryList.vue – Letzte Einträge (mobile_light P5)
// ============================================================
// Ersetzt renderHistory() in mobile_light.html.
// Bridge: renderHistory()-Override → refreshKey → Vue re-rendert.
// ============================================================

import { ref, computed, watch } from 'vue';
import type { ZeitEntry }       from '../modules/zeiterfassung/types.ts';
import { formatDate }            from '@core/utils.ts';

// ── Props ─────────────────────────────────────────────────────
const props = defineProps<{
    refreshKey: ReturnType<typeof ref<number>>;
}>();

// ── Globale Helfer ────────────────────────────────────────────
const w = window as unknown as Record<string, unknown>;

// ── Reaktive Daten-Refs (direktes Update statt computed+_tick) ──────────────
const _rawEntries      = ref<ZeitEntry[]>([]);
const _rawBaustellen   = ref<{ id: number; name: string }[]>([]);

function _refreshData(): void {
    _rawEntries.value    = (w['zeitEntries']    as ZeitEntry[]                         | undefined) ?? [];
    _rawBaustellen.value = (w['baustellenList'] as { id: number; name: string }[]      | undefined) ?? [];
}

watch(() => props.refreshKey.value, _refreshData);

// ── Daten aus reaktiven Refs ─────────────────────────────────
const entries      = computed<ZeitEntry[]>(() => _rawEntries.value);
const baustellenList = computed(() => _rawBaustellen.value);
const isErweitert  = computed(() =>
    !!(w['appSettings'] as Record<string, unknown> | undefined)?.['erweiterte_zeiterfassung']);
const abschlussJahr = computed(() =>
    parseInt((w['appSettings'] as Record<string, unknown> | undefined)?.['zeiterfassung_abschluss_jahr'] as string || '') || 0);

// ── Gefilterte + sortierte Einträge (letzte 20, neueste zuerst) ──
const sortedEntries = computed<ZeitEntry[]>(() => {
    let list = [...entries.value].sort((a, b) =>
        (b.datum ?? '').localeCompare(a.datum ?? '') || ((b.id ?? 0) - (a.id ?? 0)),
    );
    if (abschlussJahr.value) {
        list = list.filter(e => !e.datum || parseInt(e.datum.slice(0, 4)) > abschlussJahr.value);
    }
    return list.slice(0, 20);
});

// Monatlich gruppiert
const monthGroups = computed(() => {
    const order: string[] = [];
    const map: Record<string, ZeitEntry[]> = {};
    for (const e of sortedEntries.value) {
        const mk = e.datum?.slice(0, 7) ?? '?';
        if (!map[mk]) { map[mk] = []; order.push(mk); }
        map[mk].push(e);
    }
    return order.map(mk => ({ mk, entries: map[mk] }));
});

// Bearbeitbar: letzte 5 Tage
const fiveDaysAgo = new Date(); fiveDaysAgo.setHours(0,0,0,0); fiveDaysAgo.setDate(fiveDaysAgo.getDate() - 5);
const editableIds = computed(() => new Set(
    entries.value
        .filter(e => e.datum && new Date(e.datum + 'T00:00:00') >= fiveDaysAgo)
        .map(e => e.id),
));

// ── Typ-Labels ────────────────────────────────────────────────
const TYP_LABELS: Record<string, string> = {
    arbeit: '🔧 Arbeit', gleitzeit: '⏰ Gleitzeit',
    urlaub: '🌴 Urlaub', krank: '🤒 Krank', sonstig: '📋 Sonstiges',
};

function baustelleName(id?: number | null): string {
    return id ? (baustellenList.value.find(b => b.id === id)?.name ?? '') : '';
}

// ── Monat-Name ────────────────────────────────────────────────
const MONTHS = ['Januar','Februar','März','April','Mai','Juni',
                'Juli','August','September','Oktober','November','Dezember'];
const curMonth = new Date().toISOString().slice(0, 7);

function monthLabel(mk: string): string {
    if (mk === '?') return 'Ohne Datum';
    const d = new Date(mk + '-01');
    return `${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
}

// ── Einklapp-Zustand ──────────────────────────────────────────
const collapsedMonths = ref(new Set<string>());
function toggleMonth(mk: string) {
    if (collapsedMonths.value.has(mk)) collapsedMonths.value.delete(mk);
    else collapsedMonths.value.add(mk);
    collapsedMonths.value = new Set(collapsedMonths.value);
}

// ── Aktionen ──────────────────────────────────────────────────
function editEntry(id: number) {
    (w['showForm'] as ((id: number) => void) | undefined)?.(id);
}
function deleteEntry(id: number) {
    (w['deleteLightEntry'] as ((id: number) => void) | undefined)?.(id);
}
</script>

<template>
  <!-- Leer-Zustand -->
  <div v-if="sortedEntries.length === 0" class="history-title" style="text-align:center;color:var(--grey-400);padding:20px">
    <div class="history-title">Bisherige Einträge</div>
    <p style="font-size:.85rem">Noch keine Einträge vorhanden.</p>
  </div>

  <!-- Eintrags-Liste -->
  <template v-else>
    <div class="history-title">Letzte Einträge ({{ entries.length }} gesamt)</div>

    <div v-for="{ mk, entries: mEntries } in monthGroups" :key="mk">
      <!-- Monats-Header -->
      <div
        class="history-title"
        style="cursor:pointer;display:flex;justify-content:space-between;padding:6px 0"
        @click="toggleMonth(mk)"
      >
        <span>{{ monthLabel(mk) }} <span style="opacity:.5;font-weight:400">· {{ mEntries.length }}</span></span>
        <span>{{ collapsedMonths.has(mk) ? '▸' : '▾' }}</span>
      </div>

      <!-- Eintrags-Karten -->
      <div v-show="!collapsedMonths.has(mk) || mk === curMonth">
        <div
          v-for="e in mEntries" :key="e.id"
          class="history-card"
          :style="{ opacity: editableIds.has(e.id) ? '1' : '0.65' }"
        >
          <div class="history-left">
            <div class="history-date">{{ formatDate(e.datum) }}</div>
            <div class="history-type">{{ TYP_LABELS[e.typ] ?? e.typ }}</div>
            <div v-if="isErweitert && e.von && e.bis" class="history-detail">
              {{ e.von }}–{{ e.bis }} ({{ e.pause ?? 0 }}′ Pause)
            </div>
            <div v-if="baustelleName(e.baustelleId)" class="history-detail">
              {{ baustelleName(e.baustelleId) }}
            </div>
            <div v-if="e.bemerkung" class="history-detail">{{ e.bemerkung }}</div>
          </div>
          <div class="history-right">
            <div class="history-hours">{{ (e.stunden ?? 0).toFixed(2) }} h</div>
            <div v-if="editableIds.has(e.id)" class="history-actions">
              <button class="history-btn edit" @click="editEntry(e.id)">✏️</button>
              <button class="history-btn del" @click="deleteEntry(e.id)">🗑</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </template>
</template>
