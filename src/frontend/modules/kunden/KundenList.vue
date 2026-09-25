<script setup lang="ts">
// ============================================================
// KundenList.vue – Kundenstamm-Liste (P4.2 Island)
// ============================================================
// Mount-Punkt: #kundenListContainer (in #kundenstammView)
// Suche: wird von renderKundenListe()-Override gesetzt (bridge
//         zum statischen #kundenSearch-Input in index.html)
// Actions: delegiert an script.js-Globals (openKundeForm, deleteKunde)
// ============================================================

import { ref, computed, watch } from 'vue';
import { apiGet }   from '@core/api.ts';
import { useCanDo } from '@core/auth.ts';
import type { Kunde, LoadKundenResponse } from './types.ts';

// ── Props (reaktive Refs von index.ts) ───────────────────────
const props = defineProps<{
    /** Suchbegriff aus #kundenSearch-Input (bridged via renderKundenListe-Override) */
    searchQ:    ReturnType<typeof ref<string>>;
    /** Inkrement bei jedem loadKundenData()-Aufruf → Refresh */
    refreshKey: ReturnType<typeof ref<number>>;
}>();

// ── State ─────────────────────────────────────────────────────
const kunden   = ref<Kunde[]>([]);
const loading  = ref(false);
const error    = ref<string | null>(null);

// ── Berechtigungen (reaktiv) ─────────────────────────────────
const canWrite = useCanDo('canWriteKunden');

// ── Daten laden ───────────────────────────────────────────────
async function loadData() {
    loading.value = true;
    error.value   = null;
    try {
        const j = await apiGet<LoadKundenResponse>('load_kunden');
        if (!j.ok) { error.value = j.error ?? 'Fehler beim Laden.'; return; }
        kunden.value = j.kunden ?? [];

        // Compat: globale kundenData für script.js-Funktionen (openKundeForm etc.)
        const w = window as unknown as Record<string, unknown>;
        w['kundenData'] = [...kunden.value];
    } catch (e) {
        error.value = 'Netzwerkfehler.';
    } finally {
        loading.value = false;
    }
}

// Kein immediate: Daten nur bei expliziter Navigation laden.
watch(() => props.refreshKey.value, (val) => { if ((val ?? 0) > 0) loadData(); });

// ── Gefilterte + sortierte Liste ──────────────────────────────
const filteredKunden = computed(() => {
    const q = (props.searchQ.value ?? '').toLowerCase().trim();
    const list = q
        ? kunden.value.filter(k =>
            [k.kundennummer, k.firma, k.vorname, k.nachname, k.ort, k.email, k.telefon, k.mobil]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(q),
          )
        : [...kunden.value];
    return list.sort((a, b) =>
        (a.firma || a.nachname || '').localeCompare(b.firma || b.nachname || ''),
    );
});

// ── Display-Helpers ───────────────────────────────────────────
function fullName(k: Kunde): string {
    return [k.anrede, k.vorname, k.nachname].filter(Boolean).join(' ') || '–';
}
function tel(k: Kunde): string { return k.telefon || k.mobil || ''; }

// ── Aktions-Helpers ───────────────────────────────────────────
const w = window as unknown as Record<string, unknown>;
function openForm(id?: number) { (w['openKundeForm'] as Function)?.(id); }
function doDelete(id: number)  { (w['deleteKunde']   as Function)?.(id); }
</script>

<template>
  <div class="bk-kunden-list">

    <!-- ── Laden / Fehler ───────────────────────────────────── -->
    <div v-if="loading" class="bk-kl-state">Laden …</div>
    <div v-else-if="error" class="bk-kl-state bk-kl-error">{{ error }}</div>

    <!-- ── Leer ─────────────────────────────────────────────── -->
    <div
      v-else-if="filteredKunden.length === 0"
      class="bk-kl-state"
    >
      {{ (searchQ.value ?? '') ? 'Keine Kunden gefunden.' : 'Noch keine Kunden angelegt.' }}
    </div>

    <!-- ── Tabelle ───────────────────────────────────────────── -->
    <div v-else class="bk-kl-table-wrap">
      <table class="kunden-table">
        <thead>
          <tr>
            <th>Kd.-Nr.</th>
            <th>Firma</th>
            <th>Name</th>
            <th>Ort</th>
            <th>Telefon</th>
            <th>E-Mail</th>
            <th v-if="canWrite" style="width:80px;"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="k in filteredKunden" :key="k.id">
            <td>{{ k.kundennummer || '–' }}</td>
            <td>{{ k.firma || '–' }}</td>
            <td>{{ fullName(k) }}</td>
            <td>{{ (k.plz ? k.plz + ' ' : '') + (k.ort || '–') }}</td>
            <td>
              <a v-if="tel(k)" :href="`tel:${tel(k)}`">{{ tel(k) }}</a>
              <span v-else>–</span>
            </td>
            <td>
              <a v-if="k.email" :href="`mailto:${k.email}`">{{ k.email }}</a>
              <span v-else>–</span>
            </td>
            <td v-if="canWrite" class="kunde-actions">
              <button @click="openForm(k.id)" title="Bearbeiten" v-html="'✏'" />
              <button @click="doDelete(k.id)" title="Löschen"    v-html="'🗑'" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

  </div>
</template>

<style scoped>
.bk-kunden-list { margin-top: 4px; }

.bk-kl-state {
  padding: 20px;
  color: var(--text-muted, #888);
  font-style: italic;
}
.bk-kl-error { color: #c62828; font-style: normal; }

.bk-kl-table-wrap { overflow-x: auto; }
</style>
