// oci/index.ts – Adapter (P4, TypeScript)
// OCI ist in den Admin-Einstellungen und im Bestell-Workflow eingebettet;
// kein dedizierter View.

export function install(): void {
    console.log('[bk] oci (P4-Stub) bereit');
}

export function uninstall(): void {}

export function openSupplierPicker(): void {
    (window as unknown as Record<string, () => void>)['openOciSupplierPicker']?.();
}
