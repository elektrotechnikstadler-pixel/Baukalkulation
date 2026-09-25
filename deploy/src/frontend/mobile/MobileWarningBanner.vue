<script setup lang="ts">
// ============================================================
// MobileWarningBanner.vue – Hinweise zur Zeiterfassung (P5 Mobile)
// ============================================================
// Reaktives Warning-Banner analog zum Desktop (_renderSeWarnings).
// Hinweise können quittiert werden (localStorage, gleicher Key wie Desktop).
// Gleiche Logik wie _renderSeMobileWarnings in mobile.html, aber
// als reaktive Vue-Komponente (kein innerHTML-Manipulation mehr).
// ============================================================

import { ref, computed, onMounted, watch } from 'vue';
import type { ZeitEntry } from '../modules/zeiterfassung/types.ts';

// ── Props ─────────────────────────────────────────────────────
const props = defineProps<{
    refreshKey: ReturnType<typeof ref<number>>;
}>();

// ── Globale Helfer ────────────────────────────────────────────
const w = window as unknown as Record<string, unknown>;

// ── User für localStorage-Key ─────────────────────────────────
function ackKey(): string {
    const user = (w['currentUser'] as string | undefined) ?? 'anon';
    return 'bk_hint_ack_' + user;
}

// ── Quittierungs-Persistenz ───────────────────────────────────
const ackedSet = ref(new Set<string>());
function loadAcked() {
    try {
        const arr = JSON.parse(localStorage.getItem(ackKey()) ?? '[]') as string[];
        ackedSet.value = new Set(arr);
    } catch { ackedSet.value = new Set(); }
}
function saveAcked() {
    try { localStorage.setItem(ackKey(), JSON.stringify([...ackedSet.value])); } catch { /* ignore */ }
}

// ── Hinweise berechnen ────────────────────────────────────────
interface HintEntry { level: 'warn' | 'error'; text: string }

// ── Reaktive Daten-Refs ──────────────────────────────────────
const _rawEntries    = ref<ZeitEntry[]>([]);
const _rawSollTag    = ref<number>(8);
const _rawArbeitstage = ref<Set<number>>(new Set([1,2,3,4,5]));

function _refreshWarn(): void {
    _rawEntries.value = (w['zeitEntries'] as ZeitEntry[] | undefined) ?? [];
    _rawSollTag.value = (w['seMobileSollTag'] as number | undefined) ?? 8;
    const raw = w['seMobileArbeitstage'];
    const stw = (w['seMobileSollTageWoche'] as number | undefined) ?? 5;
    _rawArbeitstage.value = raw instanceof Set ? raw
        : ((w['getUserArbeitstage'] as ((o: object) => Set<number>) | undefined)
            ?.({ sollTageWoche: stw }) ?? new Set([1,2,3,4,5]));
}

const warnings = computed<HintEntry[]>(() => {
    const entries = _rawEntries.value;
    const seMobileSollTag = _rawSollTag.value;
    const atSet = _rawArbeitstage.value;

    const result: HintEntry[] = [];

    // 1. Einträge > 12h
    entries.forEach(e => {
        if ((e.stunden ?? 0) > 12) {
            const d = e.datum ? e.datum.split('-').reverse().join('.') : '?';
            result.push({ level: 'warn', text: `Ungewöhnlich viele Stunden am ${d}: ${(e.stunden ?? 0).toFixed(1)} h` });
        }
    });

    // 2. Fehlende Arbeitstage (letzte 2 Wochen)
    const today = new Date(); today.setHours(0,0,0,0);
    const twoWeeksAgo = new Date(today); twoWeeksAgo.setDate(today.getDate() - 14);
    const entryDates = new Set(entries.map(e => e.datum ?? ''));
    const getBavarianHolidays = w['getBavarianHolidays'] as ((y: number) => Date[]) | undefined;
    const dateToKey = w['dateToKey'] as ((d: Date) => string) | undefined;
    const holidayCache: Record<number, Set<string>> = {};

    for (let d = new Date(twoWeeksAgo); d < today; d.setDate(d.getDate() + 1)) {
        if (!atSet.has(d.getDay())) continue;
        const y = d.getFullYear();
        if (!holidayCache[y]) {
            const holidays = getBavarianHolidays?.(y) ?? [];
            holidayCache[y] = new Set(holidays.map(h => dateToKey?.(h) ?? ''));
        }
        const dateStr = dateToKey?.(d) ?? d.toISOString().split('T')[0];
        if (holidayCache[y].has(dateStr)) continue;
        if (!entryDates.has(dateStr)) {
            result.push({ level: 'error', text: `Fehlender Eintrag am ${dateStr.split('-').reverse().join('.')}` });
        }
    }

    return result;
});

function hintSig(w: HintEntry): string { return w.level + '|' + w.text; }

const visible = computed(() => warnings.value.filter(w => !ackedSet.value.has(hintSig(w))));
const ackedCount = computed(() => warnings.value.length - visible.value.length);

// ── Collapse ──────────────────────────────────────────────────
const collapsed = ref(localStorage.getItem('bk_warn_collapsed') === '1');
function toggleCollapse() {
    collapsed.value = !collapsed.value;
    localStorage.setItem('bk_warn_collapsed', collapsed.value ? '1' : '0');
}

// ── Aktionen ──────────────────────────────────────────────────
function ackHint(idx: number) {
    const sig = hintSig(visible.value[idx]);
    ackedSet.value.add(sig);
    ackedSet.value = new Set(ackedSet.value);
    saveAcked();
}
function resetAcked() {
    ackedSet.value.clear();
    ackedSet.value = new Set();
    saveAcked();
}

onMounted(() => { loadAcked(); _refreshWarn(); });
watch(() => props.refreshKey.value, () => { loadAcked(); _refreshWarn(); });
</script>

<template>
  <!-- Nichts anzeigen wenn keine Hinweise -->
  <div v-if="warnings.length === 0" />

  <!-- Alle quittiert → schlanker Reset-Hinweis -->
  <div
    v-else-if="visible.length === 0"
    style="background:#F1F8E9;border:1px solid #C5E1A5;border-radius:8px;padding:8px 14px;font-size:.8rem;"
  >
    <div style="display:flex;justify-content:space-between;align-items:center;color:#558B2F">
      <span>✓ Alle Hinweise quittiert ({{ ackedCount }})</span>
      <button @click="resetAcked" style="background:none;border:none;cursor:pointer;font-size:.78rem;color:#666;padding:0 4px">Zurücksetzen</button>
    </div>
  </div>

  <!-- Hinweise vorhanden -->
  <div
    v-else
    :style="{
      background: visible.some(w => w.level === 'error') ? '#FFEBEE' : '#FFF8E1',
      border: '1px solid ' + (visible.some(w => w.level === 'error') ? '#EF9A9A' : '#FFE082'),
      borderRadius: '8px',
      padding: '10px 14px',
      fontSize: '.8rem',
    }"
  >
    <!-- Header -->
    <div style="display:flex;justify-content:space-between;align-items:center;" :style="{ marginBottom: collapsed ? '0' : '4px' }">
      <div style="font-weight:700">
        {{ visible.some(w => w.level === 'error') ? '⚠️' : '💡' }}
        Hinweise zur Zeiterfassung ({{ visible.length }})
      </div>
      <div style="display:flex;gap:10px;align-items:center">
        <button v-if="ackedCount > 0" @click="resetAcked"
                style="background:none;border:none;cursor:pointer;font-size:.72rem;color:#888;padding:0 2px">
          {{ ackedCount }} quittiert ↺
        </button>
        <button @click="toggleCollapse"
                style="background:none;border:none;cursor:pointer;font-size:.78rem;color:#666;padding:0 4px">
          {{ collapsed ? 'Aufklappen ▼' : 'Minimieren ▲' }}
        </button>
      </div>
    </div>

    <!-- Hinweis-Liste -->
    <div v-show="!collapsed">
      <div
        v-for="(hint, i) in visible" :key="hintSig(hint)"
        style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:2px 0"
      >
        <span :style="{ color: hint.level === 'error' ? '#C62828' : '#E65100' }">• {{ hint.text }}</span>
        <button @click="ackHint(i)"
                title="Hinweis quittieren"
                style="background:none;border:none;cursor:pointer;font-size:.95rem;color:#4CAF50;padding:0 4px;flex-shrink:0">✓</button>
      </div>
    </div>
  </div>
</template>
