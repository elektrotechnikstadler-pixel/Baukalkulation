<script setup lang="ts">
// ============================================================
// RechnungenView.vue – Rechnungen & Angebote Liste (P4 Island)
// ============================================================
// Vollständige Vue 3 Ersetzung von renderRechnungenView() aus script.js.
// Datenbezug: eigener API-Call (unabhängig von script.js-globalem rechnungenData).
// Actions (Bearbeiten, ZUGFeRD, E-Mail, Löschen): delegiert an script.js-Globals
// als Escape-Hatches bis diese Features ebenfalls migriert werden.
//
// Mount-Punkt: #rechnungenView (von init.ts / rechnungen/index.ts gesetzt)
// Sichtbarkeit: script.js togglet hidden-Klasse am Container (Vue bleibt gemountet)
// ============================================================

import { ref, computed, watch } from 'vue';
import { apiGet }               from '@core/api.ts';
import { useCanDo }             from '@core/auth.ts';
import { formatDate }           from '@core/utils.ts';
import type { Rechnung, RechnungTyp, ListRechnungenResponse } from './types.ts';
import { STATUS_STYLE }         from './types.ts';

// ── Props (reaktive Refs von index.ts) ───────────────────────
const props = defineProps<{
    /** Aktueller Filter (vom override in index.ts gesetzt) */
    filterTyp:  ReturnType<typeof ref<string | undefined>>;
    /** Inkrement bei jedem showRechnungenView()-Aufruf → Refresh */
    refreshKey: ReturnType<typeof ref<number>>;
}>();

// ── State ─────────────────────────────────────────────────────
const rechnungen = ref<Rechnung[]>([]);
const loading    = ref(false);
const error      = ref<string | null>(null);
const searchQ    = ref('');

// ── Berechtigungen (reaktiv über useCanDo → verfolgt Store-Updates) ────────────
const canManageRe  = useCanDo('canManageRechnungen');
const canManageAn  = useCanDo('canManageAngebote');
const _canSeeRe    = useCanDo('canSeeRechnungen');
const _canSeeAn    = useCanDo('canSeeAngebote');
const canSeeRe     = computed(() => _canSeeRe.value    || canManageRe.value);
const canSeeAn     = computed(() => _canSeeAn.value    || canManageAn.value);

// ── Daten laden ───────────────────────────────────────────────
async function loadData() {
    loading.value = true;
    error.value   = null;
    try {
        const j = await apiGet<ListRechnungenResponse>('list_rechnungen');
        if (!j.ok) { error.value = j.error ?? 'Fehler beim Laden.'; return; }
        rechnungen.value = j.rechnungen ?? [];

        // Compat: rechnungenData für script.js-Escape-Hatch (openRechnungForm)
        (window as unknown as Record<string, unknown>)['rechnungenData'] = [...rechnungen.value];

        // Sidebar-Badges aktualisieren (Compat: script.js setzt die Elemente)
        const reCount = rechnungen.value.filter(r => r.typ === 'rechnung').length;
        const anCount = rechnungen.value.filter(r => r.typ === 'angebot').length;
        const reBadge = document.getElementById('reCountBadge');
        const anBadge = document.getElementById('anCountBadge');
        if (reBadge) reBadge.textContent = reCount > 0 ? `(${reCount})` : '';
        if (anBadge) anBadge.textContent = anCount > 0 ? `(${anCount})` : '';
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Laden fehlgeschlagen.';
    } finally {
        loading.value = false;
    }
}

// Kein immediate: Daten nur bei expliziter Navigation laden.
watch(() => props.refreshKey.value, (val) => { if ((val ?? 0) > 0) loadData(); });

// ── Gefilterte Listen ─────────────────────────────────────────
const q = computed(() => searchQ.value.trim().toLowerCase());

function matches(r: Rechnung): boolean {
    if (!q.value) return true;
    return [r.nummer, r.kundeName, r.baustelleName, r.projektNr, r.beschreibung, r.status]
        .filter(Boolean)
        .join(' ')
        .toLowerCase()
        .includes(q.value);
}

const filterTypVal = computed(() => props.filterTyp.value as RechnungTyp | undefined);

const showRe = computed(() => (!filterTypVal.value || filterTypVal.value === 'rechnung') && canSeeRe.value);
const showAn = computed(() => (!filterTypVal.value || filterTypVal.value === 'angebot')  && canSeeAn.value);

const filteredRechnungen = computed(() =>
    showRe.value ? rechnungen.value.filter(r => r.typ === 'rechnung' && matches(r)) : [],
);
const filteredAngebote = computed(() =>
    showAn.value ? rechnungen.value.filter(r => r.typ === 'angebot' && matches(r)) : [],
);

const title = computed(() =>
    filterTypVal.value === 'rechnung' ? 'Rechnungen'
    : filterTypVal.value === 'angebot' ? 'Angebote'
    : 'Rechnungen & Angebote',
);

// ── Betrag berechnen ──────────────────────────────────────────
function calcTotal(r: Rechnung): number {
    return (r.positionen ?? [])
        .filter(p => (p.posTyp ?? 'normal') === 'normal' && !p.parentId)
        .reduce((sum, p) => {
            // Gruppen-Kinder als Anteil einbeziehen
            const gp = (r.positionen ?? []).filter(c => c.parentId === p.id);
            if (gp.length > 0) {
                return sum + gp
                    .filter(c => (c.posTyp ?? 'normal') === 'normal')
                    .reduce((s, c) => s + (c.menge ?? 0) * (c.einzelpreis ?? 0) * (1 - (c.rabatt ?? 0) / 100), 0);
            }
            return sum + (p.menge ?? 0) * (p.einzelpreis ?? 0) * (1 - (p.rabatt ?? 0) / 100);
        }, 0);
}

function fmtEur(v: number): string {
    return v.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function fmtD(d?: string): string {
    if (!d) return '';
    return formatDate(d.split('T')[0]);
}

// ── Aktions-Helpers (Escape-Hatch zu script.js) ───────────────
const w = window as unknown as Record<string, unknown>;

function openForm(typ: RechnungTyp, id?: number) {
    (w['openRechnungForm'] as Function)?.(typ, id);
}
function dlZugferd(id: number) {
    (w['downloadZugferd'] as Function)?.(id);
}
function openEmail(r: Rechnung) {
    const to   = r.kundeEmail ?? '';
    const subj = `${r.typ === 'rechnung' ? 'Rechnung' : 'Angebot'} ${r.nummer ?? ''}`;
    const fn   = r.typ === 'rechnung'
        ? (to2: string, s2: string) => (w['sendRechnungEmail'] as Function)?.(r.id, to2, s2)
        : (to2: string, s2: string) => (w['sendAngebotEmail']  as Function)?.(r.id, to2, s2);
    (w['openEmailDialog'] as Function)?.(to, subj, fn);
}
function doDelete(id: number) {
    (w['deleteRechnung'] as Function)?.(id);
}
</script>

<template>
  <div class="bk-re-view">

    <!-- ── Header ──────────────────────────────────────────── -->
    <div class="bk-re-header">
      <h2>
        <span data-ico="clipboard">{{ title }}</span>
      </h2>
      <div class="bk-re-actions">
        <button
          v-if="showRe && canManageRe"
          class="btn btn-primary btn-sm"
          @click="openForm('rechnung')"
        >+ Neue Rechnung</button>
        <button
          v-if="showAn && canManageAn"
          class="btn btn-secondary btn-sm"
          @click="openForm('angebot')"
        >+ Neues Angebot</button>
        <button
          class="btn btn-secondary btn-sm"
          title="Aktualisieren"
          @click="loadData"
        >⟳</button>
      </div>
    </div>

    <!-- ── Suche ────────────────────────────────────────────── -->
    <div class="bk-re-search">
      <input
        v-model="searchQ"
        type="text"
        class="form-control"
        placeholder="Suche nach Nr., Kunde, Projekt, Beschreibung …"
      />
      <button v-if="searchQ" class="btn btn-secondary btn-sm" @click="searchQ = ''">
        Reset
      </button>
    </div>

    <!-- ── Laden / Fehler ───────────────────────────────────── -->
    <div v-if="loading" class="bk-re-state">
      <span>Laden …</span>
    </div>
    <div v-else-if="error" class="bk-re-state bk-re-error">
      {{ error }}
    </div>

    <!-- ── Leer-Zustand ─────────────────────────────────────── -->
    <p
      v-else-if="!loading && !showRe && !showAn"
      class="bk-re-empty"
    >Keine Berechtigung für Rechnungen oder Angebote.</p>

    <p
      v-else-if="!loading && filteredRechnungen.length === 0 && filteredAngebote.length === 0"
      class="bk-re-empty"
    >Keine passenden Einträge gefunden.</p>

    <!-- ── Rechnungen-Tabelle ───────────────────────────────── -->
    <template v-if="!loading && showRe && filteredRechnungen.length > 0">
      <h3 class="bk-re-section-title bk-re-section-re">
        Rechnungen ({{ filteredRechnungen.length }})
      </h3>
      <div class="bk-re-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Nummer</th>
              <th>Kunde</th>
              <th>Projekt</th>
              <th>Proj.-Nr.</th>
              <th>Beschreibung</th>
              <th>Datum</th>
              <th class="text-right">Betrag (€)</th>
              <th>Status</th>
              <th class="bk-re-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in filteredRechnungen" :key="r.id">
              <td><strong>{{ r.nummer }}</strong></td>
              <td>{{ r.kundeName ?? '' }}</td>
              <td>{{ r.baustelleName ?? '' }}</td>
              <td>{{ r.projektNr ?? '–' }}</td>
              <td>{{ r.beschreibung ?? '–' }}</td>
              <td>{{ fmtD(r.datum ?? r.createdAt) }}</td>
              <td class="text-right">{{ fmtEur(calcTotal(r)) }}</td>
              <td>
                <span
                  class="bk-re-badge"
                  :style="{
                    background: STATUS_STYLE[r.status]?.bg,
                    color:      STATUS_STYLE[r.status]?.color,
                  }"
                >{{ STATUS_STYLE[r.status]?.label ?? r.status }}</span>
              </td>
              <td class="bk-re-col-actions">
                <button v-if="canManageRe" class="btn btn-secondary btn-sm"
                        @click="openForm('rechnung', r.id)">Bearb.</button>
                <button class="btn btn-secondary btn-sm" style="color:#2E7D32"
                        title="ZUGFeRD E-Rechnung herunterladen"
                        @click="dlZugferd(r.id)">ZUGFeRD</button>
                <button class="btn btn-secondary btn-sm" style="color:#1565C0"
                        title="Per E-Mail senden"
                        @click="openEmail(r)">✉</button>
                <button v-if="canManageRe" class="btn btn-secondary btn-sm"
                        style="color:#e53935"
                        @click="doDelete(r.id)">–</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- ── Angebote-Tabelle ─────────────────────────────────── -->
    <template v-if="!loading && showAn && filteredAngebote.length > 0">
      <h3 class="bk-re-section-title bk-re-section-an">
        Angebote ({{ filteredAngebote.length }})
      </h3>
      <div class="bk-re-table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Nummer</th>
              <th>Kunde</th>
              <th>Projekt</th>
              <th>Proj.-Nr.</th>
              <th>Beschreibung</th>
              <th>Datum</th>
              <th class="text-right">Betrag (€)</th>
              <th>Status</th>
              <th class="bk-re-col-actions"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in filteredAngebote" :key="r.id">
              <td><strong>{{ r.nummer }}</strong></td>
              <td>{{ r.kundeName ?? '' }}</td>
              <td>{{ r.baustelleName ?? '' }}</td>
              <td>{{ r.projektNr ?? '–' }}</td>
              <td>{{ r.beschreibung ?? '–' }}</td>
              <td>{{ fmtD(r.datum ?? r.createdAt) }}</td>
              <td class="text-right">{{ fmtEur(calcTotal(r)) }}</td>
              <td>
                <span
                  class="bk-re-badge"
                  :style="{
                    background: STATUS_STYLE[r.status]?.bg,
                    color:      STATUS_STYLE[r.status]?.color,
                  }"
                >{{ STATUS_STYLE[r.status]?.label ?? r.status }}</span>
              </td>
              <td class="bk-re-col-actions">
                <button v-if="canManageAn" class="btn btn-secondary btn-sm"
                        @click="openForm('angebot', r.id)">Bearb.</button>
                <button class="btn btn-secondary btn-sm" style="color:#1565C0"
                        title="Per E-Mail senden"
                        @click="openEmail(r)">✉</button>
                <button v-if="canManageAn" class="btn btn-secondary btn-sm"
                        style="color:#e53935"
                        @click="doDelete(r.id)">–</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

  </div>
</template>

<style scoped>
.bk-re-view {
  max-width: 1200px;
  margin: 0 auto;
  padding: 4px 0 16px;
}

/* ── Header ──────────────────────────────────────────────── */
.bk-re-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 14px;
}
.bk-re-header h2 {
  margin: 0;
  font-size: 1.1rem;
}
.bk-re-actions {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

/* ── Suche ───────────────────────────────────────────────── */
.bk-re-search {
  display: flex;
  align-items: center;
  gap: 8px;
  max-width: 520px;
  margin-bottom: 12px;
}
.bk-re-search .form-control {
  flex: 1;
}

/* ── Lade / Fehler / Leer ────────────────────────────────── */
.bk-re-state {
  padding: 24px;
  text-align: center;
  color: var(--text-muted, #888);
  font-style: italic;
}
.bk-re-error {
  color: #c62828;
  font-style: normal;
}
.bk-re-empty {
  color: var(--text-muted, #888);
  font-style: italic;
  margin: 12px 0;
}

/* ── Abschnitts-Überschriften ────────────────────────────── */
.bk-re-section-title {
  font-size: .95rem;
  margin: 16px 0 8px;
}
.bk-re-section-re { color: var(--primary, #00B4D8); }
.bk-re-section-an { color: #1565C0; }

/* ── Tabelle ─────────────────────────────────────────────── */
.bk-re-table-wrap {
  overflow-x: auto;
  margin-bottom: 8px;
}
.text-right {
  text-align: right;
}
.bk-re-col-actions {
  width: 180px;
  white-space: nowrap;
}

/* ── Status-Badge ────────────────────────────────────────── */
.bk-re-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: .75rem;
  font-weight: 600;
  white-space: nowrap;
}
</style>
