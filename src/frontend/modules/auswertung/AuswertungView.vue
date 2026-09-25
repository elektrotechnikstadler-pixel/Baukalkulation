<script setup lang="ts">
// ============================================================
// AuswertungView.vue – Kaufmännische & Mitarbeiter-Auswertung (P4.4)
// ============================================================
// Navigation + Datenladen in Vue. Rendering:
//   Tab 1 (Kaufmännisch): KPI-Karten + Projekttabelle nativ in Vue.
//                          Charts via Escape-Hatch (window.drawProjektCharts).
//   Tab 2 (Mitarbeiter):  v-html aus window.renderAuswertungMitarbeiter()
//                          + window.drawMitarbeiterCharts nach DOM-Update.
// ============================================================

import { ref, computed, watch, nextTick } from 'vue';
import { apiGet }       from '@core/api.ts';
import { useCanDo }    from '@core/auth.ts';
import { fmt }          from '@core/utils.ts';
import type { AuswertungProjekt, AuswertungUser, AuswertungArchive, AuswertungData } from './types.ts';

// ── Props ─────────────────────────────────────────────────────
const props = defineProps<{
    refreshKey: ReturnType<typeof ref<number>>;
}>();

// ── State ─────────────────────────────────────────────────────
const loading  = ref(false);
const error    = ref<string | null>(null);
const activeTab= ref<'kaufmaennisch' | 'mitarbeiter'>('kaufmaennisch');

const archives = ref<AuswertungArchive[]>([]);
const users    = ref<AuswertungUser[]>([]);
const gleitzeit= ref<Record<string, unknown[]>>({});

// Mitarbeiter-Tab HTML (aus script.js-Escape-Hatch)
const mitarbeiterHtml = ref('');

// ── Globale Helfer ────────────────────────────────────────────
const w = window as unknown as Record<string, unknown>;

// ── Berechtigungen (reaktiv) ─────────────────────────────────
const canExport = useCanDo('canSeePrices');

// ── Datenladen ────────────────────────────────────────────────
async function loadData() {
    loading.value = true;
    error.value   = null;
    try {
        const [archJ, zeitJ, sollJ] = await Promise.all([
            apiGet<{ ok: boolean; archives?: AuswertungArchive[] }>('load_auswertung_archives'),
            apiGet<{ ok: boolean; data?: Record<string, unknown> }>('load_all_zeiterfassung'),
            apiGet<{ ok: boolean; users?: AuswertungUser[] }>('get_sollstunden_extended'),
        ]);

        archives.value = archJ.ok ? (archJ.archives ?? []) : [];
        users.value    = sollJ.ok ? (sollJ.users    ?? []) : [];

        // Gleitzeitkonto pro User (nur relevante User)
        const gzUsers = users.value.filter(u =>
            u.role !== 'admin' && u.showInZeitverwaltung !== false,
        );
        const gResults = await Promise.all(
            gzUsers.map(u =>
                apiGet<{ ok: boolean; buchungen?: unknown[] }>(
                    'get_gleitzeitkonto_buchungen', { username: u.username },
                )
                    .then(j => ({ username: u.username, buchungen: j.ok ? (j.buchungen ?? []) : [] }))
                    .catch(() => ({ username: u.username, buchungen: [] })),
            ),
        );
        const glz: Record<string, unknown[]> = {};
        for (const g of gResults) glz[g.username] = g.buchungen;
        gleitzeit.value = glz;

        // Globale awData-Compat: das von script.js angelegte Objekt MUTIEREN
        // (nicht ersetzen), damit renderAuswertungMitarbeiter() die let-Variable
        // awData liest, die auf dasselbe Objekt zeigt wie window.awData.
        const sharedAw = w['awData'] as Record<string, unknown> | undefined;
        const awPayload = {
            archives: archives.value,
            zeit:     zeitJ.ok ? (zeitJ.data ?? {}) : {},
            users:    users.value,
            gleitzeit: gleitzeit.value,
        };
        if (sharedAw) {
            // In-place-Update: let awData in script.js zeigt auf dieselbe Referenz
            Object.assign(sharedAw, awPayload);
        } else {
            w['awData'] = awPayload;
        }

    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Laden der Auswertungsdaten fehlgeschlagen.';
    } finally {
        loading.value = false;
    }
}

// Kein immediate: Daten nur bei expliziter Navigation laden.
watch(() => props.refreshKey.value, (val) => { if ((val ?? 0) > 0) loadData(); });

// ── Projektdaten berechnen ─────────────────────────────────────
function archivSichtbar(a: AuswertungArchive): boolean {
    return (w['awArchivSichtbar'] as ((a: AuswertungArchive) => boolean) | undefined)?.(a)
        ?? (a.includeInAuswertung !== false);
}

const allProjekte = computed<AuswertungProjekt[]>(() => {
    const appData = w['appData'] as { baustellen?: unknown[]; pauschalen?: unknown[] } | undefined;
    const baustellen = (appData?.baustellen ?? []) as Record<string, unknown>[];

    const active: AuswertungProjekt[] = baustellen
        .filter(b => b['includeInAuswertung'] !== false)
        .map(b => {
            let ms = 0, mek = 0, as2 = 0, afk = 0, ps = 0, abs = 0;
            const mat  = (b['material']   as Record<string, unknown>[] | undefined) ?? [];
            const az   = (b['arbeitszeit']as Record<string, unknown>[] | undefined) ?? [];
            const pauschalenB = (b['pauschalen']as Record<string, unknown>[] | undefined) ?? [];
            const abschl = (b['abschlaege']as Record<string, unknown>[] | undefined) ?? [];
            const pauschalenGlobal = (appData?.pauschalen ?? []) as Record<string, unknown>[];

            const matVkFn = w['matVk'] as ((r: unknown, b: unknown) => number) | undefined;
            mat.forEach(r => {
                ms  += (matVkFn?.(r, b) ?? 0) * ((r['anzahl'] as number) || 0);
                mek += ((r['ek'] as number) || 0) * ((r['anzahl'] as number) || 0);
            });
            az.forEach(r => {
                as2 += ((r['stunden'] as number) || 0) * ((r['stundenpreis'] as number) || 0);
                afk += ((r['stunden'] as number) || 0) * ((r['fixkosten'] as number) || 0);
            });
            pauschalenB.forEach(entry => {
                const p = pauschalenGlobal.find(pg => pg['id'] === entry['id']);
                if (p) ps += ((p['preis'] as number) || 0) * ((entry['anzahl'] as number) || 1);
            });
            abschl.forEach(r => { abs += (r['betrag'] as number) || 0; });

            const g = ms + as2 + ps;
            return {
                name: (b['name'] as string) || '',
                projektNr: (b['projektNr'] as string) || '',
                matVk: ms, matEk: mek, azEin: as2, azFk: afk,
                pSum: ps, abSum: abs,
                gesamt: g,
                gewinn: (ms - mek) + (as2 - afk) + ps,
                offen: g - abs,
                source: 'aktiv' as const,
            };
        });

    const archived: AuswertungProjekt[] = archives.value
        .filter(archivSichtbar)
        .map(a => ({
            name: a.name, projektNr: a.projektNr ?? '',
            matVk: a.matVk ?? 0, matEk: a.matEk ?? 0,
            azEin: a.azEin ?? 0, azFk: a.azFk ?? 0,
            pSum: a.pSum ?? 0, abSum: a.abSum ?? 0,
            gesamt: a.gesamt ?? 0, gewinn: a.gewinn ?? 0,
            offen: a.offen ?? 0,
            source: 'archiv' as const,
        }));

    return [...active, ...archived];
});

// Summenwerte
const totals = computed(() => {
    let tMat = 0, tAz = 0, tP = 0, tAb = 0, tGes = 0, tGw = 0;
    for (const p of allProjekte.value) {
        tMat += p.matVk; tAz += p.azEin; tP += p.pSum;
        tAb  += p.abSum; tGes += p.gesamt; tGw += p.gewinn;
    }
    return { tMat, tAz, tP, tAb, tGes, tGw, tOffen: tGes - tAb };
});

const gwPct = computed(() =>
    totals.value.tGes > 0
        ? (totals.value.tGw / totals.value.tGes * 100).toFixed(1)
        : '0.0',
);

// ── Tab-Wechsel ───────────────────────────────────────────────
watch([activeTab, allProjekte], async () => {
    await nextTick(); // Vue-DOM-Update abwarten

    // Charts über script.js-Escape-Hatches zeichnen (Canvas-Elemente im DOM)
    const awCharts = w['awCharts'] as Record<string, unknown> | undefined;
    if (awCharts) {
        Object.values(awCharts).forEach(c => {
            try { (c as { destroy(): void }).destroy(); } catch (_) {}
        });
        (w['awCharts'] as unknown) = {};
    }

    if (activeTab.value === 'kaufmaennisch') {
        // setTimeout: Canvas-Elemente brauchen einen Rendering-Frame nach nextTick
        setTimeout(() => {
            (w['drawProjektCharts'] as ((p: AuswertungProjekt[]) => void) | undefined)?.(allProjekte.value);
        }, 30);
    } else {
        // Mitarbeiter-Tab: HTML aus Escape-Hatch
        const year = new Date().getFullYear();
        mitarbeiterHtml.value = (w['renderAuswertungMitarbeiter'] as ((y: number, ys: number[]) => string) | undefined)
            ?.(year, [year]) ?? '<p>Mitarbeiter-Daten werden berechnet…</p>';
        await nextTick();
        setTimeout(() => {
            (w['drawMitarbeiterCharts'] as ((y: number) => void) | undefined)?.(year);
        }, 30);
    }
});
</script>

<template>
  <div class="bk-auswertung">

    <!-- ── Toolbar ───────────────────────────────────────────── -->
    <div class="bk-aw-header">
      <h2 style="margin:0">📊 Auswertung</h2>
      <div class="aw-tab-bar">
        <button
          class="aw-tab-btn"
          :class="{ active: activeTab === 'kaufmaennisch' }"
          @click="activeTab = 'kaufmaennisch'"
        >📊 Kaufmännisch</button>
        <button
          class="aw-tab-btn"
          :class="{ active: activeTab === 'mitarbeiter' }"
          @click="activeTab = 'mitarbeiter'"
        >👥 Mitarbeiter</button>
      </div>
    </div>

    <!-- ── Laden / Fehler ───────────────────────────────────── -->
    <div v-if="loading" class="bk-aw-state">Daten werden geladen …</div>
    <div v-else-if="error" class="bk-aw-state bk-aw-error">{{ error }}</div>

    <!-- ── Tab: Kaufmännisch ─────────────────────────────────── -->
    <div v-else-if="activeTab === 'kaufmaennisch'">

      <!-- KPI-Karten Zeile 1 -->
      <div class="bk-aw-kpi-grid bk-aw-kpi-4">
        <div class="aw-kpi">
          <span class="aw-kpi-label">Projekte</span>
          <strong class="aw-kpi-val">{{ allProjekte.length }}</strong>
          <span class="aw-kpi-sub">
            {{ allProjekte.filter(p => p.source === 'aktiv').length }} aktiv –
            {{ allProjekte.filter(p => p.source === 'archiv').length }} archiv
          </span>
        </div>
        <div class="aw-kpi">
          <span class="aw-kpi-label">Umsatz gesamt</span>
          <strong class="aw-kpi-val">{{ fmt(totals.tGes) }}</strong>
        </div>
        <div class="aw-kpi">
          <span class="aw-kpi-label">Gewinn</span>
          <strong class="aw-kpi-val" :style="{ color: totals.tGw >= 0 ? 'var(--success,#198754)' : 'var(--danger,#dc3545)' }">
            {{ fmt(totals.tGw) }}
          </strong>
          <span class="aw-kpi-sub">Marge {{ gwPct }} %</span>
        </div>
        <div class="aw-kpi">
          <span class="aw-kpi-label">Offen</span>
          <strong class="aw-kpi-val" style="color:var(--primary,#00B4D8)">{{ fmt(totals.tOffen) }}</strong>
          <span class="aw-kpi-sub">Abschl. {{ fmt(totals.tAb) }}</span>
        </div>
      </div>

      <!-- KPI-Karten Zeile 2 -->
      <div class="bk-aw-kpi-grid bk-aw-kpi-3" style="margin-top:8px;margin-bottom:12px">
        <div class="aw-kpi aw-kpi-sm">
          <span class="aw-kpi-label">Material (VK)</span>
          <strong class="aw-kpi-val">{{ fmt(totals.tMat) }}</strong>
        </div>
        <div class="aw-kpi aw-kpi-sm">
          <span class="aw-kpi-label">Arbeitszeit</span>
          <strong class="aw-kpi-val">{{ fmt(totals.tAz) }}</strong>
        </div>
        <div class="aw-kpi aw-kpi-sm">
          <span class="aw-kpi-label">Pauschalen</span>
          <strong class="aw-kpi-val">{{ fmt(totals.tP) }}</strong>
        </div>
      </div>

      <!-- Chart-Placeholder (Canvas-Elemente werden von script.js befüllt) -->
      <div class="bk-aw-chart-grid">
        <div class="aw-card">
          <h3 class="aw-card-title">Umsatzverteilung</h3>
          <div style="height:220px"><canvas id="awChartUmsatz"></canvas></div>
        </div>
        <div class="aw-card">
          <h3 class="aw-card-title">Top 10 Gewinn</h3>
          <div style="height:220px"><canvas id="awChartGewinn"></canvas></div>
        </div>
        <div class="aw-card">
          <h3 class="aw-card-title">Top 15 Umsatz</h3>
          <div style="height:220px"><canvas id="awChartProjekte"></canvas></div>
        </div>
      </div>

      <!-- Export-Leiste + Projekttabelle -->
      <div class="aw-card" style="margin-top:12px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:6px">
          <h3 class="aw-card-title" style="margin:0">Alle Projekte ({{ allProjekte.length }})</h3>
          <div v-if="canExport" style="display:flex;gap:6px">
            <button class="btn btn-ghost btn-sm" @click="(w['exportAuswertungKaufmaennischCSV'] as Function)?.()">
              ⬇ CSV Export
            </button>
            <button class="btn btn-ghost btn-sm" @click="(w['printAuswertungKaufmaennisch'] as Function)?.()">
              🖨 Drucken
            </button>
          </div>
        </div>
        <div style="overflow-x:auto">
          <table class="data-table" style="font-size:.82rem">
            <thead>
              <tr>
                <th>Projekt</th>
                <th>Nr.</th>
                <th class="text-right">Umsatz</th>
                <th class="text-right">Gewinn</th>
                <th class="text-right">Offen</th>
                <th>Typ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in allProjekte" :key="p.name + p.projektNr + p.source">
                <td>{{ p.name }}</td>
                <td>{{ p.projektNr || '–' }}</td>
                <td class="text-right">{{ fmt(p.gesamt) }}</td>
                <td class="text-right" :style="{ color: p.gewinn >= 0 ? 'var(--success,#198754)' : 'var(--danger,#dc3545)' }">
                  {{ fmt(p.gewinn) }}
                </td>
                <td class="text-right">{{ fmt(p.offen) }}</td>
                <td>
                  <span style="font-size:.72rem;padding:2px 6px;border-radius:3px"
                        :style="p.source === 'aktiv' ? 'background:#E3F2FD;color:#1565C0' : 'background:#F5F5F5;color:#666'">
                    {{ p.source }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ── Tab: Mitarbeiter (Escape-Hatch) ───────────────────── -->
    <div v-else-if="activeTab === 'mitarbeiter'">
      <div v-if="mitarbeiterHtml" v-html="mitarbeiterHtml" />
      <div v-else class="bk-aw-state">Mitarbeiter-Daten werden berechnet …</div>
    </div>

  </div>
</template>

<style scoped>
.bk-auswertung { max-width: 1400px; margin: 0 auto; padding: 4px 0 24px; }

.bk-aw-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 14px;
}

.bk-aw-state {
  padding: 32px;
  text-align: center;
  color: var(--text-muted, #888);
  font-style: italic;
}
.bk-aw-error { color: #c62828; font-style: normal; }

.bk-aw-kpi-grid { display: grid; gap: 10px; }
.bk-aw-kpi-4 { grid-template-columns: repeat(4, 1fr); }
.bk-aw-kpi-3 { grid-template-columns: repeat(3, 1fr); }

.bk-aw-chart-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 12px;
  margin-bottom: 12px;
}

.text-right { text-align: right; }

@media (max-width: 900px) {
  .bk-aw-kpi-4 { grid-template-columns: repeat(2, 1fr); }
  .bk-aw-chart-grid { grid-template-columns: 1fr; }
}
</style>
