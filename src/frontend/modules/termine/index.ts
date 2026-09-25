// termine/index.ts – Vue Teleport-Modal (P5.2)
// TermineView.vue wird beim Seitenstart einmalig gemountet (in einem
// dynamisch erzeugten Div). Das Modal öffnet/schließt via isOpen-Ref
// (Teleport zu body). openTermineModal() + renderTermineList() werden
// überschrieben.

import { createApp } from 'vue';
import TermineView   from './TermineView.vue';

let _app:       ReturnType<typeof createApp> | null = null;
let _vueRef:    { open: () => void; close: () => void; triggerRefresh: () => void } | null = null;
let _installed  = false;

export function install(): void {
    if (_installed) return;
    _installed = true;

    // ── REVERT (v2.10.65): Termine-Vue-Island deaktiviert ──────────────
    // Die native Terminplanung in script.js (openTermineModal → #termineContent,
    // loadTermineData, renderTermineList) ist vollständig funktionsfähig und wird
    // NICHT mehr durch das Vue-Modal überschrieben. Grund: wiederholte Regressionen
    // (Termine nicht sichtbar) + Performance. Der User wünscht die alte Struktur.
    // Die Vue-Komponente bleibt im Code erhalten, wird aber nicht gemountet.
    console.log('[bk] termine: native script.js-Logik aktiv (Vue-Island deaktiviert)');
}

/** @deprecated Vue-Island deaktiviert – siehe install() */
function _installVueIsland(): void {
    // Persistenter Mount-Punkt (kein index.html change nötig)
    const mountEl = document.createElement('div');
    mountEl.id = 'bk-termine-mount';
    document.body.appendChild(mountEl);

    _app = createApp(TermineView);
    const instance = _app.mount(mountEl);
    // Exposed API über defineExpose
    _vueRef = instance as unknown as typeof _vueRef;

    const w = window as unknown as Record<string, unknown>;

    // Override: öffnet das Vue-Modal statt des alten dynamischen Overlays
    w['openTermineModal'] = async () => {
        _vueRef?.open();
    };

    // Override: renderTermineList → Vue-Refresh
    w['renderTermineList'] = () => {
        _vueRef?.triggerRefresh();
    };

    console.log('[bk] termine/TermineView.vue installiert');
}
void _installVueIsland; // reference to avoid unused warning

export function uninstall(): void {
    _app?.unmount();
    document.getElementById('bk-termine-mount')?.remove();
    _app = null;
    _vueRef = null;
    _installed = false;
}

export function openTermineModal(): void {
    _vueRef?.open();
}
export function hideTermine(): void {
    _vueRef?.close();
}

