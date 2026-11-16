// ============================================================
// types.ts – Zeiterfassung Desktop (TypeScript-Typen)
// ============================================================

export interface ZeitEntry {
    id:             number;
    datum?:         string;
    typ:            string;    // 'arbeit'|'urlaub'|'krank'|'gleitzeit'|'sonstig'|'feiertag'
    stunden?:       number;
    baustelleId?:   number | null;
    baustelleName?: string;
    bemerkung?:     string;
    von?:           string;
    bis?:           string;
    pause?:         number;   // Minuten
    /** Nur bei typ 'urlaub': 0.5 = halber Tag, sonst 1 */
    tagesanteil?:   number;
    stundenKatId?:  number | null;
    entryId?:       string;
    username?:      string;
    /** Automatisch erzeugte Feiertags-/Virtuelle-Einträge */
    _virtual?:      boolean;
}

// ── Typ-Anzeige ───────────────────────────────────────────────

export const TYP_EMOJI: Record<string, string> = {
    arbeit:    '⏱',
    urlaub:    '🌴',
    krank:     '🤒',
    gleitzeit: '⏱',
    sonstig:   '⚠️',
    feiertag:  '🎉',
};

export const TYP_LABEL: Record<string, string> = {
    arbeit:    'Arbeit',
    urlaub:    'Urlaub',
    krank:     'Krank',
    gleitzeit: 'Gleitzeit',
    sonstig:   'Sonstiger Fehlgrund',
    feiertag:  'Feiertag',
};

// ── Urlaubstage (halbe Tage zählen 0,5; gleichwertig zu bkTagesanteil/bkUrlaubTage/bkFormatTage) ──

type UrlaubEintrag = { typ: string; datum?: string; tagesanteil?: number };

export function tagesanteil(e: UrlaubEintrag | null | undefined): number {
    if (!e || e.typ !== 'urlaub') return 1;
    return Math.abs(Number(e.tagesanteil) - 0.5) < 0.001 ? 0.5 : 1;
}

export function urlaubTage(entries: UrlaubEintrag[], jahr?: number | string): number {
    const prefix = jahr === undefined || jahr === '' ? '' : String(jahr);
    return entries.reduce((summe, e) => {
        if (e.typ !== 'urlaub') return summe;
        if (prefix && !e.datum?.startsWith(prefix)) return summe;
        return summe + tagesanteil(e);
    }, 0);
}

export function formatTage(n: number): string {
    return String(Math.round((Number(n) || 0) * 2) / 2).replace('.', ',');
}
