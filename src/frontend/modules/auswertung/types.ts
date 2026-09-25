// ============================================================
// types.ts – Auswertung (TypeScript-Typen)
// ============================================================

export interface AuswertungProjekt {
    name:       string;
    projektNr:  string;
    matVk:      number;
    matEk:      number;
    azEin:      number;
    azFk:       number;
    pSum:       number;
    abSum:      number;
    gesamt:     number;
    gewinn:     number;
    offen:      number;
    source:     'aktiv' | 'archiv';
}

export interface AuswertungUser {
    username:               string;
    kuerzel?:               string;
    role:                   string;
    sollstundenTag?:        number;
    sollTageWoche?:         number;
    showInZeitverwaltung?:  boolean;
    urlaubstageProJahr?:    Record<number, number>;
    arbeitstage?:           unknown;
}

export interface AuswertungArchive {
    id:                 number;
    name:               string;
    projektNr?:         string;
    archivedAt?:        string;
    matVk?:             number;
    matEk?:             number;
    azEin?:             number;
    azFk?:              number;
    pSum?:              number;
    abSum?:             number;
    gesamt?:            number;
    gewinn?:            number;
    offen?:             number;
    includeInAuswertung?: boolean;
    [key: string]:      unknown;
}

export interface AuswertungData {
    archives:   AuswertungArchive[];
    zeit:       Record<string, unknown>;
    users:      AuswertungUser[];
    gleitzeit:  Record<string, unknown[]>;
}
