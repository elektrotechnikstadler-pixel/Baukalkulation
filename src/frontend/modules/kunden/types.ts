// ============================================================
// types.ts – Kundenstamm (TypeScript-Typen)
// ============================================================

export interface Kunde {
    id:            number;
    kundennummer?: string;
    anrede?:       string;
    firma?:        string;
    vorname?:      string;
    nachname?:     string;
    strasse?:      string;
    plz?:          string;
    ort?:          string;
    land?:         string;
    email?:        string;
    telefon?:      string;
    mobil?:        string;
    fax?:          string;
    website?:      string;
    notizen?:      string;
    createdAt?:    string;
    updatedAt?:    string;
}

export interface LoadKundenResponse {
    ok:      boolean;
    kunden?: Kunde[];
    error?:  string;
}
