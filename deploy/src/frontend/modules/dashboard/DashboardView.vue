<script setup lang="ts">
// ============================================================
// DashboardView.vue – Dashboard (P4.3 Island)
// ============================================================
// Ersetzt renderDashboard() + renderDashboardItem() aus script.js.
// Mount-Punkt: #dashboardView
// Actions delegiert an script.js-Globals (Escape-Hatches).
// ============================================================

import { ref, computed, watch } from 'vue';
import { apiPost }     from '@core/api.ts';
import { useCanDo }    from '@core/auth.ts';
import { formatDate }  from '@core/utils.ts';
import type {
    DashboardItem, LoadDashboardResponse,
    DashboardItemTyp, DashboardItemStatus,
} from './types.ts';
import { TYP_ICON, PRIO_STYLE } from './types.ts';

// ── Props (reaktive Signale von index.ts) ─────────────────────
const props = defineProps<{
    refreshKey: ReturnType<typeof ref<number>>;
}>();

// ── State ─────────────────────────────────────────────────────
const items      = ref<DashboardItem[]>([]);
const canManage  = ref(false);
const loading    = ref(false);
const error      = ref<string | null>(null);

// ── Filter-State ──────────────────────────────────────────────
type TypeFilter  = 'alle' | DashboardItemTyp | 'offen' | 'erledigt';
type OwnerFilter = 'alle' | 'mine' | 'assigned';

const typeFilter  = ref<TypeFilter>('alle');
const ownerFilter = ref<OwnerFilter>('alle');
const searchQ     = ref('');

// ── Berechtigungen (reaktiv) ─────────────────────────────────
const canWrite = useCanDo('canWriteDashboard');

// ── Daten laden ───────────────────────────────────────────────
const w = window as unknown as Record<string, unknown>;

async function loadData() {
    loading.value = true;
    error.value   = null;
    try {
        const body = ownerFilter.value !== 'alle' ? { filterUser: ownerFilter.value } : {};
        const j = await apiPost<LoadDashboardResponse>('load_dashboard', body);
        if (!j.ok) { error.value = j.error ?? 'Fehler beim Laden.'; return; }
        items.value    = j.items ?? [];
        canManage.value= j.canManage ?? false;

        // Compat: globale dashboardData für script.js-Globals aktualisieren
        (w['dashboardData'] as unknown) = [...items.value];
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Laden fehlgeschlagen.';
    } finally {
        loading.value = false;
    }
}

// Kein immediate: Daten nur bei expliziter Navigation laden (refreshKey > 0).
// Die install()-Funktion ruft refreshKey++ beim ersten showDashboard() auf.
watch(() => props.refreshKey.value, (val) => { if ((val ?? 0) > 0) loadData(); });
watch(ownerFilter, () => { if ((props.refreshKey.value ?? 0) > 0) loadData(); });

// ── Gefilterte Items ──────────────────────────────────────────
const curUser = computed(() => (w['currentUser'] as string | null) ?? '');

const filteredItems = computed(() => {
    const today = new Date().toISOString().split('T')[0];
    let list = items.value.slice();

    // Owner-Filter (client-seitig analog script.js)
    if (ownerFilter.value === 'mine') {
        list = list.filter(i => i.ersteller === curUser.value);
    } else if (ownerFilter.value === 'assigned') {
        list = list.filter(i =>
            i.zugewiesen_an === curUser.value ||
            i.zugewiesen_an === 'alle' ||
            (i.zugewiesen_an?.startsWith('gruppe:') ?? false),
        );
    }

    // Typ-Filter
    switch (typeFilter.value) {
        case 'aufgabe': case 'notiz': case 'erinnerung':
            list = list.filter(i => i.typ === typeFilter.value); break;
        case 'offen':
            list = list.filter(i => i.status !== 'erledigt'); break;
        case 'erledigt':
            list = list.filter(i => i.status === 'erledigt'); break;
    }

    // Textsuche (Titel, Beschreibung, Ersteller, Zugewiesen)
    const q = searchQ.value.trim().toLowerCase();
    if (q) {
        list = list.filter(i =>
            [i.titel, i.beschreibung, i.ersteller, i.zugewiesen_an]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(q),
        );
    }

    return list;
});

// ── Helpers ───────────────────────────────────────────────────
const today = new Date().toISOString().split('T')[0];

function isUeberfaellig(item: DashboardItem): boolean {
    return !!(item.faelligAm && item.faelligAm < today && item.status !== 'erledigt');
}

function resolveZuweisung(val: string): string {
    return ((w['resolveZuweisung'] as Function)?.(val) ?? val) as string;
}

function baustelleName(id: number): string {
    const appData = w['appData'] as { baustellen?: { id: number; name: string }[] } | undefined;
    return appData?.baustellen?.find(b => b.id === id)?.name ?? '';
}

// ── Aktionen ──────────────────────────────────────────────────
function toggleStatus(id: number, status: DashboardItemStatus) {
    (w['toggleDashboardStatus'] as Function)?.(id, status);
}
function moveItem(id: number, dir: 'up' | 'down') {
    (w['moveDashboardItem'] as Function)?.(id, dir);
}
function openForm(id?: number, typ?: string) {
    (w['openDashboardItemForm'] as Function)?.(id ?? null, typ);
}
function doDelete(id: number) {
    (w['deleteDashboardItem'] as Function)?.(id);
}
function goBaustelle(id: number) {
    (w['showBaustelleById'] as Function)?.(id);
}

// ── Filter-Definitionen ───────────────────────────────────────
const TYPE_FILTERS: { key: TypeFilter; label: string }[] = [
    { key: 'alle',       label: 'Alle'          },
    { key: 'aufgabe',    label: '📋 Aufgaben'   },
    { key: 'notiz',      label: '📝 Notizen'    },
    { key: 'erinnerung', label: '🔔 Erinnerungen'},
    { key: 'offen',      label: 'Offen'         },
    { key: 'erledigt',   label: 'Erledigt'      },
];
const OWNER_FILTERS: { key: OwnerFilter; label: string }[] = [
    { key: 'alle',     label: 'Alle Mitarbeiter' },
    { key: 'mine',     label: 'Meine Einträge'   },
    { key: 'assigned', label: 'Mir zugewiesen'   },
];
</script>

<template>
  <div class="bk-dashboard">

    <!-- ── Toolbar ───────────────────────────────────────────── -->
    <div class="bk-dash-toolbar">
      <div class="bk-dash-filter-bar">
        <button
          v-for="f in TYPE_FILTERS" :key="f.key"
          class="dashboard-filter-btn"
          :class="{ active: typeFilter === f.key }"
          @click="typeFilter = f.key"
        >{{ f.label }}</button>
      </div>
      <button v-if="canWrite" class="btn btn-primary btn-sm" @click="openForm()">
        + Neu
      </button>
    </div>

    <!-- Textsuche -->
    <div class="bk-dash-search-row">
      <input
        v-model="searchQ"
        type="search"
        class="form-control"
        placeholder="🔍 Suchen (Titel, Beschreibung, Ersteller…)"
        style="font-size:.85rem;padding:6px 10px;border-radius:8px"
      />
    </div>

    <!-- Owner-Filter (nur für Manager/Admin) -->
    <div v-if="canManage" class="bk-dash-filter-bar" style="margin-top:6px">
      <button
        v-for="f in OWNER_FILTERS" :key="f.key"
        class="dashboard-filter-btn"
        :class="{ active: ownerFilter === f.key }"
        @click="ownerFilter = f.key"
      >{{ f.label }}</button>
    </div>

    <!-- ── Laden / Fehler ───────────────────────────────────── -->
    <div v-if="loading" class="bk-dash-state">Laden …</div>
    <div v-else-if="error" class="bk-dash-state bk-dash-error">{{ error }}</div>

    <!-- ── Leer ─────────────────────────────────────────────── -->
    <div v-else-if="filteredItems.length === 0" class="bk-dash-state">
      Keine Einträge.
      <button v-if="canWrite" class="btn btn-primary btn-sm" style="margin-left:8px" @click="openForm()">
        Ersten Eintrag anlegen
      </button>
    </div>

    <!-- ── Items-Liste ───────────────────────────────────────── -->
    <div v-else class="bk-dash-items">
      <div
        v-for="item in filteredItems"
        :key="item.id"
        class="dashboard-item"
        :class="{ erledigt: item.status === 'erledigt' }"
      >
        <!-- Farb-Punkt -->
        <div
          class="dashboard-item-dot"
          :style="{ background: item.farbe ?? '#94a3b8' }"
        />

        <!-- Inhalt -->
        <div class="dashboard-item-content">
          <div class="dashboard-item-title" :title="item.titel">
            {{ TYP_ICON[item.typ] }} {{ item.titel }}
          </div>
          <div
            v-if="item.beschreibung"
            class="bk-dash-beschreibung"
          >{{ item.beschreibung }}</div>

          <!-- Meta-Badges -->
          <div class="dashboard-item-meta">
            <!-- Priorität (nur wenn nicht normal) -->
            <span
              v-if="item.prioritaet !== 'normal'"
              class="dashboard-item-badge"
              :style="{ background: PRIO_STYLE[item.prioritaet].bg, color: PRIO_STYLE[item.prioritaet].color }"
            >{{ item.prioritaet.charAt(0).toUpperCase() + item.prioritaet.slice(1) }}</span>

            <!-- Fälligkeitsdatum -->
            <span
              v-if="item.faelligAm"
              :style="isUeberfaellig(item) ? 'color:#B91C1C;font-weight:600' : ''"
            >{{ isUeberfaellig(item) ? '⚠️ ' : '📅 ' }}{{ formatDate(item.faelligAm) }}</span>

            <!-- Baustellen-Link -->
            <a
              v-if="item.linkTyp === 'baustelle' && item.linkId"
              href="#"
              style="color:var(--blue);text-decoration:none;font-size:.7rem"
              @click.prevent="goBaustelle(item.linkId!)"
            >→ {{ baustelleName(item.linkId) }}</a>

            <!-- Zugewiesen-Badge -->
            <span
              v-if="item.zugewiesen_an"
              class="dashboard-item-badge"
              :style="item.zugewiesen_an === 'alle'
                ? 'background:#fef3c7;color:#92400e'
                : item.zugewiesen_an.startsWith('gruppe:')
                  ? 'background:#ede9fe;color:#5b21b6'
                  : 'background:#e0f2fe;color:#0369a1'"
            >{{ resolveZuweisung(item.zugewiesen_an) }}</span>

            <!-- Ersteller -->
            <span style="font-size:.67rem;opacity:.6">von {{ item.ersteller }}</span>
          </div>
        </div>

        <!-- Aktionen -->
        <div v-if="canWrite" class="dashboard-item-actions">
          <button @click="moveItem(item.id, 'up')"   title="Nach oben"  style="font-size:.75rem">↑</button>
          <button @click="moveItem(item.id, 'down')" title="Nach unten" style="font-size:.75rem">↓</button>
          <button
            @click="toggleStatus(item.id, item.status)"
            :title="item.status === 'erledigt' ? 'Wieder öffnen' : 'Erledigen'"
          >{{ item.status === 'erledigt' ? '↩️' : '✅' }}</button>
          <button @click="openForm(item.id)" title="Bearbeiten">✏️</button>
          <button @click="doDelete(item.id)" title="Löschen" style="color:var(--danger)">🗑</button>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
.bk-dashboard { padding: 4px 0 16px; }

.bk-dash-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}
.bk-dash-filter-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.bk-dash-state {
  padding: 24px;
  color: var(--text-muted, #888);
  font-style: italic;
}
.bk-dash-error { color: #c62828; font-style: normal; }

.bk-dash-beschreibung {
  font-size: .78rem;
  color: var(--text-muted, #555);
  margin-top: 2px;
  white-space: pre-wrap;
}

.bk-dash-items {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
</style>
