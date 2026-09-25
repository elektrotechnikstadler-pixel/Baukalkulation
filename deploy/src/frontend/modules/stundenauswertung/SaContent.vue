<script setup lang="ts">
// ============================================================
// SaContent.vue – Stundenauswertung Inhalt (P5.3)
// ============================================================
// Mount-Punkt: #saContent (innerhalb des statischen Modals).
// Bridge: renderStundenauswertung()-Override triggert refreshKey.
// Die statischen Filterelemente (#saSearch, #saUserSelect etc.)
// bleiben erhalten; Vue lauscht darauf via onMounted-EventListeners.
//
// Berechnungen: Escape-Hatches zu script.js-Globals
//   (calcGleitzeitSaldo, _computeZeitAnomalien, getUserArbeitstage, …)
// ============================================================

import { ref, computed, watch, onMounted } from 'vue';
import { formatDate } from '@core/utils.ts';
import type { SaUser, SaEntry } from './types.ts';

// ── Props ─────────────────────────────────────────────────────
const props = defineProps<{
    refreshKey: ReturnType<typeof ref<number>>;
}>();

// ── Globale Helfer ────────────────────────────────────────────
const w = window as unknown as Record<string, unknown>;

// ── Filter-State (bridged von statischen DOM-Elementen) ───────
const selectedUser  = ref('');
const searchQ       = ref('');
const filterFrom    = ref('');
const filterTo      = ref('');
const showAnomalies = ref(false);

// ── Loading-State ─────────────────────────────────────────────
const loading = ref(false);

// ── Bridge: statische Filter-Elemente → reaktive Refs ────────
function syncFilters() {
    selectedUser.value  = (document.getElementById('saUserSelect')  as HTMLSelectElement | null)?.value  ?? '';
    searchQ.value       = (document.getElementById('saSearch')      as HTMLInputElement   | null)?.value  ?? '';
    filterFrom.value    = (document.getElementById('saFilterFrom')  as HTMLInputElement   | null)?.value  ?? '';
    filterTo.value      = (document.getElementById('saFilterTo')    as HTMLInputElement   | null)?.value  ?? '';
    showAnomalies.value = (document.getElementById('saShowAnomalies') as HTMLInputElement | null)?.checked ?? false;
}

onMounted(() => {
    // Einmalig initialisieren + auf jede Änderung lauschen
    syncFilters();
    ['saUserSelect', 'saSearch', 'saFilterFrom', 'saFilterTo', 'saShowAnomalies']
        .forEach(id => document.getElementById(id)
            ?.addEventListener('change', syncFilters));
    document.getElementById('saSearch')?.addEventListener('input', syncFilters);
});

watch(() => props.refreshKey.value, () => {
    loading.value = false;
    syncFilters();
    _tick.value++; // erzwingt Neuauswertung der non-reaktiven window-Globals
});

// ── Daten aus Globals lesen ────────────────────────────────────
// WICHTIG: _tick.value als erste Zeile – erzwingt Neuauswertung wenn
// window.saAllXxx nach dem Modal-Öffnen gesetzt wird (nicht reaktiv).
const _tick = ref(0);
const allZeit    = computed(() => { _tick.value; return (w['saAllZeit']    as Record<string, { entries: SaEntry[] }> | undefined) ?? {}; });
const allUsers   = computed(() => { _tick.value; return (w['saAllUsers']   as SaUser[]                               | undefined) ?? []; });
const gleitzeitAll = computed(() => { _tick.value; return (w['saGleitzeitAll'] as Record<string, unknown[]>          | undefined) ?? {}; });
const saAbschlussJahr = computed(() => parseInt((w['appSettings'] as Record<string, unknown>)?.['zeiterfassung_abschluss_jahr'] as string) || 0);
const saShowClosed = computed(() => (document.getElementById('saShowClosed') as HTMLInputElement | null)?.checked ?? false);
const isErweitert = computed(() => !!(w['appSettings'] as Record<string, unknown> | undefined)?.['erweiterte_zeiterfassung']);

// ── Gefilterte User-Liste ─────────────────────────────────────
const visibleUsers = computed<SaUser[]>(() => {
    let users = allUsers.value.filter(u => u.role !== 'admin' && u.showInZeitverwaltung !== false);
    if (selectedUser.value) users = users.filter(u => u.username === selectedUser.value);
    return users;
});

// ── Per-User-Berechnung ───────────────────────────────────────
interface UserResult {
    user:        SaUser;
    entries:     SaEntry[];
    istTotal:    number;
    anomalien:   number;
    fehlbuchungen: number;
    anomalienHtml:    string;
    fehlbuchungenHtml: string;
    gleitzeitSaldo: number;
    urlaubJahr:  number;
    urlaubGenommen: number;
    expanded:    boolean;
}

const userResults = computed<UserResult[]>(() => {
    const year     = new Date().getFullYear();
    const q        = searchQ.value.toLowerCase().trim();
    const from     = filterFrom.value;
    const to       = filterTo.value;
    const showAnom = showAnomalies.value;
    const abJ      = saAbschlussJahr.value;
    const showClosed = saShowClosed.value;

    const getUserArbeitstage = w['getUserArbeitstage'] as ((u: unknown) => Set<number>) | undefined;
    const calcGleitzeitSaldo = w['calcGleitzeitSaldo'] as ((e: SaEntry[], s: number, y: number, b: unknown[], a: Set<number>) => number) | undefined;
    const _computeZeitAnomalien = w['_computeZeitAnomalien'] as ((e: SaEntry[], s: number, a: Set<number>, f: string, t: string) => unknown[]) | undefined;
    const _computeFehlbuchungen = w['_computeFehlbuchungen'] as ((e: SaEntry[], u: unknown) => unknown[]) | undefined;
    const countsTowardIst = w['countsTowardIst'] as ((typ: string) => boolean) | undefined;
    const gleitzeitAktiv = (w['appSettings'] as Record<string, unknown> | undefined)?.['gleitzeit_enabled'] !== false;
    const appData = w['appData'] as { baustellen?: { id: number; name: string }[] } | undefined;

    return visibleUsers.value.map(user => {
        const allEntries = allZeit.value[user.username]?.entries ?? [];
        let entries = [...allEntries];

        // Abgeschlossene Jahre ausblenden
        if (abJ && !showClosed) {
            entries = entries.filter(e => !e.datum || parseInt(e.datum.slice(0, 4)) > abJ);
        }
        if (from) entries = entries.filter(e => (e.datum ?? '') >= from);
        if (to)   entries = entries.filter(e => (e.datum ?? '') <= to);

        const sollTag     = user.sollstundenTag ?? 8;
        const arbeitstage = getUserArbeitstage?.(user) ?? new Set([1, 2, 3, 4, 5]);
        const anomalien   = _computeZeitAnomalien?.(entries, sollTag, arbeitstage, from, to) ?? [];
        const fehlbuch    = _computeFehlbuchungen?.(entries, user) ?? [];

        // HTML-Boxen für Anomalien und Fehlbuchungen (via script.js Escape-Hatch)
        const anomalienHtml    = anomalien.length
            ? ((w['_renderAnomalieBox'] as ((a: unknown[]) => string) | undefined)?.(anomalien) ?? '')
            : '';
        const fehlbuchungenHtml = fehlbuch.length
            ? ((w['_renderFehlbuchungBox'] as ((f: unknown[], u: string) => string) | undefined)?.(fehlbuch, user.username) ?? '')
            : '';

        if (showAnom) {
            if (!anomalien.length && !fehlbuch.length) return null;
            const keepDates = new Set([
                ...(anomalien as { datum: string }[]).map(a => a.datum),
                ...(fehlbuch  as { datum: string }[]).map(f => f.datum),
            ]);
            entries = entries.filter(e => keepDates.has(e.datum ?? ''));
        }

        if (q) {
            entries = entries.filter(e => {
                const bName = e.baustelleId
                    ? (appData?.baustellen?.find(b => b.id === e.baustelleId)?.name ?? '')
                    : '';
                return [e.datum, e.typ, bName, e.bemerkung ?? '', String(e.stunden ?? '')]
                    .join(' ').toLowerCase().includes(q);
            });
        }

        const buchungen = gleitzeitAll.value[user.username] as unknown[] ?? [];
        const gleitzeitSaldo = (gleitzeitAktiv && calcGleitzeitSaldo)
            ? calcGleitzeitSaldo(allEntries, sollTag, year, buchungen, arbeitstage)
            : 0;
        const istTotal = entries.filter(e => countsTowardIst?.(e.typ) ?? false)
            .reduce((s, e) => s + (e.stunden ?? 0), 0);
        const urlaubJahr    = (user.urlaubstageProJahr?.[year]) ?? 30;
        const urlaubGenommen = allEntries.filter(e => e.typ === 'urlaub' && e.datum?.startsWith(String(year))).length;

        return {
            user, entries, istTotal,
            anomalien: anomalien.length,
            fehlbuchungen: fehlbuch.length,
            anomalienHtml,
            fehlbuchungenHtml,
            gleitzeitSaldo,
            urlaubJahr, urlaubGenommen,
            expanded: showAnom || false,
        };
    }).filter((r): r is UserResult => r !== null);
});

// ── Expand-Zustand ────────────────────────────────────────────
const expandedUsers = ref(new Set<string>());
function toggleUser(username: string) {
    if (expandedUsers.value.has(username)) expandedUsers.value.delete(username);
    else expandedUsers.value.add(username);
    // trigger reactivity
    expandedUsers.value = new Set(expandedUsers.value);
}
function isExpanded(username: string) {
    return expandedUsers.value.has(username) || showAnomalies.value;
}

// ── Entry-Helpers ──────────────────────────────────────────────
function baustelleName(e: SaEntry): string {
    if (e.baustelleName) return e.baustelleName;
    if (!e.baustelleId) return '';
    const appData = w['appData'] as { baustellen?: { id: number; name: string }[] } | undefined;
    return appData?.baustellen?.find(b => b.id === e.baustelleId)?.name ?? '';
}

function typLabel(typ: string): string {
    return ((w['_seTypLabel'] as ((t: string) => string) | undefined)?.(typ)) ?? typ;
}

function gzColor(saldo: number): string {
    return saldo >= 0 ? '#28A745' : '#FF3B30';
}

// ── Aktionen ──────────────────────────────────────────────────
function editEntry(username: string, id: number) {
    (w['saEditEntry'] as ((u: string, id: number) => void) | undefined)?.(username, id);
}
function deleteEntry(username: string, id: number) {
    (w['saDeleteEntry'] as ((u: string, id: number) => void) | undefined)?.(username, id);
}
function addEntry(username: string) {
    (w['saAddEntry'] as ((u: string) => void) | undefined)?.(username);
}
function openGleitzeitDialog(username: string, saldo: number) {
    (w['openGleitzeitBuchungDialog'] as ((u: string, s: number) => void) | undefined)?.(username, saldo);
}
</script>

<template>
  <!-- ── Laden ─────────────────────────────────────────────── -->
  <div v-if="loading" style="text-align:center;padding:40px;color:var(--grey-400,#888)">
    Daten werden geladen …
  </div>

  <!-- ── Kein Benutzer ─────────────────────────────────────── -->
  <div v-else-if="visibleUsers.length === 0" style="text-align:center;padding:20px;color:var(--grey-400,#888)">
    Keine Benutzer gefunden.
  </div>

  <!-- ── Nur-Auffällige leer ───────────────────────────────── -->
  <div v-else-if="userResults.length === 0 && showAnomalies" style="text-align:center;padding:20px;color:var(--grey-400,#888)">
    ✅ Keine auffälligen Buchungen im gewählten Zeitraum.
  </div>

  <!-- ── Per-User Karten ───────────────────────────────────── -->
  <div v-else>
    <div v-for="r in userResults" :key="r.user.username" style="margin-bottom:12px">

      <!-- Summary-Header (klickbar zum Aufklappen) -->
      <div
        class="summary-box"
        style="cursor:pointer"
        @click="toggleUser(r.user.username)"
      >
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
          <div>
            <strong style="font-size:1rem">{{ r.user.username }}</strong>
            <small style="color:var(--grey-400,#888);margin-left:6px">{{ r.user.role }}</small>
            <span
              v-if="r.anomalien"
              style="background:#FFEBEE;color:#C62828;border:1px solid #EF9A9A;border-radius:10px;padding:1px 8px;font-size:.72rem;font-weight:700;margin-left:6px"
            >⚠️ {{ r.anomalien }}</span>
            <span
              v-if="r.fehlbuchungen"
              style="background:#C62828;color:#fff;border-radius:10px;padding:1px 8px;font-size:.72rem;font-weight:700;margin-left:4px"
            >⛔ {{ r.fehlbuchungen }}</span>
          </div>
          <div style="display:flex;gap:14px;font-size:.85rem;align-items:center">
            <span>{{ r.entries.length }} Einträge</span>
            <strong>{{ r.istTotal.toFixed(2) }} h</strong>
            <span v-if="r.gleitzeitSaldo !== 0" :style="{ color: gzColor(r.gleitzeitSaldo), fontWeight: '700' }">
              Gleitzeit: {{ r.gleitzeitSaldo >= 0 ? '+' : '' }}{{ r.gleitzeitSaldo.toFixed(2) }} h
            </span>
            <button
              v-if="r.gleitzeitSaldo !== 0"
              class="btn btn-secondary btn-sm"
              style="font-size:.75rem;padding:3px 8px"
              @click.stop="openGleitzeitDialog(r.user.username, r.gleitzeitSaldo)"
            >Gleitzeit</button>
            <span>Urlaub: {{ r.urlaubGenommen }}/{{ r.urlaubJahr }}</span>
            <button
              class="btn btn-secondary btn-sm"
              style="font-size:.75rem;padding:3px 8px"
              @click.stop="addEntry(r.user.username)"
            >+ Nachtragen</button>
          </div>
        </div>
      </div>

      <!-- Anomalie-Box (script.js-generiertes HTML mit Reparatur-Links) -->
      <div v-if="isExpanded(r.user.username) && r.anomalienHtml"
           v-html="r.anomalienHtml"
           style="margin-top:6px;margin-bottom:4px" />
      <!-- Fehlbuchungs-Box (script.js-generiertes HTML mit Reparatur-Buttons) -->
      <div v-if="isExpanded(r.user.username) && r.fehlbuchungenHtml"
           v-html="r.fehlbuchungenHtml"
           style="margin-top:6px;margin-bottom:4px" />

      <!-- Detail-Tabelle (aufgeklappt) -->
      <div v-if="isExpanded(r.user.username)" style="margin-top:4px">
        <table class="data-table" style="font-size:.85rem">
          <thead>
            <tr>
              <th style="width:100px">Datum</th>
              <th style="width:40px">Tag</th>
              <th style="width:90px">Typ</th>
              <template v-if="isErweitert">
                <th style="width:55px">Von</th>
                <th style="width:55px">Bis</th>
                <th style="width:55px">Pause</th>
              </template>
              <th>Baustelle</th>
              <th>Bemerkung</th>
              <th class="text-right" style="width:65px">Stunden</th>
              <th style="width:70px">Aktion</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="r.entries.length === 0">
              <td :colspan="isErweitert ? 10 : 7" class="hint-text" style="text-align:center;padding:12px">
                Keine Einträge.
              </td>
            </tr>
            <tr
              v-for="e in [...r.entries].sort((a, b) => (a.datum ?? '').localeCompare(b.datum ?? ''))"
              :key="e.id"
            >
              <td style="font-weight:600">{{ formatDate(e.datum) }}</td>
              <td>{{ e.datum ? ['So','Mo','Di','Mi','Do','Fr','Sa'][new Date(e.datum).getDay()] : '' }}</td>
              <td>{{ typLabel(e.typ) }}</td>
              <template v-if="isErweitert">
                <td>{{ e.von ?? '' }}</td>
                <td>{{ e.bis ?? '' }}</td>
                <td>{{ e.pause ? e.pause + ' min' : '' }}</td>
              </template>
              <td>{{ baustelleName(e) }}</td>
              <td>{{ e.bemerkung ?? '' }}</td>
              <td class="text-right">{{ (e.stunden ?? 0).toFixed(2) }}</td>
              <td>
                <button class="icon-btn" title="Bearbeiten"
                        @click="editEntry(r.user.username, e.id)">✏️</button>
                <button class="icon-btn danger" title="Löschen"
                        @click="deleteEntry(r.user.username, e.id)">🗑</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
