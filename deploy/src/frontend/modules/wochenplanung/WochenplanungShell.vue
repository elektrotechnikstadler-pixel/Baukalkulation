<script setup lang="ts">
// ============================================================
// WochenplanungShell.vue – Lade-Shell für Wochenplanung (P5)
// ============================================================
// mount-Punkt: #bk-wp-shell (vor #wpContent im Modal-Body).
// Die komplexe Timeline-Renderlogik (Drag&Drop, Echtzeit-Polling)
// bleibt in script.js. Vue liefert die UX-Hülle: Loading-Spinner,
// Fehler-State, reaktive Metadaten.
// ============================================================
import { ref } from 'vue';

const isLoading = ref(false);
const error     = ref<string | null>(null);

// ── Öffentliche API (via defineExpose für index.ts) ──────────────
// WICHTIG: Refs NICHT direkt exponieren – Vue auto-unwrappt sie
// (instance.isLoading = false/null, nicht Ref) → .value = x wirft.
// Setter-Funktionen werden nicht unwrappt und können direkt aufgerufen werden.
defineExpose({
    setLoading: (v: boolean)       => { isLoading.value = v; },
    setError:   (msg: string|null) => { error.value = msg; },
});
</script>

<template>
  <!-- Lade-Overlay (erscheint über dem Wochenplan-Bereich) -->
  <div
    v-if="isLoading"
    style="
      display:flex;align-items:center;justify-content:center;
      padding:48px 0;color:var(--text-muted,#888);gap:12px;
      font-size:.95rem;
    "
  >
    <span style="font-size:1.5rem;animation:spin 1s linear infinite;">⟳</span>
    Wochenplan wird geladen …
  </div>

  <!-- Fehler-State -->
  <div
    v-else-if="error"
    style="padding:24px;color:#c62828;text-align:center;font-size:.9rem;"
  >
    {{ error }}
  </div>
</template>

<style>
@keyframes spin { to { transform: rotate(360deg); } }
</style>
