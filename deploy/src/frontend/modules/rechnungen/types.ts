// ============================================================
// types.ts – Rechnungen & Angebote (TypeScript-Typen)
// ============================================================

export type RechnungTyp    = 'rechnung' | 'angebot';
export type RechnungStatus = 'offen' | 'bezahlt' | 'storniert' | 'angenommen' | 'abgelehnt';

export type PosTyp =
    | 'normal'
    | 'gruppe'
    | 'freitext'
    | 'eventual'
    | 'trennlinie'
    | 'zwischensumme';

export interface Position {
    id?:           string;
    parentId?:     string;
    posTyp?:       PosTyp;
    bezeichnung:   string;
    menge:         number;
    einheit?:      string;
    einzelpreis:   number;
    rabatt?:       number;
    gesamt?:       number; // für zwischensumme
}

export interface Rechnung {
    id:             number;
    typ:            RechnungTyp;
    nummer:         string;
    datum?:         string;
    faelligAm?:     string;
    zahlungsziel?:  string;
    status:         RechnungStatus;
    kundeId?:       number | null;
    kundeName?:     string;
    kundeEmail?:    string;
    baustelleId?:   number | null;
    baustelleName?: string;
    projektNr?:     string;
    beschreibung?:  string;
    notizen?:       string;
    positionen?:    Position[];
    createdAt?:     string;
    updatedAt?:     string;
}

export interface ListRechnungenResponse {
    ok:        boolean;
    rechnungen?: Rechnung[];
    error?:    string;
}

// ── Status-Darstellung ────────────────────────────────────────

export const STATUS_STYLE: Record<RechnungStatus, { bg: string; color: string; label: string }> = {
    offen:       { bg: '#FFF3E0', color: '#E65100', label: 'Offen' },
    bezahlt:     { bg: '#E8F5E9', color: '#2E7D32', label: 'Bezahlt' },
    storniert:   { bg: '#FFEBEE', color: '#C62828', label: 'Storniert' },
    angenommen:  { bg: '#E8F5E9', color: '#2E7D32', label: 'Angenommen' },
    abgelehnt:   { bg: '#FFEBEE', color: '#C62828', label: 'Abgelehnt' },
};
