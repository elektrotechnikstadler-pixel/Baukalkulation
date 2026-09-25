<script setup lang="ts">
// ============================================================
// MobileBaustellenList.vue – Baustellen-Liste (P6 Mobile)
// ============================================================
// Mount-Punkt: #listContent in mobile.html
// Bridge: renderList()-Override → refreshKey → Vue re-rendert
// Escape-Hatches: getVisibleBaustellen, calcTotals, showDetail,
//   fmt, esc, kundenData
// ============================================================

import { ref, computed, watch, onMounted } from 'vue';

// ── Props ─────────────────────────────────────────────────────
const props = defineProps<{
    refreshKey: ReturnType<typeof ref<number>>;
}>();

// ── Globale Escape-Hatches ────────────────────────────────────
const w = window as unknown as Record<string, unknown>;

// ── Suchfilter (bridged von #mobileSearchInput) ───────────────
const searchQ = ref('');

// ── Typen ─────────────────────────────────────────────────────
interface Baustelle {
    id:         number;
    name:       string;
    parentId?:  number | null;
    kundeId?:   number | null;
    projektNr?: string;
    material?:  unknown[];
    arbeitszeit?:unknown[];
    abschlaege?:unknown[];
}
interface BaustelleTotals {
    grand: number;
    offen: number;
}
interface Kunde {
    id:        number;
    firma?:    string;
    vorname?:  string;
    nachname?: string;
    kundennummer?: string;
}

// ── Reaktive Daten-Refs (direktes Update statt computed+_tick) ──────────────
// Zuverlässiger als computed()+_tick: Daten werden explizit gesetzt wenn
// renderList() den refreshKey erhöht, unabhängig von Vue-Caching.
const _rawBaustellen = ref<Baustelle[]>([]);
const _rawKunden     = ref<Kunde[]>([]);

function _refreshData(): void {
    // Bevorzuge window.mobileBaustellen (von mobile.html vor renderList() gesetzt),
    // Fallback auf getVisibleBaustellen() für den initialen Render.
    const fromWindow = (w['mobileBaustellen'] as Baustelle[] | undefined);
    _rawBaustellen.value = (fromWindow && fromWindow.length > 0
        ? fromWindow
        : ((w['getVisibleBaustellen'] as (() => Baustelle[]) | undefined)?.() ?? [])
    ).slice().sort((a, b) => a.name.localeCompare(b.name, 'de'));
    _rawKunden.value = (w['kundenData'] as Kunde[] | undefined) ?? [];
    const el = document.getElementById('mobileSearchInput') as HTMLInputElement | null;
    searchQ.value = el?.value ?? '';
}

onMounted(() => {
    const el = document.getElementById('mobileSearchInput') as HTMLInputElement | null;
    el?.addEventListener('input', () => { searchQ.value = el.value ?? ''; });
    _refreshData(); // initialer Versuch (Daten evtl. schon geladen)
});

watch(() => props.refreshKey.value, _refreshData);

// ── Daten aus reaktiven Refs ─────────────────────────────────
const allBaustellen = computed<Baustelle[]>(() => {
    const tokens = searchQ.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
    const raw    = _rawBaustellen.value;
    if (!tokens.length) return raw;
    const kundeHay: Record<number, string> = {};
    _rawKunden.value.forEach(k => {
        kundeHay[k.id] = [k.firma, k.vorname, k.nachname, k.kundennummer].filter(Boolean).join(' ').toLowerCase();
    });
    return raw.filter(b => {
        const hay = ((b.name ?? '') + ' ' + (b.projektNr ?? '') + ' ' + (b.kundeId ? (kundeHay[b.kundeId] ?? '') : '')).toLowerCase();
        return tokens.every(t => hay.includes(t));
    });
});

const topLevel = computed(() => allBaustellen.value.filter(b => !b.parentId));
const subMap   = computed<Record<number, Baustelle[]>>(() => {
    const m: Record<number, Baustelle[]> = {};
    allBaustellen.value.filter(b => b.parentId).forEach(b => {
        if (!m[b.parentId!]) m[b.parentId!] = [];
        m[b.parentId!].push(b);
    });
    return m;
});

const kundeMap = computed<Record<number, Kunde>>(() =>
    Object.fromEntries(_rawKunden.value.map(k => [k.id, k])),
);

// Nach Kunden gruppieren
interface KundeGroup { kundeId: number; name: string; baustellen: Baustelle[] }

const grouped = computed<{ byKunde: KundeGroup[]; noKunde: Baustelle[] }>(() => {
    const byKunde: Record<number, Baustelle[]> = {};
    const noKunde: Baustelle[] = [];
    topLevel.value.forEach(b => {
        if (b.kundeId && kundeMap.value[b.kundeId]) {
            if (!byKunde[b.kundeId]) byKunde[b.kundeId] = [];
            byKunde[b.kundeId].push(b);
        } else {
            noKunde.push(b);
        }
    });
    const groups: KundeGroup[] = Object.entries(byKunde)
        .map(([id, bs]) => {
            const k = kundeMap.value[+id];
            return { kundeId: +id, name: k?.firma || [k?.vorname, k?.nachname].filter(Boolean).join(' ') || '', baustellen: bs };
        })
        .sort((a, b) => a.name.localeCompare(b.name, 'de'));
    return { byKunde: groups, noKunde };
});

const isEmpty = computed(() =>
    topLevel.value.length === 0 && Object.keys(subMap.value).length === 0,
);

// ── Helpers ───────────────────────────────────────────────────
function totals(b: Baustelle): BaustelleTotals {
    return (w['calcTotals'] as ((b: Baustelle) => BaustelleTotals) | undefined)?.(b) ?? { grand: 0, offen: 0 };
}
function fmt(v: number): string {
    return (w['fmt'] as ((v: number) => string) | undefined)?.(v) ?? v.toFixed(2);
}
function showDetail(id: number) {
    (w['showDetail'] as ((id: number) => void) | undefined)?.(id);
}
</script>

<template>
  <!-- Leer-Zustand -->
  <div v-if="isEmpty" class="empty-state">
    <div class="icon">🏗</div>
    <h3>Keine Baustellen</h3>
    <p>Tippen Sie auf + um eine neue Baustelle anzulegen.</p>
  </div>

  <template v-else>
    <!-- ── Baustellen nach Kunden ────────────────────────────── -->
    <template v-for="gruppe in grouped.byKunde" :key="gruppe.kundeId">
      <div class="section-label">{{ gruppe.name }}</div>
      <div class="card" style="padding:0">
        <template v-for="(b, idx) in gruppe.baustellen" :key="b.id">
          <BaustelleRow :b="b" :indent="false" :sub-map="subMap" :last="idx === gruppe.baustellen.length - 1"
                        :totals-fn="totals" :fmt-fn="fmt" @select="showDetail" />
        </template>
      </div>
    </template>

    <!-- ── Baustellen ohne Kunden ─────────────────────────────── -->
    <template v-if="grouped.noKunde.length > 0">
      <div v-if="grouped.byKunde.length > 0" class="section-label">Ohne Kundenzuordnung</div>
      <div class="card" style="padding:0">
        <template v-for="(b, idx) in grouped.noKunde" :key="b.id">
          <BaustelleRow :b="b" :indent="false" :sub-map="subMap" :last="idx === grouped.noKunde.length - 1"
                        :totals-fn="totals" :fmt-fn="fmt" @select="showDetail" />
        </template>
      </div>
    </template>
  </template>
</template>

<!-- ── BaustelleRow als Sub-Komponente ───────────────────── -->
<script lang="ts">
import { defineComponent, type PropType } from 'vue';

interface Baustelle {
    id: number; name: string; parentId?: number | null;
    material?: unknown[]; arbeitszeit?: unknown[]; abschlaege?: unknown[];
}
interface Totals { grand: number; offen: number }

export const BaustelleRow = defineComponent({
    name: 'BaustelleRow',
    props: {
        b:          { type: Object as PropType<Baustelle>, required: true },
        indent:     { type: Boolean, default: false },
        subMap:     { type: Object as PropType<Record<number, Baustelle[]>>, default: () => ({}) },
        last:       { type: Boolean, default: false },
        totalsFn:   { type: Function as PropType<(b: Baustelle) => Totals>, required: true },
        fmtFn:      { type: Function as PropType<(v: number) => string>, required: true },
    },
    emits: ['select'],
    setup(props, { emit }) {
        const subs = () => props.subMap[props.b.id] ?? [];
        const t    = () => props.totalsFn(props.b);
        const isPaid     = () => t().grand > 0.005 && t().offen <= 0.005;
        const badgeCls   = () => isPaid() ? 'badge-green' : (t().offen > 0.005 ? 'badge-blue' : 'badge-orange');
        const badgeTxt   = () => isPaid() ? '✓ Bezahlt' : `${props.fmtFn(t().offen)} offen`;
        return { subs, t, badgeCls, badgeTxt, emit };
    },
    template: `
<div>
  <div class="list-row"
       :style="indent ? 'margin-left:20px' : ''"
       @click="emit('select', b.id)">
    <div class="list-row-icon">{{ indent ? '📄' : '🏗' }}</div>
    <div class="list-row-body">
      <div class="list-row-title">{{ b.name }}</div>
      <div class="list-row-sub">
        {{ (b.material||[]).length }} Material · {{ (b.arbeitszeit||[]).length }} AZ · {{ (b.abschlaege||[]).length }} Abschl.
      </div>
    </div>
    <div class="list-row-right">
      <div class="list-row-amount price-sensitive">{{ fmtFn(t().grand) }}</div>
      <div class="badge price-sensitive" :class="badgeCls()">{{ badgeTxt() }}</div>
    </div>
    <span class="chevron">›</span>
  </div>
  <BaustelleRow
    v-for="sub in subs()" :key="sub.id"
    :b="sub" :indent="true" :sub-map="subMap" :last="false"
    :totals-fn="totalsFn" :fmt-fn="fmtFn"
    @select="emit('select', $event)"
  />
</div>`,
});
</script>
