<script setup lang="ts">
// ============================================================
// TermineView.vue – Terminplanung (P5.2)
// ============================================================
// Vollständige Vue-Komponente als Teleport-Modal.
// mount() wird beim Seitenstart einmalig in #bk-termine-mount
// aufgerufen; isOpen steuert Sichtbarkeit.
// ============================================================

import { ref, computed, watch, nextTick } from 'vue';
import { apiGet }       from '@core/api.ts';
import { useCanDo }    from '@core/auth.ts';
import { formatDate }   from '@core/utils.ts';
import type { Termin, LoadTermineResponse, ZeitraumFilter } from './types.ts';

// ── Reaktiver State ───────────────────────────────────────────
const isOpen      = ref(false);
const loading     = ref(false);
const error       = ref<string | null>(null);
const termine     = ref<Termin[]>([]);
const refreshKey  = ref(0);

// ── Filter-State ──────────────────────────────────────────────
const filterText       = ref('');
const filterBaustelle  = ref<number | ''>('');
const filterPerson     = ref('');
const filterZeitraum   = ref<ZeitraumFilter>('upcoming');

// ── Globale Escape-Hatches ────────────────────────────────────
const w = window as unknown as Record<string, unknown>;

// ── Berechtigungen (reaktiv) ─────────────────────────────────
const canManage = useCanDo('canManageTermine');

// ── Baustellen-Liste (für Filter-Select) ─────────────────────
const baustellen = computed(() => {
    const appData = w['appData'] as { baustellen?: { id: number; name: string }[] } | undefined;
    return appData?.baustellen ?? [];
});

// ── Daten laden ───────────────────────────────────────────────
async function loadData() {
    loading.value = true;
    error.value   = null;
    try {
        const j = await apiGet<LoadTermineResponse>('load_termine');
        termine.value = j.ok ? (j.termine ?? []) : [];
    } catch {
        error.value = 'Fehler beim Laden der Termine.';
    } finally {
        loading.value = false;
    }
}

watch(refreshKey, () => { if (isOpen.value) loadData(); });

// ── Modal öffnen / schließen ──────────────────────────────────
async function open() {
    isOpen.value = true;
    filterZeitraum.value = 'all';  // 'upcoming' würde vergangene Termine ausblenden
    filterText.value = '';
    filterBaustelle.value = '';
    filterPerson.value = '';
    await nextTick();
    await loadData();
}

function close() {
    isOpen.value = false;
}

// Globale API (wird von index.ts genutzt)
// WICHTIG: refreshKey als Ref NICHT direkt exponieren (auto-unwrappt zu 0).
// Stattdessen triggerRefresh-Funktion exponieren.
defineExpose({ open, close, triggerRefresh: () => { refreshKey.value++; } });

// ── Personen-Liste für Filter ─────────────────────────────────
const allPersons = computed(() => {
    const set = new Set<string>();
    for (const t of termine.value) {
        if (t.ersteller) set.add(t.ersteller);
        for (const u of t.zugewiesen ?? []) set.add(u);
    }
    return [...set].sort();
});

// ── Filter anwenden ───────────────────────────────────────────
const today = new Date().toISOString().split('T')[0];

const filtered = computed<Termin[]>(() => {
    let list = termine.value.slice();
    const q   = filterText.value.toLowerCase().trim();
    const bs  = filterBaustelle.value;
    const per = filterPerson.value;
    const zr  = filterZeitraum.value;

    // Zeitraum
    if (zr === 'upcoming') list = list.filter(t => (t.datum ?? '') >= today);
    else if (zr === 'past') list = list.filter(t => (t.datum ?? '') < today);
    else if (zr === 'today') list = list.filter(t => t.datum === today);
    else if (zr === 'week') {
        const dt = new Date(); const mon = new Date(dt);
        mon.setDate(dt.getDate() - ((dt.getDay() + 6) % 7));
        const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
        const monKey = mon.toISOString().split('T')[0];
        const sunKey = sun.toISOString().split('T')[0];
        list = list.filter(t => (t.datum ?? '') >= monKey && (t.datum ?? '') <= sunKey);
    } else if (zr === 'month') {
        const m = new Date().toISOString().slice(0, 7);
        list = list.filter(t => (t.datum ?? '').startsWith(m));
    }

    // Baustelle
    if (bs) list = list.filter(t => t.baustelleId === bs);
    // Person
    if (per) list = list.filter(t =>
        t.ersteller === per || (t.zugewiesen ?? []).includes(per),
    );
    // Volltext
    if (q) list = list.filter(t =>
        [t.titel, t.beschreibung, t.ort, t.ersteller, ...(t.zugewiesen ?? [])]
            .join(' ').toLowerCase().includes(q),
    );

    return list;
});

const upcoming = computed(() =>
    filtered.value.filter(t => (t.datum ?? '') >= today)
        .sort((a, b) => a.datum!.localeCompare(b.datum!) || (a.zeitVon ?? '').localeCompare(b.zeitVon ?? '')),
);
const past = computed(() =>
    filtered.value.filter(t => (t.datum ?? '') < today)
        .sort((a, b) => b.datum!.localeCompare(a.datum!)),
);

// ── Helpers ───────────────────────────────────────────────────
function zeitText(t: Termin): string {
    if (t.ganztags) return 'Ganztags';
    if (t.zeitVon) return t.zeitVon + (t.zeitBis ? ' – ' + t.zeitBis : '');
    return '';
}

function baustelleName(id?: number | null): string {
    if (!id) return '';
    return baustellen.value.find(b => b.id === id)?.name ?? '';
}

// ── Aktionen ──────────────────────────────────────────────────
function editTermin(id: number) {
    (w['openTerminFormModal'] as ((id: number) => void) | undefined)?.(id);
}
function doDelete(id: number) {
    (w['deleteTermin'] as ((id: number) => void) | undefined)?.(id);
}
function newTermin() {
    (w['openTerminFormModal'] as (() => void) | undefined)?.();
}
function calShare() {
    (w['openWpCalendarShare'] as (() => void) | undefined)?.();
}

function resetFilter() {
    filterText.value = '';
    filterBaustelle.value = '';
    filterPerson.value = '';
    filterZeitraum.value = 'upcoming';
}
const hasFilter = computed(() =>
    !!(filterText.value || filterBaustelle.value || filterPerson.value || filterZeitraum.value !== 'upcoming'),
);
</script>

<template>
  <Teleport to="body">
    <div
      v-if="isOpen"
      id="termineOverlay"
      style="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9000;display:flex;align-items:center;justify-content:center;overflow:auto;padding:20px;"
      @click.self="close"
    >
      <div style="background:var(--surface,#fff);color:var(--text,#1a1a2e);border-radius:12px;max-width:960px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 12px 40px rgba(0,0,0,.3);">

        <!-- ── Modal-Header ─────────────────────────────────── -->
        <div style="padding:16px 24px;border-bottom:1px solid var(--grey-200,#e0e0e0);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(135deg,#00B4D8,#0096B7);color:#fff;border-radius:12px 12px 0 0;">
          <h2 style="margin:0;font-size:1.15rem;">📅 Terminplanung</h2>
          <div style="display:flex;gap:8px;align-items:center;">
            <button
              v-if="canManage"
              class="btn btn-sm"
              style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:6px;padding:5px 12px;font-size:.8rem;cursor:pointer"
              @click="newTermin"
            >+ Neuer Termin</button>
            <button
              title="Kalender abonnieren"
              style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:6px;padding:5px 10px;font-size:.85rem;cursor:pointer;"
              @click="calShare"
            >📅</button>
            <button
              @click="close"
              style="background:none;border:none;color:#fff;font-size:1.5rem;cursor:pointer;line-height:1;"
            >&times;</button>
          </div>
        </div>

        <!-- ── Modal-Inhalt ─────────────────────────────────── -->
        <div style="padding:20px 24px;">

          <!-- Filter-Leiste -->
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center;">
            <input
              v-model="filterText"
              type="text"
              class="form-control"
              placeholder="🔍 Suche…"
              style="flex:1;min-width:140px;max-width:240px;font-size:.82rem;padding:6px 10px"
            />
            <select
              v-model="filterBaustelle"
              class="form-control"
              style="font-size:.82rem;padding:6px 8px;min-width:160px;max-width:240px"
            >
              <option value="">Alle Baustellen</option>
              <option v-for="b in baustellen" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
            <select
              v-model="filterPerson"
              class="form-control"
              style="font-size:.82rem;padding:6px 8px;max-width:160px"
            >
              <option value="">Alle Personen</option>
              <option v-for="p in allPersons" :key="p" :value="p">{{ p }}</option>
            </select>
            <select
              v-model="filterZeitraum"
              class="form-control"
              style="font-size:.82rem;padding:6px 8px;max-width:160px"
            >
              <option value="upcoming">Kommende</option>
              <option value="past">Vergangene</option>
              <option value="all">Alle</option>
              <option value="today">Heute</option>
              <option value="week">Diese Woche</option>
              <option value="month">Dieser Monat</option>
            </select>
            <button
              v-if="hasFilter"
              class="btn btn-sm btn-secondary"
              style="font-size:.78rem;padding:4px 10px"
              @click="resetFilter"
            >✕ Reset</button>
          </div>

          <!-- Laden / Fehler -->
          <div v-if="loading" style="text-align:center;padding:40px;color:var(--grey-400,#888)">
            Termine werden geladen …
          </div>
          <div v-else-if="error" style="color:#c62828;padding:20px">{{ error }}</div>

          <!-- Leer -->
          <div v-else-if="termine.length === 0" style="text-align:center;padding:40px;color:var(--grey-400,#888)">
            <p style="font-size:1.1rem;margin-bottom:8px">Keine Termine vorhanden</p>
            <p style="font-size:.85rem">Erstellen Sie einen neuen Termin über den Button oben.</p>
          </div>
          <div v-else-if="filtered.length === 0" style="text-align:center;padding:30px;color:var(--grey-400,#888)">
            <p style="font-size:.9rem">Keine Termine für diesen Filter gefunden.</p>
          </div>

          <!-- Termin-Karten (Alle / upcoming + past) -->
          <template v-else>
            <!-- Alle: aufgeteilt in Kommende + Vergangene -->
            <template v-if="filterZeitraum === 'all'">
              <template v-if="upcoming.length > 0">
                <h3 style="font-size:.85rem;color:var(--primary,#00B4D8);margin-bottom:10px;font-weight:700">
                  Kommende Termine
                </h3>
                <TerminCard
                  v-for="t in upcoming" :key="t.id"
                  :termin="t" :can-manage="canManage"
                  :baustelle-name="baustelleName(t.baustelleId)"
                  :zeit-text="zeitText(t)"
                  @edit="editTermin" @delete="doDelete"
                />
              </template>
              <template v-if="past.length > 0">
                <h3 style="font-size:.85rem;color:var(--grey-400,#888);margin:20px 0 10px;font-weight:700">
                  Vergangene Termine
                </h3>
                <TerminCard
                  v-for="t in past" :key="t.id"
                  :termin="t" :can-manage="canManage"
                  :baustelle-name="baustelleName(t.baustelleId)"
                  :zeit-text="zeitText(t)"
                  @edit="editTermin" @delete="doDelete"
                />
              </template>
            </template>

            <!-- Einzelliste (alle anderen Filter) -->
            <template v-else>
              <div style="font-size:.78rem;color:var(--grey-400,#888);margin-bottom:8px;font-weight:600">
                {{ filtered.length }} Termin{{ filtered.length !== 1 ? 'e' : '' }}
              </div>
              <TerminCard
                v-for="t in filtered" :key="t.id"
                :termin="t" :can-manage="canManage"
                :baustelle-name="baustelleName(t.baustelleId)"
                :zeit-text="zeitText(t)"
                @edit="editTermin" @delete="doDelete"
              />
            </template>
          </template>

        </div>
      </div>
    </div>
  </Teleport>
</template>

<!-- ── Termin-Karte als Sub-Komponente ─────────────────────── -->
<script lang="ts">
import { defineComponent } from 'vue';
// formatDate und Termin sind bereits in <script setup> importiert.
// Hier per type-Import (kein Duplicate-Identifier) referenzieren.
import type { Termin as TerminType } from './types.ts';
import { formatDate as _fd } from '@core/utils.ts';

const TerminCard = defineComponent({
    name: 'TerminCard',
    props: {
        termin:        { type: Object as () => TerminType, required: true },
        canManage:     { type: Boolean, default: false },
        baustelleName: { type: String, default: '' },
        zeitText:      { type: String, default: '' },
    },
    emits: ['edit', 'delete'],
    setup(props, { emit }) {
        const today  = new Date().toISOString().split('T')[0];
        const isPast = (props.termin.datum ?? '') < today;
        return { isPast, formatDate: _fd, emit };
    },
    template: `
<div
  :style="{
    display: 'flex', gap: '12px', padding: '12px 16px',
    border: '1px solid var(--grey-200,#e0e0e0)',
    borderLeft: '4px solid ' + (termin.farbe || '#00B4D8'),
    borderRadius: '10px', marginBottom: '8px',
    opacity: isPast ? '0.6' : '1',
    background: 'var(--surface,#fff)',
  }"
>
  <div style="flex:1;min-width:0">
    <div style="font-weight:700;font-size:.92rem">{{ termin.titel }}</div>
    <div style="font-size:.8rem;color:var(--grey-400,#888);margin-top:2px">
      📅 {{ formatDate(termin.datum) }}
      <template v-if="zeitText">&nbsp;·&nbsp; ⏱ {{ zeitText }}</template>
      <template v-if="termin.ort">&nbsp;·&nbsp; 📍 {{ termin.ort }}</template>
      <template v-if="baustelleName">&nbsp;·&nbsp; 🏗 {{ baustelleName }}</template>
    </div>
    <div v-if="termin.beschreibung" style="font-size:.82rem;margin-top:4px">
      {{ termin.beschreibung }}
    </div>
    <div v-if="termin.zugewiesen && termin.zugewiesen.length" style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap">
      <span
        v-for="u in termin.zugewiesen" :key="u"
        style="font-size:.7rem;background:var(--blue-light,#E0F7FA);color:var(--blue,#00B4D8);padding:1px 6px;border-radius:4px;font-weight:600"
      >{{ u }}</span>
    </div>
    <div style="font-size:.68rem;color:var(--grey-400,#aaa);margin-top:4px">
      Erstellt von {{ termin.ersteller || '?' }}
    </div>
  </div>
  <div v-if="canManage" style="display:flex;gap:4px;flex-shrink:0;align-self:flex-start">
    <button class="btn btn-sm btn-secondary" style="font-size:.75rem;padding:4px 8px" @click="emit('edit', termin.id)">✏️</button>
    <button class="btn btn-sm" style="font-size:.75rem;padding:4px 8px;background:#FF3B30;color:#fff;border:none;border-radius:6px;cursor:pointer" @click="emit('delete', termin.id)">🗑</button>
  </div>
</div>`,
});

export { TerminCard };
</script>
