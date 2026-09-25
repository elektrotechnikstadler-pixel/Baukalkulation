// ============================================================
// types.ts – Stundenauswertung (TypeScript-Typen)
// ============================================================

export interface SaUser {
    username:            string;
    role:                string;
    kuerzel?:            string;
    sollstundenTag?:     number;
    sollTageWoche?:      number;
    urlaubstageProJahr?: Record<number, number>;
    showInZeitverwaltung?: boolean;
    arbeitstage?:        unknown;
}

export interface SaEntry {
    id:            number;
    datum?:        string;
    typ:           string;
    stunden?:      number;
    baustelleId?:  number | null;
    baustelleName?: string;
    bemerkung?:    string;
    von?:          string;
    bis?:          string;
    pause?:        number;
}

export interface SaZeitData {
    [username: string]: {
        entries: SaEntry[];
    };
}

export interface SaAnomalieEntry {
    datum:  string;
    level:  'warn' | 'error';
    text:   string;
}
