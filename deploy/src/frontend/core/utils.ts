// ============================================================
// utils.ts – Gemeinsame Hilfsfunktionen (TypeScript)
// ============================================================
// Reine Funktionen ohne Seiteneffekte – können in jedem Kontext
// importiert werden (Core, Modulbundles, Tests).
// Entsprechen den gleichnamigen globalen Funktionen in script.js.
//
// Verwendung:
//   import { esc, fmt, fmtNum, formatDate, debounce } from '@core/utils.ts';
// ============================================================

// ── Sicherheit ────────────────────────────────────────────────

/**
 * HTML-Sonderzeichen escapen (XSS-Schutz).
 * Entspricht `esc()` in script.js.
 */
export function esc(s: unknown): string {
    if (!s) return '';
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g,  '&lt;')
        .replace(/>/g,  '&gt;')
        .replace(/"/g,  '&quot;');
}

// ── Zahlen- und Währungsformatierung ─────────────────────────

/**
 * Zahl als Euro-Betrag formatieren (z.B. „12.345,67 €").
 * Entspricht `fmt()` in script.js.
 */
export function fmt(v: number | string | null | undefined): string {
    return (parseFloat(v as string) || 0).toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }) + ' €';
}

/**
 * Zahl als dezimale Zeichenkette ohne Einheit (z.B. „12.345,67").
 * Entspricht `fmtNum()` in script.js.
 */
export function fmtNum(v: number | string | null | undefined): string {
    return (parseFloat(v as string) || 0).toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/**
 * Zahl mit konfigurierbarer Nachkommastellenzahl formatieren.
 */
export function fmtDecimals(v: number | string | null | undefined, decimals = 2): string {
    return (parseFloat(v as string) || 0).toLocaleString('de-DE', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

// ── Datumsformatierung ───────────────────────────────────────

/**
 * ISO-Datum (YYYY-MM-DD) in deutsches Format (DD.MM.YYYY) umwandeln.
 * Entspricht `formatDate()` in script.js.
 */
export function formatDate(d: string | null | undefined): string {
    if (!d) return '';
    const parts = String(d).split('-');
    if (parts.length !== 3) return d;
    return parts[2] + '.' + parts[1] + '.' + parts[0];
}

/**
 * Date-Objekt oder ISO-String als „DD.MM.YYYY" formatieren.
 */
export function formatDateObj(date: Date | string | null | undefined): string {
    if (!date) return '';
    const d = date instanceof Date ? date : new Date(date);
    if (isNaN(d.getTime())) return String(date);
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    return `${dd}.${mm}.${d.getFullYear()}`;
}

// ── Async-Hilfsfunktionen ─────────────────────────────────────

/** Generischer Callback-Typ mit beliebigen Argumenten */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type AnyFn = (...args: any[]) => any;

/**
 * Funktion debouncen: Wiederholte Aufrufe innerhalb von `ms` ms
 * werden gebündelt; nur der letzte wird ausgeführt.
 * Entspricht `_debounce()` in script.js.
 */
export function debounce<T extends AnyFn>(fn: T, ms: number): (...args: Parameters<T>) => void {
    let timer: ReturnType<typeof setTimeout>;
    return function (this: unknown, ...args: Parameters<T>) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), ms);
    };
}

// ── DOM-Hilfsfunktionen ──────────────────────────────────────

type ToastType = 'info' | 'success' | 'error';

/**
 * Toast-Benachrichtigung anzeigen.
 * Delegiert an die globale `showNotification()` von script.js (Compat-Layer).
 * Fällt auf ein einfaches eigenes Toast-Element zurück wenn nicht verfügbar.
 */
export function showToast(msg: string, type: ToastType = 'info'): void {
    const w = window as unknown as Record<string, unknown>;
    if (typeof w['showNotification'] === 'function') {
        (w['showNotification'] as (m: string, t: string) => void)(msg, type);
        return;
    }
    // Standalone-Fallback
    const el = document.createElement('div');
    el.textContent = msg;
    const colors: Record<ToastType, string> = {
        info:    '#2c6fad',
        success: '#2d7d46',
        error:   '#b00020',
    };
    el.style.cssText = [
        'position:fixed', 'bottom:1.5rem', 'right:1.5rem', 'z-index:9999',
        `background:${colors[type]}`, 'color:#fff',
        'padding:.75rem 1.25rem', 'border-radius:.5rem',
        'font-size:.9rem', 'box-shadow:0 4px 12px rgba(0,0,0,.3)',
        'pointer-events:none',
    ].join(';');
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 3500);
}
