<?php
// ============================================================
// DIN EN 1090 – Modul: Datenbank-Schema
// ============================================================

class Din1090Database
{
    public static function init(\PDO $db): void
    {
        // ── Projekte ─────────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS din1090_projects (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                baustelleId         INTEGER,
                bezeichnung         TEXT NOT NULL DEFAULT '',
                ausfuehrungsklasse  TEXT NOT NULL DEFAULT 'EXC2',
                werkstoff           TEXT DEFAULT 'S235JR',
                normen              TEXT DEFAULT 'DIN EN 1090-2',
                verantwortlicher    TEXT DEFAULT '',
                schweissaufsicht    TEXT DEFAULT '',
                pruefstelle         TEXT DEFAULT '',
                status              TEXT DEFAULT 'aktiv',
                notizen             TEXT DEFAULT '',
                erstellt_am         TEXT DEFAULT '',
                erstellt_von        TEXT DEFAULT '',
                aktualisiert_am     TEXT DEFAULT ''
            )
        ");

        // ── Materialverfolgung ───────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS din1090_materials (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                projectId       INTEGER NOT NULL,
                bezeichnung     TEXT NOT NULL DEFAULT '',
                werkstoff       TEXT DEFAULT '',
                abmessung       TEXT DEFAULT '',
                charge_nr       TEXT DEFAULT '',
                schmelz_nr      TEXT DEFAULT '',
                zeugnis_typ     TEXT DEFAULT '3.1',
                zeugnis_nr      TEXT DEFAULT '',
                lieferant       TEXT DEFAULT '',
                menge           REAL DEFAULT 0,
                einheit         TEXT DEFAULT 'Stk',
                pruef_status    TEXT DEFAULT 'offen',
                bemerkung       TEXT DEFAULT '',
                erstellt_am     TEXT DEFAULT '',
                erstellt_von    TEXT DEFAULT ''
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_din1090_mat_proj ON din1090_materials(projectId)");

        // ── Schweißer ────────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS din1090_welders (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                name                TEXT NOT NULL DEFAULT '',
                stempel_nr          TEXT DEFAULT '',
                qualifikation_nr    TEXT DEFAULT '',
                norm                TEXT DEFAULT 'EN ISO 9606-1',
                verfahren           TEXT DEFAULT '',
                position            TEXT DEFAULT '',
                werkstoff_gruppe    TEXT DEFAULT '',
                dicke_bereich       TEXT DEFAULT '',
                gueltig_bis         TEXT DEFAULT '',
                bemerkung           TEXT DEFAULT '',
                erstellt_am         TEXT DEFAULT ''
            )
        ");

        // ── WPS (Schweißanweisungen) ─────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS din1090_wps (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                projectId           INTEGER NOT NULL,
                wps_nr              TEXT NOT NULL DEFAULT '',
                verfahren           TEXT DEFAULT '',
                grundwerkstoff      TEXT DEFAULT '',
                zusatzwerkstoff     TEXT DEFAULT '',
                schutzgas           TEXT DEFAULT '',
                position            TEXT DEFAULT '',
                nahtart             TEXT DEFAULT '',
                blechdicke_von      REAL DEFAULT 0,
                blechdicke_bis      REAL DEFAULT 0,
                vorwaermung         TEXT DEFAULT '',
                wpqr_nr             TEXT DEFAULT '',
                status              TEXT DEFAULT 'freigegeben',
                bemerkung           TEXT DEFAULT '',
                erstellt_am         TEXT DEFAULT '',
                erstellt_von        TEXT DEFAULT ''
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_din1090_wps_proj ON din1090_wps(projectId)");

        // ── Schweißprotokoll ─────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS din1090_weld_log (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                projectId       INTEGER NOT NULL,
                naht_nr         TEXT NOT NULL DEFAULT '',
                bauteil         TEXT DEFAULT '',
                zeichnung_nr    TEXT DEFAULT '',
                wps_id          INTEGER,
                schweisser_id   INTEGER,
                datum           TEXT DEFAULT '',
                position        TEXT DEFAULT '',
                nahtart         TEXT DEFAULT '',
                a_mass          REAL DEFAULT 0,
                laenge          REAL DEFAULT 0,
                vorwaermung     TEXT DEFAULT '',
                zwischenlagen_temp TEXT DEFAULT '',
                pruef_status    TEXT DEFAULT 'offen',
                vt_ergebnis     TEXT DEFAULT '',
                bemerkung       TEXT DEFAULT '',
                erstellt_am     TEXT DEFAULT '',
                erstellt_von    TEXT DEFAULT ''
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_din1090_weld_proj ON din1090_weld_log(projectId)");

        // ── Prüfungen ────────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS din1090_inspections (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                projectId       INTEGER NOT NULL,
                pruef_art       TEXT NOT NULL DEFAULT 'VT',
                bauteil         TEXT DEFAULT '',
                naht_nr         TEXT DEFAULT '',
                pruef_datum     TEXT DEFAULT '',
                pruefer         TEXT DEFAULT '',
                pruef_norm      TEXT DEFAULT '',
                ergebnis        TEXT DEFAULT 'bestanden',
                report_nr       TEXT DEFAULT '',
                umfang          TEXT DEFAULT '',
                bemerkung       TEXT DEFAULT '',
                erstellt_am     TEXT DEFAULT '',
                erstellt_von    TEXT DEFAULT ''
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_din1090_insp_proj ON din1090_inspections(projectId)");

        // ── Abweichungsberichte (NCR) ────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS din1090_ncr (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                projectId           INTEGER NOT NULL,
                ncr_nr              TEXT NOT NULL DEFAULT '',
                datum               TEXT DEFAULT '',
                bauteil             TEXT DEFAULT '',
                beschreibung        TEXT DEFAULT '',
                ursache             TEXT DEFAULT '',
                massnahme           TEXT DEFAULT '',
                verantwortlicher    TEXT DEFAULT '',
                frist               TEXT DEFAULT '',
                status              TEXT DEFAULT 'offen',
                abgeschlossen_am    TEXT DEFAULT '',
                erstellt_am         TEXT DEFAULT '',
                erstellt_von        TEXT DEFAULT ''
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_din1090_ncr_proj ON din1090_ncr(projectId)");

        // ── Checklisten ──────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS din1090_checklists (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                projectId       INTEGER NOT NULL,
                kategorie       TEXT NOT NULL DEFAULT '',
                punkt           TEXT NOT NULL DEFAULT '',
                erforderlich_ab INTEGER DEFAULT 1,
                erledigt        INTEGER DEFAULT 0,
                erledigt_am     TEXT DEFAULT '',
                erledigt_von    TEXT DEFAULT '',
                bemerkung       TEXT DEFAULT ''
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_din1090_chk_proj ON din1090_checklists(projectId)");

        // ── Oberflächenbehandlung ────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS din1090_surface (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                projectId       INTEGER NOT NULL,
                bauteil         TEXT DEFAULT '',
                system          TEXT DEFAULT '',
                vorbehandlung   TEXT DEFAULT '',
                grundierung     TEXT DEFAULT '',
                schicht_1       TEXT DEFAULT '',
                schicht_2       TEXT DEFAULT '',
                soll_dicke      REAL DEFAULT 0,
                ist_dicke       REAL DEFAULT 0,
                pruef_datum     TEXT DEFAULT '',
                pruefer         TEXT DEFAULT '',
                ergebnis        TEXT DEFAULT 'bestanden',
                bemerkung       TEXT DEFAULT '',
                erstellt_am     TEXT DEFAULT '',
                erstellt_von    TEXT DEFAULT ''
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_din1090_surf_proj ON din1090_surface(projectId)");

        // ── Audit-Log ────────────────────────────────────────
        $db->exec("
            CREATE TABLE IF NOT EXISTS din1090_audit_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                projectId   INTEGER,
                aktion      TEXT DEFAULT '',
                details     TEXT DEFAULT '',
                benutzer    TEXT DEFAULT '',
                zeitpunkt   TEXT DEFAULT ''
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_din1090_audit_proj ON din1090_audit_log(projectId)");
    }

    /**
     * Standard-Checkliste nach DIN EN 1090-2 Tabelle A.1 erzeugen
     * @param int $excLevel 1–4 (Ausführungsklasse)
     * @return array [ [kategorie, punkt, erforderlich_ab], ... ]
     */
    public static function getChecklistTemplate(int $excLevel): array
    {
        $items = [
            // ── 1. Ausgangsstoffe ────────────────────────────
            ['Ausgangsstoffe', 'CE-Kennzeichnung der Bauprodukte geprüft', 1],
            ['Ausgangsstoffe', 'Werkszeugnisse 2.1 nach EN 10204 vorhanden', 1],
            ['Ausgangsstoffe', 'Abnahmeprüfzeugnisse 3.1 nach EN 10204 vorhanden', 2],
            ['Ausgangsstoffe', 'Abnahmeprüfzeugnisse 3.2 nach EN 10204 vorhanden', 3],
            ['Ausgangsstoffe', 'Rückverfolgbarkeit der Werkstoffe sichergestellt', 2],
            ['Ausgangsstoffe', 'Eingangsprüfung durchgeführt und dokumentiert', 2],
            ['Ausgangsstoffe', 'Schweißzusätze nach EN ISO 14171/14341/14343 geprüft', 2],
            ['Ausgangsstoffe', 'Lagerung der Werkstoffe gemäß Herstellervorgaben', 1],
            ['Ausgangsstoffe', 'Verbindungsmittel (Schrauben/Niete) zertifiziert', 2],

            // ── 2. Vorbereitung & Zusammenbau ────────────────
            ['Vorbereitung', 'Zeichnungsfreigabe dokumentiert', 1],
            ['Vorbereitung', 'Schnittflächen nach EN ISO 9013 geprüft', 2],
            ['Vorbereitung', 'Lochherstellung nach EN 1090-2 Abschn. 6.6', 2],
            ['Vorbereitung', 'Nahtvorbereitung nach WPS geprüft', 2],
            ['Vorbereitung', 'Passgenauigkeit/Spaltmaße dokumentiert', 2],
            ['Vorbereitung', 'Vorwärmtemperaturen nach EN ISO 13916 eingehalten', 2],
            ['Vorbereitung', 'Heftschweißungen gemäß WPS ausgeführt', 2],
            ['Vorbereitung', 'Oberflächenvorbereitung vor Schweißung dokumentiert', 2],

            // ── 3. Schweißen ─────────────────────────────────
            ['Schweißen', 'Schweißplan erstellt', 2],
            ['Schweißen', 'WPS (Schweißanweisungen) nach EN ISO 15609 erstellt', 2],
            ['Schweißen', 'WPQR (Verfahrensprüfungen) nach EN ISO 15614 vorhanden', 2],
            ['Schweißen', 'Schweißerprüfungen nach EN ISO 9606 gültig', 2],
            ['Schweißen', 'Schweißaufsichtsperson nach EN ISO 14731 benannt', 2],
            ['Schweißen', 'Qualifikation der Schweißaufsicht dokumentiert', 2],
            ['Schweißen', 'Schweißfolge/Schweißfolgeplan festgelegt', 3],
            ['Schweißen', 'Schweißprotokoll (Nahtliste) vollständig geführt', 2],
            ['Schweißen', 'Zusatzwerkstoffe chargenweise dokumentiert', 3],
            ['Schweißen', 'Zwischenlagentemperatur überwacht und dokumentiert', 3],
            ['Schweißen', 'Wärmenachbehandlung (falls erforderlich) dokumentiert', 3],

            // ── 4. Mechanische Verbindungen ──────────────────
            ['Mech. Verbindungen', 'Schraubenverbindungen nach EN 1090-2 Abschn. 8', 1],
            ['Mech. Verbindungen', 'Vorspannkräfte nach EN 14399 dokumentiert', 2],
            ['Mech. Verbindungen', 'Anzugsverfahren (Drehmoment/kombiniert) festgelegt', 2],
            ['Mech. Verbindungen', 'Gleitfeste Verbindungen: Reibflächen geprüft', 3],
            ['Mech. Verbindungen', 'Schrauben-Prüfprotokoll erstellt', 2],

            // ── 5. Oberflächenschutz ─────────────────────────
            ['Oberflächenschutz', 'Korrosionsschutzsystem nach EN ISO 12944 festgelegt', 1],
            ['Oberflächenschutz', 'Vorbehandlungsgrad nach EN ISO 8501 geprüft', 2],
            ['Oberflächenschutz', 'Schichtdickenmessungen durchgeführt', 2],
            ['Oberflächenschutz', 'Beschichtungsprotokoll erstellt', 2],
            ['Oberflächenschutz', 'Feuerverzinkung nach EN ISO 1461 dokumentiert', 2],
            ['Oberflächenschutz', 'Reparaturbeschichtungen dokumentiert', 2],

            // ── 6. Geometrische Toleranzen ───────────────────
            ['Toleranzen', 'Herstellungstoleranzen nach EN 1090-2 Tab. D.1 geprüft', 1],
            ['Toleranzen', 'Montagetoleranzen nach EN 1090-2 Tab. D.2 geprüft', 2],
            ['Toleranzen', 'Maßprotokoll erstellt und dokumentiert', 2],
            ['Toleranzen', 'Winkligkeit und Ebenheit geprüft', 2],
            ['Toleranzen', 'Verwölbung/Verformung innerhalb Grenzwerte', 2],

            // ── 7. Prüfung & Kontrolle ───────────────────────
            ['Prüfung', 'Sichtprüfung (VT) 100% nach EN ISO 17637 durchgeführt', 1],
            ['Prüfung', 'ZfP-Prüfumfang nach EN 1090-2 Tabelle 24 festgelegt', 2],
            ['Prüfung', 'Eindringprüfung (PT) nach EN ISO 3452 durchgeführt', 2],
            ['Prüfung', 'Magnetpulverprüfung (MT) nach EN ISO 17638 durchgeführt', 2],
            ['Prüfung', 'Ultraschallprüfung (UT) nach EN ISO 17640 durchgeführt', 3],
            ['Prüfung', 'Durchstrahlungsprüfung (RT) nach EN ISO 17636 (falls erf.)', 4],
            ['Prüfung', 'Prüfberichte mit Bewertung nach EN ISO 5817 erstellt', 2],
            ['Prüfung', 'Nacharbeit dokumentiert und nachgeprüft', 2],
            ['Prüfung', 'Unabhängige Prüfstelle einbezogen (falls EXC3/4)', 3],

            // ── 8. Montage ───────────────────────────────────
            ['Montage', 'Montageanweisung erstellt', 2],
            ['Montage', 'Auflager und Verankerungen geprüft', 2],
            ['Montage', 'Ausrichtung und Lotrecht dokumentiert', 2],
            ['Montage', 'Montageschweißungen nach WPS ausgeführt', 2],
            ['Montage', 'Baustellenschweißer qualifiziert', 2],
            ['Montage', 'Probeaufbau durchgeführt (falls gefordert)', 3],
            ['Montage', 'Temporäre Aussteifungen dokumentiert', 3],

            // ── 9. Dokumentation & Konformität ───────────────
            ['Dokumentation', 'Leistungserklärung (DoP) nach BauPVO erstellt', 1],
            ['Dokumentation', 'CE-Kennzeichnung angebracht', 1],
            ['Dokumentation', 'Werksbescheinigung nach EN 1090-1 erstellt', 2],
            ['Dokumentation', 'Konformitätserklärung erstellt', 2],
            ['Dokumentation', 'Werkseigene Produktionskontrolle (WPK) dokumentiert', 2],
            ['Dokumentation', 'Dokumentationspaket vollständig zusammengestellt', 2],
            ['Dokumentation', 'Aufbewahrungsfristen festgelegt (mind. 10 Jahre)', 1],
            ['Dokumentation', 'Übergabe der Bauakte an Auftraggeber', 2],
            ['Dokumentation', 'Abnahmeprotokoll erstellt und unterzeichnet', 2],
        ];

        // Nur Items die für die gewählte EXC relevant sind
        return array_filter($items, fn($i) => $i[2] <= $excLevel);
    }
}
