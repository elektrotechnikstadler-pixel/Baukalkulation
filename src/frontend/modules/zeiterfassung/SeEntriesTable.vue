<script setup lang="ts">
// ============================================================
// SeEntriesTable.vue – Zeiterfassungs-Einträge (P5 Island)
// ============================================================
// Ersetzt die statische <table id="seDesktopTable"> in index.html.
// Daten kommen via reaktive Refs aus renderSeDesktop()-Override.
// Delete-Aktion → Escape-Hatch zu window.delSeDesktop().
// ============================================================

import { ref, computed } from 'vue';
import { formatDate }    from '@core/utils.ts';
import type { ZeitEntry } from './types.ts';
import { TYP_EMOJI, TYP_LABEL } from './types.ts';

// ── Props (reaktive Refs von index.ts) ───────────────────────
const props = defineProps<{
    entries:     ReturnType<typeof ref<ZeitEntry[]>>;
    searchQ:     ReturnType<typeof ref<string>>;
    isErweitert: ReturnType<typeof ref<boolean>>;
}>();

// ── Globale Helfer ────────────────────────────────────────────
const w = window as unknown as Record<string, unknown>;

// ── Bearbeitbarkeits-Check ────────────────────────────────────
function canEditEntry(e: ZeitEntry): boolean {
    if (e._virtual) return false;
    if (!e.datum) return false;
    const entryDate = new Date(e.datum + 'T00:00:00');
    const fiveDaysAgo = new Date();
    fiveDaysAgo.setHours(0, 0, 0, 0);
    fiveDaysAgo.setDate(fiveDaysAgo.getDate() - 5);
    return entryDate >= fiveDaysAgo;
}

// ── Typ-Label ─────────────────────────────────────────────────
function typLabel(e: ZeitEntry): string {
    const typ = e.typ ?? 'arbeit';
    if (typ === 'feiertag') return `🎉 ${e.bemerkung || 'Feiertag'}`;
    if (typ === 'sonstig')  return `⚠️ ${e.bemerkung || 'Sonstiger Fehlgrund'}`;
    // Sonstige Stunden-Typen via globale _seTypLabel falls vorhanden
    const customLabel = (w['_seTypLabel'] as ((t: string) => string) | undefined)?.(typ);
    return `${TYP_EMOJI[typ] ?? '⏱'} ${customLabel ?? TYP_LABEL[typ] ?? typ}`;
}

// ── Baustellenname auflösen ───────────────────────────────────
function baustelleText(e: ZeitEntry): string {
    if (e.baustelleName) return e.baustelleName;
    if (!e.baustelleId) return '';
    const appData = w['appData'] as { baustellen?: { id: number; name: string }[] } | undefined;
    return appData?.baustellen?.find(b => b.id === e.baustelleId)?.name ?? '';
}

// ── Gefilterte + sortierte Einträge ───────────────────────────
const sortedEntries = computed<ZeitEntry[]>(() => {
    const q = (props.searchQ.value ?? '').toLowerCase().trim();
    const list = (props.entries.value ?? []).slice();

    // Aktueller Monat zuerst, dann absteigend nach Datum
    const currentMonth = new Date().toISOString().slice(0, 7);
    list.sort((a, b) => {
        const da = a.datum ?? '', db = b.datum ?? '';
        const aThis = da.startsWith(currentMonth);
        const bThis = db.startsWith(currentMonth);
        if (aThis && !bThis) return -1;
        if (!aThis && bThis) return 1;
        return db.localeCompare(da) || ((b.id ?? 0) - (a.id ?? 0));
    });

    if (!q) return list;
    return list.filter(e =>
        [e.datum, e.typ, e.baustelleName ?? baustelleText(e), e.bemerkung, String(e.stunden ?? '')]
            .join(' ')
            .toLowerCase()
            .includes(q),
    );
});

const colCount = computed(() => props.isErweitert.value ? 9 : 6);

// ── Aktion ────────────────────────────────────────────────────
function delEntry(id: number) {
    (w['delSeDesktop'] as ((id: number) => void) | undefined)?.(id);
}
</script>

<template>
  <table class="data-table" id="seDesktopTable">

    <!-- ── Kopfzeile (reagiert auf erweiterte_zeiterfassung) ── -->
    <thead id="seDesktopThead">
      <tr v-if="isErweitert.value">
        <th style="width:100px">Datum</th>
        <th style="width:90px">Typ</th>
        <th style="width:55px">Von</th>
        <th style="width:55px">Bis</th>
        <th style="width:50px">Pause</th>
        <th style="width:60px">Stunden</th>
        <th>Baustelle</th>
        <th>Bemerkung</th>
        <th style="width:42px"></th>
      </tr>
      <tr v-else>
        <th style="width:120px">Datum</th>
        <th style="width:100px">Typ</th>
        <th style="width:80px">Stunden</th>
        <th>Baustelle</th>
        <th>Bemerkung</th>
        <th style="width:42px"></th>
      </tr>
    </thead>

    <!-- ── Eintrags-Zeilen ─────────────────────────────────── -->
    <tbody id="seDesktopBody">
      <!-- Leer-Zustand -->
      <tr v-if="sortedEntries.length === 0">
        <td :colspan="colCount" class="hint-text" style="text-align:center;padding:20px">
          Keine Einträge vorhanden.
        </td>
      </tr>

      <!-- Eintrags-Zeilen -->
      <tr
        v-for="e in sortedEntries"
        :key="e.id"
        :style="{
          opacity:    !canEditEntry(e) ? (e._virtual ? '0.85' : '0.6') : undefined,
          background: e._virtual ? '#FFF9C4' : undefined,
        }"
      >
        <!-- Erweiterter Modus -->
        <template v-if="isErweitert.value">
          <td>{{ formatDate(e.datum) }}</td>
          <td v-html="typLabel(e)" />
          <td>{{ e._virtual ? '—' : (e.von ?? '—') }}</td>
          <td>{{ e._virtual ? '—' : (e.bis ?? '—') }}</td>
          <td>{{ e._virtual ? '—' : (e.pause ? e.pause + ' min' : '—') }}</td>
          <td class="text-right">{{ (e.stunden ?? 0).toFixed(2) }} h</td>
          <td>{{ e._virtual ? '' : baustelleText(e) }}</td>
          <td>
            <em v-if="e._virtual" style="font-size:.78rem;color:#888">auto</em>
            <template v-else>{{ e.bemerkung ?? '' }}</template>
          </td>
          <td>
            <button
              v-if="canEditEntry(e)"
              class="icon-btn danger"
              title="Löschen"
              @click="delEntry(e.id)"
            >🗑</button>
          </td>
        </template>

        <!-- Normal-Modus -->
        <template v-else>
          <td>{{ formatDate(e.datum) }}</td>
          <td v-html="typLabel(e)" />
          <td class="text-right">{{ (e.stunden ?? 0).toFixed(2) }} h</td>
          <td>{{ e._virtual ? '' : baustelleText(e) }}</td>
          <td>
            <em v-if="e._virtual" style="font-size:.78rem;color:#888">auto</em>
            <template v-else>{{ e.bemerkung ?? '' }}</template>
          </td>
          <td>
            <button
              v-if="canEditEntry(e)"
              class="icon-btn danger"
              title="Löschen"
              @click="delEntry(e.id)"
            >🗑</button>
          </td>
        </template>
      </tr>
    </tbody>
  </table>
</template>
