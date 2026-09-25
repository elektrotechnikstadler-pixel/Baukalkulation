// ============================================================
// utils.js – Gemeinsame Hilfsfunktionen (Baukalkulation ES)
// ============================================================
// Reine Funktionen ohne Seiteneffekte – können in jedem Kontext
// importiert werden (Core, Modulbundles, Tests).
//
// Alle Implementierungen spiegeln das Verhalten der gleichnamigen
// globalen Funktionen in script.js wider, damit Phase-3+-Module
// konsistente Ausgaben liefern.
//
// Verwendung:
//   import { esc, fmt, fmtNum, formatDate, debounce } from '@core/utils.js';
// ============================================================

// ── Sicherheit ────────────────────────────────────────────────

/**
 * HTML-Sonderzeichen escapen (XSS-Schutz).
 * Entspricht `esc()` in script.js.
 *
 * @param {any} s
 * @returns {string}
 */
export function esc(s) {
    if (!s) return '';
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

// ── Zahlen- und Währungsformatierung ─────────────────────────

/**
 * Zahl als Euro-Betrag formatieren (z.B. „12.345,67 €").
 * Entspricht `fmt()` in script.js.
 *
 * @param {number|string|null} v
 * @returns {string}
 */
export function fmt(v) {
    return (parseFloat(v) || 0).toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }) + ' €';
}

/**
 * Zahl als dezimale Zeichenkette ohne Einheit (z.B. „12.345,67").
 * Entspricht `fmtNum()` in script.js.
 *
 * @param {number|string|null} v
 * @returns {string}
 */
export function fmtNum(v) {
    return (parseFloat(v) || 0).toLocaleString('de-DE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/**
 * Zahl mit konfigurierbarer Nachkommastellenzahl formatieren.
 *
 * @param {number|string|null} v
 * @param {number}             decimals  (default: 2)
 * @returns {string}
 */
export function fmtDecimals(v, decimals = 2) {
    return (parseFloat(v) || 0).toLocaleString('de-DE', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

// ── Datumsformatierung ───────────────────────────────────────

/**
 * ISO-Datum (YYYY-MM-DD) in deutsches Format (DD.MM.YYYY) umwandeln.
 * Entspricht `formatDate()` in script.js.
 *
 * @param {string|null} d  - ISO-Datum oder null/leer
 * @returns {string}
 */
export function formatDate(d) {
    if (!d) return '';
    const parts = String(d).split('-');
    if (parts.length !== 3) return d;
    return parts[2] + '.' + parts[1] + '.' + parts[0];
}

/**
 * Datum-Objekt oder ISO-String als „DD.MM.YYYY" formatieren.
 *
 * @param {Date|string|null} date
 * @returns {string}
 */
export function formatDateObj(date) {
    if (!date) return '';
    const d = date instanceof Date ? date : new Date(date);
    if (isNaN(d)) return String(date);
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    return `${dd}.${mm}.${d.getFullYear()}`;
}

// ── Async-Hilfsfunktionen ─────────────────────────────────────

/**
 * Funktion debouncen: Wiederholte Aufrufe innerhalb von `ms` ms
 * werden gebündelt; nur der letzte wird ausgeführt.
 * Entspricht `_debounce()` in script.js.
 *
 * @param {Function} fn
 * @param {number}   ms
 * @returns {Function}
 */
export function debounce(fn, ms) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), ms);
    };
}

// ── DOM-Hilfsfunktionen ──────────────────────────────────────

/**
 * Toast-Benachrichtigung anzeigen.
 * Nutzt die globale `showNotification()` von script.js als Compat-Layer.
 * Fällt auf ein einfaches eigenes Toast-Element zurück, wenn die
 * globale Funktion nicht verfügbar ist (z.B. in isolierten Tests).
 *
 * @param {string} msg
 * @param {'info'|'success'|'error'} [type]
 */
export function showToast(msg, type = 'info') {
    // Compat: globale Funktion aus script.js nutzen wenn verfügbar
    if (typeof window !== 'undefined' && typeof window.showNotification === 'function') {
        window.showNotification(msg);
        return;
    }
    // Standalone-Fallback
    const el = document.createElement('div');
    el.textContent = msg;
    const colors = { info: '#2c6fad', success: '#2d7d46', error: '#b00020' };
    el.style.cssText = [
        'position:fixed', 'bottom:1.5rem', 'right:1.5rem', 'z-index:9999',
        `background:${colors[type] ?? colors.info}`, 'color:#fff',
        'padding:.75rem 1.25rem', 'border-radius:.5rem',
        'font-size:.9rem', 'box-shadow:0 4px 12px rgba(0,0,0,.3)',
        'pointer-events:none',
    ].join(';');
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 3500);
}
