// src/frontend/__tests__/utils.spec.ts
// Tests für src/frontend/core/utils.ts

import { describe, it, expect, vi } from 'vitest';
import { esc, fmt, fmtNum, fmtDecimals, formatDate, formatDateObj, debounce } from '@core/utils.ts';

describe('esc – XSS-Schutz', () => {
    it('escapt alle HTML-Sonderzeichen', () => {
        expect(esc('<script>alert("xss")</script>')).toBe('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;');
    });
    it('gibt leeren String für falsy-Werte zurück', () => {
        expect(esc('')).toBe('');
        expect(esc(null)).toBe('');
        expect(esc(undefined)).toBe('');
    });
    it('konvertiert Zahlen korrekt', () => {
        expect(esc(42)).toBe('42');
    });
});

describe('fmt – Währungsformatierung', () => {
    it('formatiert Zahlen mit 2 Nachkommastellen + €', () => {
        expect(fmt(1234.5)).toBe('1.234,50 €');
    });
    it('behandelt null als 0', () => {
        expect(fmt(null)).toBe('0,00 €');
    });
    it('negativer Betrag', () => {
        expect(fmt(-50)).toBe('-50,00 €');
    });
});

describe('fmtNum – Zahlenformatierung ohne €', () => {
    it('gibt Zahl ohne €-Zeichen zurück', () => {
        expect(fmtNum(1234.5)).toBe('1.234,50');
    });
});

describe('fmtDecimals – Dezimalformatierung', () => {
    it('0 Nachkommastellen', () => {
        expect(fmtDecimals(1234.5, 0)).toBe('1.235');
    });
    it('Standard 2 Nachkommastellen', () => {
        expect(fmtDecimals(3.1415)).toBe('3,14');
    });
});

describe('formatDate – Datumskonvertierung', () => {
    it('konvertiert ISO → DE-Format', () => {
        expect(formatDate('2026-07-03')).toBe('03.07.2026');
    });
    it('gibt leeren String für null/leer zurück', () => {
        expect(formatDate(null)).toBe('');
        expect(formatDate('')).toBe('');
    });
    it('gibt unvollständige Datumsstrings unverändert zurück (kein 3-teiliges ISO)', () => {
        expect(formatDate('2026-07')).toBe('2026-07');   // nur 2 Teile
        expect(formatDate('20260703')).toBe('20260703'); // keine Trennzeichen
    });
});

describe('formatDateObj – Date-Objekt formatieren', () => {
    it('formatiert Date-Objekt korrekt', () => {
        expect(formatDateObj(new Date(2026, 6, 3))).toBe('03.07.2026');
    });
    it('gibt leeren String für null zurück', () => {
        expect(formatDateObj(null)).toBe('');
    });
});

describe('debounce', () => {
    it('verzögert den Aufruf', async () => {
        vi.useFakeTimers();
        const fn = vi.fn();
        const debounced = debounce(fn, 100);

        debounced();
        debounced();
        debounced(); // nur letzter soll ausgeführt werden

        expect(fn).not.toHaveBeenCalled();
        vi.advanceTimersByTime(100);
        expect(fn).toHaveBeenCalledOnce();
        vi.useRealTimers();
    });
});
