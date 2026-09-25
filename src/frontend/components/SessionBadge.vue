<script setup lang="ts">
// ============================================================
// SessionBadge.vue – Reaktive Session-Statusanzeige (P3 Island)
// ============================================================
// Zeigt den eingeloggten Nutzer (Kürzel + Rolle) und den
// Online/Offline-Status reaktiv aus dem Store.
//
// Mount-Punkt: <div id="bk-session-island"> in index.html
// Companion-Modus: läuft parallel zu script.js (Phase 5)
// Rollback: <div id="bk-session-island"> aus index.html entfernen
// ============================================================

import { computed }  from 'vue';
import { useStore }  from '@core/useStore.ts';

// ── Reaktive Store-Werte ─────────────────────────────────────
const user      = useStore('user');
const isOffline = useStore('isOffline');

// ── Computed: Anzeige-Werte ───────────────────────────────────
const displayName = computed(() =>
    user.value?.kuerzel || user.value?.username || '',
);

const roleBadge = computed<{ label: string; color: string } | null>(() => {
    switch (user.value?.role) {
        case 'admin':  return { label: 'Admin',  color: '#c0392b' };
        case 'master': return { label: 'Master', color: '#7d3c98' };
        default:       return null;
    }
});

const statusTitle = computed(() =>
    isOffline.value ? 'Offline – Verbindung unterbrochen' : 'Online',
);
</script>

<template>
  <!--
    Nur rendern, wenn der Store einen User enthält.
    Vor dem Bootstrap oder bei abgelaufener Session bleibt die
    Komponente unsichtbar (kein leerer Platzhalter).
  -->
  <div
    v-if="user"
    class="bk-session-badge"
    :title="`${displayName} · ${statusTitle}`"
  >
    <!-- Nutzer-Kürzel / Name -->
    <span class="bk-badge-name">{{ displayName }}</span>

    <!-- Rollen-Tag (Admin / Master) -->
    <span
      v-if="roleBadge"
      class="bk-badge-role"
      :style="{ background: roleBadge.color }"
    >{{ roleBadge.label }}</span>

    <!-- Online / Offline Indikator -->
    <span
      class="bk-badge-dot"
      :class="isOffline ? 'is-offline' : 'is-online'"
      aria-hidden="true"
    />
  </div>
</template>

<style scoped>
/* ── Container ────────────────────────────────────────────── */
.bk-session-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px 3px 8px;
  border-radius: 20px;
  border: 1px solid var(--border-color, #d0d0d0);
  background: var(--card-bg, #ffffff);
  font-size: .80rem;
  line-height: 1;
  cursor: default;
  user-select: none;
  /* Sanftes Einblenden beim ersten Render */
  animation: bk-badge-fadein 0.4s ease;
}

@keyframes bk-badge-fadein {
  from { opacity: 0; transform: translateY(-2px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* ── Nutzer-Name ─────────────────────────────────────────── */
.bk-badge-name {
  font-weight: 600;
  color: var(--text-primary, #222);
  max-width: 100px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* ── Rollen-Tag ──────────────────────────────────────────── */
.bk-badge-role {
  font-size: .68rem;
  font-weight: 700;
  color: #fff;
  border-radius: 3px;
  padding: 1px 5px;
  text-transform: uppercase;
  letter-spacing: .03em;
}

/* ── Status-Punkt ────────────────────────────────────────── */
.bk-badge-dot {
  width:  8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
  transition: background 0.4s ease, box-shadow 0.4s ease;
}

.bk-badge-dot.is-online {
  background: #27ae60;
  box-shadow: 0 0 0 2px rgba(39, 174, 96, .25);
}

.bk-badge-dot.is-offline {
  background: #95a5a6;
  box-shadow: none;
}

/* ── Dark-Mode ────────────────────────────────────────────── */
@media (prefers-color-scheme: dark) {
  .bk-session-badge {
    border-color: var(--border-color, #444);
    background:   var(--card-bg, #2a2a2a);
  }
  .bk-badge-name {
    color: var(--text-primary, #eee);
  }
}
</style>
