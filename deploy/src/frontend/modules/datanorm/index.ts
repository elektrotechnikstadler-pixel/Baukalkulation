// datanorm/index.ts – Adapter (P4, TypeScript)
// Datanorm ist in die Material-Katalog-View eingebettet; kein dedizierter View.
// Die Methoden delegieren an script.js-Globals.

export function install(): void {
    console.log('[bk] datanorm (P4-Stub) bereit');
}

export function uninstall(): void {}

export function reindex(): void {
    (window as unknown as Record<string, () => void>)['datanormReindex']?.();
}
export function getStatus(): void {
    (window as unknown as Record<string, () => void>)['datanormStatus']?.();
}
