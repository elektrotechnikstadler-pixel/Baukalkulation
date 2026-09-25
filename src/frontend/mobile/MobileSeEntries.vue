<script setup lang="ts">
// ============================================================
// MobileSeEntries.vue – Mobile Zeiterfassungs-Einträge (P5 Mobile)
// ============================================================
// Mount-Punkt: #stundenerfassungScrollView in mobile.html
// Bridge: renderStundenerfassung()-Override → refreshKey
// Rendering: KPI-Kacheln + monatlich gruppierte Eintrags-Karten
// Escape-Hatches: calcSollMonat, calcGleitzeitSaldoMobile,
//   getVirtuelleFeiertagEintraegeM, getUserArbeitstage, countsTowardIstM
// ============================================================

import { ref, computed, watch, onMounted } from 'vue';
import type { ZeitEntry } from '../modules/zeiterfassung/types.ts';
import { TYP_EMOJI }      from '../modules/zeiterfassung/types.ts';
import { formatDate }      from '@core/utils.ts';

// ── Props ─────────────────────────────────────────────────────
const props = defineProps<{
    refreshKey: ReturnType<typeof ref<number>>;
}>();

// ── Globale Helfer ────────────────────────────────────────────
const w = window as unknown as Record<string, unknown>;

// ── Filter-State (bridged von #seSearchMobile) ────────────────
const searchQ   = ref('');

onMounted(() => {
    const el = document.getElementById('seSearchMobile') as HTMLInputElement | null;
    el?.addEventListener('input', () => { searchQ.value = el.value ?? ''; });
    searchQ.value = el?.value ?? '';
});

// ── Reaktive Daten-Refs (direktes Update statt computed+_tick) ──────────────
const _rawEntries    = ref<ZeitEntry[]>([]);
const _rawSollTag    = ref<number>(8);
const _rawStw        = ref<number>(5);
const _rawArbeitstage = ref<Set<number>>(new Set([1,2,3,4,5]));
const _rawGlzeit     = ref<unknown[]>([]);
const _rawUrlaubProJahr = ref<Record<number,number>>({});

function _refreshSe(): void {
    const el = document.getElementById('seSearchMobile') as HTMLInputElement | null;
    searchQ.value = el?.value ?? '';
    _rawEntries.value  = (w['zeitEntries'] as ZeitEntry[] | undefined) ?? [];
    _rawSollTag.value  = (w['seMobileSollTag'] as number | undefined) ?? 8;
    _rawStw.value      = (w['seMobileSollTageWoche'] as number | undefined) ?? 5;
    const raw = w['seMobileArbeitstage'];
    _rawArbeitstage.value = raw instanceof Set ? raw
        : ((w['getUserArbeitstage'] as ((o: object) => Set<number>) | undefined)
            ?.({ sollTageWoche: _rawStw.value }) ?? new Set([1,2,3,4,5]));
    _rawGlzeit.value   = (w['seMobileGleitzeitBuchungen'] as unknown[] | undefined) ?? [];
    _rawUrlaubProJahr.value = (w['seMobileUrlaubProJahr'] as Record<number,number> | undefined) ?? {};
}

watch(() => props.refreshKey.value, _refreshSe);

// ── Daten aus reaktiven Refs lesen ────────────────────────────────────────────
const entries      = computed<ZeitEntry[]>(() => _rawEntries.value);
const sollTag      = computed(() => _rawSollTag.value);
const stw          = computed(() => _rawStw.value);
const arbeitstage  = computed<Set<number>>(() => _rawArbeitstage.value);
const gleitzeitBuchungen = computed(() => _rawGlzeit.value);
const urlaubProJahr = computed(() => _rawUrlaubProJahr.value);
const gleitzeitAktiv = computed(() =>
    (w['appSettings'] as Record<string, unknown> | undefined)?.['gleitzeit_enabled'] !== false);
const seAbschlussJahr = computed(() =>
    parseInt((w['appSettings'] as Record<string, unknown> | undefined)?.['zeiterfassung_abschluss_jahr'] as string || '') || 0);
const isErweitert = computed(() =>
    !!(w['appSettings'] as Record<string, unknown> | undefined)?.['erweiterte_zeiterfassung']);

// ── KPI-Berechnungen ──────────────────────────────────────────
const now          = new Date();
const year         = now.getFullYear();
const currentMonth = year + '-' + String(now.getMonth() + 1).padStart(2, '0');
const todayStr     = now.toISOString().split('T')[0];

const vtFeiertage = computed<ZeitEntry[]>(() =>
    (w['getVirtuelleFeiertagEintraegeM'] as ((e: ZeitEntry[], s: number, a: Set<number>, y: number, m: number) => ZeitEntry[]) | undefined)
        ?.(entries.value, sollTag.value, arbeitstage.value, year, now.getMonth()) ?? []);

function countsTowardIst(typ: string): boolean {
    return (w['countsTowardIstM'] as ((t: string) => boolean) | undefined)?.(typ) ?? (typ === 'arbeit');
}

const monthEntries   = computed(() => entries.value.filter(e => e.datum?.startsWith(currentMonth)));
const monthHours     = computed(() =>
    monthEntries.value.filter(e => countsTowardIst(e.typ)).reduce((s, e) => s + (e.stunden ?? 0), 0)
    + vtFeiertage.value.reduce((s, e) => s + (e.stunden ?? 0), 0));
const monthKrank     = computed(() => monthEntries.value.filter(e => e.typ === 'krank').length);
const sollMonat      = computed(() =>
    (w['calcSollMonat'] as ((s: number, y: number, m: number, a: Set<number>) => number) | undefined)
        ?.(sollTag.value, year, now.getMonth(), arbeitstage.value) ?? 0);
const diffMonat      = computed(() => monthHours.value - sollMonat.value);
const gleitzeitSaldo = computed(() =>
    gleitzeitAktiv.value
        ? ((w['calcGleitzeitSaldoMobile'] as ((e: ZeitEntry[], s: number, y: number, b: unknown[], a: Set<number>) => number) | undefined)
            ?.(entries.value, sollTag.value, year, gleitzeitBuchungen.value, arbeitstage.value) ?? 0)
        : 0);
const urlaubJahr     = computed(() => urlaubProJahr.value[year] ?? 30);
const urlaubGenommen = computed(() =>
    entries.value.filter(e => e.typ === 'urlaub' && e.datum?.startsWith(String(year))).length);

// Heute
const heuteIst  = computed(() =>
    entries.value.filter(e => e.datum === todayStr && countsTowardIst(e.typ))
        .reduce((s, e) => s + (e.stunden ?? 0), 0));
const todayDow  = now.getDay();
const isWorkday = computed(() => arbeitstage.value.has(todayDow));
const heuteSoll = computed(() => isWorkday.value ? sollTag.value : 0);
const heuteDiff = computed(() => heuteIst.value - heuteSoll.value);

// ── Gefilterte + sortierte Einträge ───────────────────────────
const sortedEntries = computed<ZeitEntry[]>(() => {
    let list = entries.value.slice();
    if (seAbschlussJahr.value) {
        const showClosed = (document.getElementById('seShowClosedMobile') as HTMLInputElement | null)?.checked ?? false;
        if (!showClosed) {
            list = list.filter(e => !e.datum || parseInt(e.datum.slice(0, 4)) > seAbschlussJahr.value);
        }
    }
    list.sort((a, b) => {
        const da = a.datum ?? '', db = b.datum ?? '';
        const aThis = da.startsWith(currentMonth);
        const bThis = db.startsWith(currentMonth);
        if (aThis && !bThis) return -1;
        if (!aThis && bThis) return 1;
        return db.localeCompare(da) || ((b.id ?? 0) - (a.id ?? 0));
    });
    const q = searchQ.value.toLowerCase().trim();
    if (q) {
        list = list.filter(e =>
            [e.datum, e.typ, e.baustelleName ?? '', e.bemerkung ?? '', String(e.stunden ?? '')]
                .join(' ').toLowerCase().includes(q),
        );
    }
    return list;
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

// Bearbeitbar: letzte 5 Tage (datumsbasiert)
const fiveDaysAgo = new Date();
fiveDaysAgo.setHours(0, 0, 0, 0);
fiveDaysAgo.setDate(fiveDaysAgo.getDate() - 5);
const editableIds = computed(() => new Set(
    entries.value
        .filter(e => e.datum && new Date(e.datum + 'T00:00:00') >= fiveDaysAgo)
        .map(e => e.id),
));

// ── Einklapp-Zustand ──────────────────────────────────────────
const collapsed = ref(new Set<string>());
function toggleMonth(mk: string) {
    if (collapsed.value.has(mk)) collapsed.value.delete(mk);
    else collapsed.value.add(mk);
    collapsed.value = new Set(collapsed.value);
    try { localStorage.setItem('seCollapsedMonths', JSON.stringify([...collapsed.value].reduce((o, k) => ({ ...o, [k]: true }), {}))) } catch (_) {}
}
function isCollapsed(mk: string): boolean {
    if (searchQ.value) return false;
    return collapsed.value.has(mk) || (mk !== currentMonth && !collapsed.value.has(mk + '_open'));
}

// Initialisieren aus localStorage
onMounted(() => {
    try {
        const stored = JSON.parse(localStorage.getItem('seCollapsedMonths') || '{}');
        const initialCollapsed = new Set<string>();
        // Nicht-aktueller Monat startet eingeklappt
        for (const { mk } of monthGroups.value) {
            if (stored[mk] === true || (stored[mk] === undefined && mk !== currentMonth)) {
                initialCollapsed.add(mk);
            }
        }
        collapsed.value = initialCollapsed;
    } catch (_) {}
});

// ── Typ-Anzeige ───────────────────────────────────────────────
function typIcon(typ: string): string {
    const icons: Record<string, string> = {
        urlaub: '🌴', krank: '🤒', gleitzeit: '⏱', sonstig: '⚠️', feiertag: '🎉',
    };
    return icons[typ] ?? '⏱';
}
function typText(e: ZeitEntry): string {
    if (e.typ === 'urlaub')    return 'Urlaubstag';
    if (e.typ === 'krank')     return 'Krankheitstag';
    if (e.typ === 'gleitzeit') return 'Gleittag';
    if (e.typ === 'sonstig')   return e.bemerkung || 'Sonstig';
    return `${(e.stunden ?? 0).toFixed(2)} h`;
}
function vonBisText(e: ZeitEntry): string {
    if (!isErweitert.value || !e.von || !e.bis) return '';
    return ` (${e.von}–${e.bis}, ${e.pause ?? 0}′ Pause)`;
}

// ── Monat-Name ────────────────────────────────────────────────
const MONTH_NAMES = ['Januar','Februar','März','April','Mai','Juni',
                     'Juli','August','September','Oktober','November','Dezember'];
function monthLabel(mk: string): string {
    if (mk === '?') return 'Ohne Datum';
    const d = new Date(mk + '-01');
    return `${MONTH_NAMES[d.getMonth()]} ${d.getFullYear()}`;
}

// ── Aktionen ──────────────────────────────────────────────────
function editEntry(id: number) {
    (w['showStundenerfassungSheet'] as ((id: number) => void) | undefined)?.(id);
}
function deleteEntry(id: number) {
    (w['delStundenerfassung'] as ((id: number) => void) | undefined)?.(id);
}

// ── Farben ────────────────────────────────────────────────────
function positiveColor(v: number) { return v >= 0 ? '#28A745' : '#FF3B30'; }
</script>

<template>
  <!-- ── KPI-Strip ───────────────────────────────────────────── -->
  <div class="kpi-strip" style="margin-bottom:10px;flex-wrap:wrap;gap:6px">
    <div class="kpi-tile accent-yellow" style="flex:1;min-width:46%">
      <div class="kpi-tile-label">Ist {{ MONTH_NAMES[now.getMonth()] }}</div>
      <div class="kpi-tile-value">{{ monthHours.toFixed(2) }} h</div>
    </div>
    <div class="kpi-tile accent-blue" style="flex:1;min-width:46%">
      <div class="kpi-tile-label">Soll {{ MONTH_NAMES[now.getMonth()] }}</div>
      <div class="kpi-tile-value">{{ sollMonat.toFixed(2) }} h</div>
    </div>
    <div class="kpi-tile" style="flex:1;min-width:46%;background:var(--surface)">
      <div class="kpi-tile-label">+/- Monat</div>
      <div class="kpi-tile-value" :style="{ color: positiveColor(diffMonat) }">
        {{ diffMonat >= 0 ? '+' : '' }}{{ diffMonat.toFixed(2) }} h
      </div>
    </div>
    <div v-if="gleitzeitAktiv" class="kpi-tile" style="flex:1;min-width:46%;background:var(--surface)">
      <div class="kpi-tile-label">Gleitzeitkonto {{ year }}</div>
      <div class="kpi-tile-value" :style="{ color: positiveColor(gleitzeitSaldo) }">
        {{ gleitzeitSaldo >= 0 ? '+' : '' }}{{ gleitzeitSaldo.toFixed(2) }} h
      </div>
    </div>
    <div class="kpi-tile" style="flex:1;min-width:30%;background:var(--surface)">
      <div class="kpi-tile-label">Urlaub {{ year }}</div>
      <div class="kpi-tile-value">{{ urlaubGenommen }}/{{ urlaubJahr }}</div>
    </div>
    <div class="kpi-tile" style="flex:1;min-width:30%;background:var(--surface)">
      <div class="kpi-tile-label">Krank</div>
      <div class="kpi-tile-value">{{ monthKrank }} T</div>
    </div>
    <div class="kpi-tile" style="flex:1;min-width:30%;background:var(--surface)">
      <div class="kpi-tile-label">Heute Ist</div>
      <div class="kpi-tile-value" :style="{ color: positiveColor(heuteDiff) }">
        {{ heuteIst.toFixed(2) }} h
      </div>
    </div>
  </div>

  <!-- ── Leer-Zustand ─────────────────────────────────────────── -->
  <div v-if="sortedEntries.length === 0" class="empty-state">
    <div class="icon">⏱</div>
    <h3>Keine Einträge</h3>
    <p>Tippen Sie auf + um Stunden zu erfassen.</p>
  </div>

  <!-- ── Monatlich gruppierte Karten ───────────────────────────── -->
  <template v-else>
    <div v-for="{ mk, entries: mEntries } in monthGroups" :key="mk">

      <!-- Monats-Header (klappbar) -->
      <div
        class="section-label se-month-head"
        style="cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:8px"
        @click="toggleMonth(mk)"
      >
        <span>
          {{ monthLabel(mk) }}
          <span style="opacity:.55;font-weight:400">· {{ mEntries.length }}</span>
        </span>
        <span>{{ isCollapsed(mk) ? '▸' : '▾' }}</span>
      </div>

      <!-- Monatskarte (ein-/ausgeklappt) -->
      <div v-show="!isCollapsed(mk)" class="card se-month-card">
        <div
          v-for="e in mEntries" :key="e.id"
          class="list-row"
          :style="{
            cursor: editableIds.has(e.id) ? 'pointer' : 'default',
            opacity: editableIds.has(e.id) ? '1' : '0.7',
          }"
          @click="editableIds.has(e.id) && editEntry(e.id)"
        >
          <!-- Typ-Icon -->
          <div class="list-row-icon">{{ typIcon(e.typ) }}</div>

          <!-- Inhalt -->
          <div class="list-row-body">
            <div class="list-row-title">{{ formatDate(e.datum) }}</div>
            <div class="list-row-sub">
              {{ typText(e) }}{{ vonBisText(e) }}{{ e.baustelleName ? ' · ' + e.baustelleName : '' }}{{ e.bemerkung && e.typ !== 'sonstig' ? ' · ' + e.bemerkung : '' }}
            </div>
          </div>

          <!-- Löschen-Button -->
          <button
            v-if="editableIds.has(e.id)"
            style="background:none;border:none;font-size:1.1rem;cursor:pointer;padding:4px 2px;color:var(--danger)"
            @click.stop="deleteEntry(e.id)"
          >🗑</button>
        </div>
      </div>

    </div>
  </template>
</template>
