// ============================================================
// useStore.ts – Vue 3 Composable für den reaktiven Store
// ============================================================
// Brücke zwischen dem Framework-unabhängigen Store (store.ts)
// und Vue 3s Reaktivitätssystem. Hört auf Store-Änderungen und
// gibt ein getyptes `ref<>` zurück, das automatisch reaktiv ist.
//
// Verwendung in Vue-Komponenten:
//   import { useStore } from '@core/useStore.ts';
//
//   const user      = useStore('user');     // Ref<BkUser | null>
//   const isOffline = useStore('isOffline'); // Ref<boolean>
//
//   // Template: {{ user?.username }}
//   // Reaktiv: ändert sich automatisch wenn setState() aufgerufen wird
// ============================================================

import { ref, onUnmounted } from 'vue';
import { getState, subscribe } from './store.ts';
import type { BkState } from './store.ts';

/**
 * Reaktives Ref für ein einzelnes State-Feld.
 * Hört automatisch auf Änderungen und meldet sich beim Unmount ab.
 *
 * @param key  Schlüssel aus BkState (z.B. 'user', 'isOffline')
 * @returns    Vue Ref<BkState[K]> – reaktiv, immer aktuell
 */
export function useStore<K extends keyof BkState>(key: K) {
    // Initialer Wert aus dem aktuellen State
    const value = ref<BkState[K]>(getState()[key]);

    // Subscribe: Wert bei Store-Änderungen nachziehen
    const unsub = subscribe(key, (newVal) => {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        (value as any).value = newVal;
    });

    // Cleanup: Subscriber abmelden wenn die Komponente unmounted
    onUnmounted(unsub);

    return value;
}
