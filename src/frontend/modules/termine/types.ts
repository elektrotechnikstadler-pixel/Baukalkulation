// ============================================================
// types.ts – Terminplanung (TypeScript-Typen)
// ============================================================

export type TerminPrioritaet = 'normal' | 'hoch' | 'dringend';
export type ZeitraumFilter   = 'upcoming' | 'past' | 'all' | 'today' | 'week' | 'month';

export interface Termin {
    id:            number;
    titel:         string;
    datum:         string;          // ISO YYYY-MM-DD
    zeitVon?:      string;          // HH:MM
    zeitBis?:      string;
    ganztags?:     boolean;
    beschreibung?: string;
    ort?:          string;
    baustelleId?:  number | null;
    farbe?:        string;
    ersteller?:    string;
    zugewiesen?:   string[];
    prioritaet?:   TerminPrioritaet;
    erledigt?:     boolean;
}

export interface LoadTermineResponse {
    ok:       boolean;
    termine?: Termin[];
    error?:   string;
}
