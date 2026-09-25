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
