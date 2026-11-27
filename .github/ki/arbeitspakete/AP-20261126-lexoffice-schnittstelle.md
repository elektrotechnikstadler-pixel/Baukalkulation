# AP-20261126-lexoffice-schnittstelle: Schnittstelle Lexware Office (ehem. lexoffice)

| Feld | Wert |
|---|---|
| Status | in Umsetzung (B0 + B1 abgeschlossen, Review APPROVE; nächster Schritt B2) |
| Typ | Modul |
| Testläufe rot | 1 (Umgebung: Docker Desktop aus) |
| Review-Runden | 2 |
| Commit | B0/B1: `49b0928` (Code) + dieser `docs(ki)`-Commit; vorab Test-AP-Zwischenstand `bed4ee7`/`3be0e9d` (Nutzerwunsch) – nicht gepusht |

## 1. Auftrag

> Lexoffice API Schnittstelle einbinden: stelle die beste Lösung zu Einbindung der Lexoffice API Schnittstelle,
> erstelle einen Plan, was ist demnach sinnvoll in der aktuellen Projektierung zu ändern, gibt es Buttons /
> zusätzliche Informationen, welche erfasst werden sollten. Die Schnittstelle soll wieder in Verwaltung allgemein
> aktiviert/deaktiviert werden können.
>
> Zusatz: künftig alles tokensparend umsetzen; Tests usw. so umsetzen, dass dafür keine Tokens mehr benötigt
> werden (→ E-037, E-100, Bündel B0).
>
> Ergänzung 2026-11-26: Es soll eingestellt werden können, ob die Rechnung in der Baukalkulation oder in
> Lexoffice erzeugt wird. Wird sie in der Baukalkulation erzeugt, dann wie beschrieben. Wird sie in Lexoffice
> erzeugt, sind Angebote und Rechnungen in der Baukalkulation deaktiviert; aus den Projekten werden Material,
> Arbeitszeit, Pauschalen und Abschläge sinnvoll in Lexoffice übertragen.

## 2. Plan

### 2.1 Fakten zur API (geprüft 2026-11-26, developers.lexware.io/docs)

- lexoffice heißt jetzt **Lexware Office**; Gateway `https://api.lexware.io`, REST/JSON, Bearer-API-Key
  (erzeugt unter `app.lexware.de/addons/public-api`).
- Limit **2 Anfragen/s** für alle Endpunkte zusammen (Token-Bucket), sonst HTTP 429.
- Relevante Endpunkte: `GET /v1/profile`, `/v1/contacts`, `POST /v1/vouchers` (Buchhaltungsbeleg),
  `POST /v1/vouchers/{id}/files` (PDF/E-Rechnung anhängen), `GET /v1/voucherlist?voucherType&voucherStatus`,
  `GET /v1/payments/{voucherId}`, `GET /v1/posting-categories`, `/v1/event-subscriptions` (Webhook
  `payment.changed`), Permalinks `…/permalink/<typ>/view/{id}`; Steuerarten u. a. `net`, `vatfree`,
  `constructionService13b`.
- **Verkaufsbelege anlegbar:** `POST /v1/quotations`, `POST /v1/invoices[?finalize=true]`, Nachfolgebeleg per
  `?precedingSalesVoucherId=…`, `POST /v1/credit-notes`; Positionen `type` `custom` (Name, Beschreibung, Menge,
  Einheit, `unitPrice.netAmount`, `taxRatePercentage`, `discountPercentage`) und `text` (Zwischenüberschrift).
- **Nur lesbar:** **Abschlagsrechnungen** (`GET /v1/down-payment-invoices/{id}`, „cannot be created or modified
  by means of the API“) und **Schlussrechnungen** (`closingInvoice`, `downPaymentDeductions` read-only; ein
  Beleg kann per API nicht zur Schlussrechnung fortgeführt werden).

### 2.2 Betriebsart wählbar: „Rechnungen erzeugen in“

Neue Einstellung `rechnung_erzeugung` = `baukalkulation` (Standard) | `lexware`, nur wählbar bei aktivem
Modul Lexware Office.

| | **Modus A – Baukalkulation erzeugt** | **Modus B – Lexware erzeugt** |
|---|---|---|
| Angebote/Rechnungen in der Baukalkulation | aktiv wie bisher | **deaktiviert** (Menü, Projektknöpfe, Mobil ausgeblendet; Endpunkte 403) |
| Nummernkreis, PDF, E-Rechnung | Baukalkulation | Lexware |
| Übertragung | fertige Rechnung als **Buchhaltungsbeleg** + eigenes PDF/ZUGFeRD (`/v1/vouchers` + `/files`) | **Projektpositionen** (Material, Arbeitszeit, Pauschalen) als **Angebot** bzw. **Rechnungsentwurf** (`/v1/quotations`, `/v1/invoices` ohne `finalize`) |
| Abschläge | Baukalkulation wie bisher, je Abschlag ein Beleg | in Lexware aus dem übertragenen Angebot erzeugt, Rückspiegel ins Projekt (2.2.2) |
| Zahlstatus | Abruf → Rechnungsstatus | Abruf → Lexware-Belege am Projekt (Auswertung, Kunde) |

Verworfen: Rechnung in beiden Systemen parallel (zwei Nummernkreise, Doppelbuchung) · nur Datei-Upload
(`/v1/files`: keine Kundenzuordnung, kein Zahlstatus).

#### 2.2.1 Modus B – Projektpositionen nach Lexware (sinnvolle Abbildung)

**Übertragungsmaske „An Lexware übertragen“** im Projekt – eigene Maske in zwei Schritten (G1-Antwort 4):

1. **Auswahl** (je Übertragung, Vorbelegung = letzte Auswahl dieses Projekts, gespeichert als
   `lexofficeAuswahl` im Projekt-JSON):
   - Belegart: **Angebot** oder **Rechnungsentwurf**; Leistungszeitraum (vorbelegt aus Arbeitszeit-Daten).
   - Gruppen ein/aus: Material · Arbeitszeit · Pauschalen · Gruppenüberschriften.
   - Darstellung **Material**: einzeln · Summe je Kategorie · eine Zeile „Material lt. Aufstellung“.
   - Darstellung **Arbeitszeit**: je Kategorie/Stundensatz zusammengefasst · je Tag · je Tag und Kürzel · eine Zeile.
   - Darstellung **Pauschalen**: einzeln · Summe.
   - Positionsliste mit Häkchen je Material/Arbeitszeit/Pauschale; Filter **„nur noch nicht abgerechnete“** (Standard an).
   - Einleitungs-/Schlusstext (vorbelegt aus Verwaltung).
2. **Vorschau**: Positionen genau wie sie in Lexware ankommen, Netto/USt/Brutto; **Zurück** oder
   **Übertragen**; danach Link **In Lexware öffnen**.

Die Tabelle zeigt die Abbildung je Darstellungsoption:

| Quelle im Projekt | Lexware-Position | Standard (einstellbar) |
|---|---|---|
| Gruppe | `text`-Zeile „Material“, „Arbeitsleistung“, „Pauschalen“ | an |
| Material (`material[]`) | `custom`: Artikelnr. + Bezeichnung, Menge, Einheit, **VK-Einzelpreis** (EK × Aufschlag × globaler Aufschlag × Rabatt bzw. `vkDirekt`) | **einzeln**; alternativ Summe je Kategorie oder eine Position „Material lt. Aufstellung“ |
| Arbeitszeit (`arbeitszeit[]`) | `custom` je **Kategorie/Stundensatz zusammengefasst**: „Arbeitszeit Monteur“, Std, Stundensatz; Leistungszeitraum = erstes–letztes Datum | zusammengefasst; alternativ je Tag/Mitarbeiter (Datum + Kürzel in Beschreibung, keine Klarnamen) |
| Pauschalen (`pauschalen[]`) | `custom`: Name, Anzahl, Preis aus Pauschalenkatalog | einzeln |
| Steuer | `taxRatePercentage` = `firma_mwst_satz`; Kleinunternehmer → `vatfree`; §13b-Projekt → `constructionService13b` | – |

- **Eine Rechenquelle:** Preise/Summen aus einem neuen gemeinsamen Dienst `ProjektPositionen`, den auch der
  bestehende Projekt-Export (`ExportActions` Material/Arbeitszeit/Pauschalen) nutzt → Export = Lexware (E-086).
- **Teilabrechnung ohne Doppelungen:** übertragene Positionen werden je Projekt mit Lexware-Beleg-ID
  vermerkt; der nächste Rechnungsentwurf enthält standardmäßig nur **noch nicht abgerechnete** Positionen
  (Schalter „alles erneut“). Ein verworfener Entwurf in Lexware gibt seine Positionen beim Abgleich wieder frei.
- **Entwurf statt finalisiert:** Lexware vergibt Nummer und PDF erst beim Fertigstellen durch den Nutzer –
  Prüfung im gewohnten Lexware-Layout, keine ungewollt festgeschriebenen Rechnungen.

#### 2.2.2 Modus B – Abschläge (API-Grenze)

Da Abschlags- und Schlussrechnungen per API nicht anlegbar sind:

1. Baukalkulation überträgt das **Angebot** (bzw. den Auftragsumfang) mit allen Projektpositionen.
2. Der **Abschlagsplan** bleibt im Projekt (Bezeichnung, % oder Betrag, Fälligkeit) – Knopf **„Abschlag in
   Lexware erstellen“** öffnet das Angebot per Permalink; dort erzeugt Lexware die Abschlagsrechnung nativ.
3. Der Abgleich liest Abschlags- und Schlussrechnungen samt Zahlstatus zurück (`voucherlist`,
   `down-payment-invoices`, Belegkette des Angebots) und hakt den Plan ab: Nummer, Betrag, bezahlt am.
4. Die **Schlussrechnung** erstellt der Nutzer in Lexware; Lexware zieht die Abschläge steuerlich korrekt ab
   (§ 14 Abs. 5 UStG). Nachträge nach dem Angebot gehen als Nachtragsangebot oder zusätzlicher Rechnungsentwurf.

Verworfen: Abschläge als normale Rechnungen + Minuspositionen in der Schlussrechnung (Lexware erkennt sie
nicht als Anzahlung → falsche Buchung und Umsatzsteuer, kein automatischer Abzug).

#### 2.2.3 Umschalten der Betriebsart

- Nur Admin, mit Bestätigungsdialog; gesperrt, solange Übertragungen offen (`wartend`/`sendet`) sind.
- A → B: vorhandene Angebote/Rechnungen bleiben gespeichert und für Admins **nur lesend** abrufbar
  (Aufbewahrung GoBD, Sicherungen); B → A: Modul Rechnungen wird wieder aktiv, Nummernkreis läuft weiter.
- Modus wirkt auf alle Lesestellen der Rechnungsdaten (E-086-Inventur): Projektansicht (Abschläge,
  `script.js` ~5285), Auswertung (bezahlte Rechnungen je Projekt, ~5468, `AuswertungView.vue`),
  Kundenformular (~23540), Mobil, Exporte.

#### 2.2.4 Gemeinsam für beide Modi

- **Abruf statt Webhook:** Instanzen laufen oft hinter NAS/Cloudflare ohne öffentlich erreichbare URL →
  Zahlstatus per Planer (vorhandene `job_status`/`job_laeufe`); Webhook `payment.changed` erst als spätere Option.
- **Als Modul `modules/lexoffice/`** (Skill `modul-anlegen`): eigenes Backend + eigenes JS statt Erweiterung
  von `script.js` (1,2 MB, E-091); in `script.js` nur kleine Einhängepunkte (Rechnungsliste/-formular, Kunde).
- **Modus A überträgt keine Angebote** (nicht buchhaltungsrelevant); in Modus B entstehen Angebote in Lexware.

### 2.3 Ein-/Ausschalten in Verwaltung → Allgemein

- Bereich **Module**: Schalter `modul_lexoffice` „Lexware Office (lexoffice)“ – nur wählbar, wenn
  `modul_rechnungen` aktiv ist; Standard **aus**.
- Bereich **Schnittstellen** (umbenennen in „Schnittstellen (KI, OCI, Lexware)“): Karte „Lexware Office“,
  **nur Admin**, nur sichtbar bei aktivem Modul; oben die Auswahl **„Rechnungen erzeugen in: Baukalkulation /
  Lexware Office“** (2.2). Im Modus B wird der Schalter `modul_rechnungen` gesperrt angezeigt mit Hinweis
  „Rechnungen werden in Lexware Office erzeugt“.
- **Aus** bedeutet: keine Übertragung, kein Abruf, Buttons/Spalten ausgeblendet, Endpunkte antworten 403
  (`modules/lexoffice/backend/Module.php` prüft `modul_lexoffice` wie `aufmass`). Verknüpfungen und API-Key
  **bleiben erhalten** → Wiedereinschalten ohne Dubletten. Schlüssel nur per „Schlüssel löschen“ entfernen.
- Modul **aus** in Modus B: `rechnungenAktiv()` liefert wieder den Wert von `modul_rechnungen` – Angebote/Rechnungen
  der Baukalkulation werden nach Bestätigungsdialog wieder nutzbar; der Lexware-Belegspiegel bleibt lesbar.

### 2.4 Buttons und Anzeigen

| Ort | Element | Wirkung |
|---|---|---|
| Verwaltung → Schnittstellen | **Rechnungen erzeugen in** (Baukalkulation / Lexware) | Betriebsart 2.2, Umschalten nach 2.2.3 |
| 〃 | API-Key (verdeckt, „gesetzt ✓“), **Verbindung testen** | `GET /v1/profile`: Firmenname, Steuerart, Kleinunternehmer; Warnung bei Abweichung zu `firma_kleinunternehmer` |
| 〃 (nur Modus A) | **Erlöskategorie** (Auswahl), getrennt für Normal / §13b / steuerfrei | Liste aus `GET /v1/posting-categories` |
| 〃 (nur Modus B) | Einleitungs-/Schlusstext, Zahlungsbedingung | Vorbelegung der Übertragungsmaske (Darstellung wird in der Maske gewählt) |
| 〃 | Schalter **Automatisch übertragen** (bei Status „offen“ nach Speichern) / **Zahlstatus abgleichen** | Standard: manuell / an |
| 〃 | **Übertragen ab Datum** | verhindert Doppelbuchung bereits manuell in Lexware erfasster Altrechnungen |
| 〃 | **Kunden abgleichen** (Vorschau → bestätigen), **Jetzt Zahlstatus abrufen**, letzter Lauf + Fehlerliste | Erstverknüpfung per E-Mail/Name ohne Dubletten; Lauf über Job-Auftrag |
| Rechnungsliste (Modus A) | Spalte/Badge **Lexware**: – / ⏳ / ✓ übertragen / ⚠ Fehler; Filter „nicht übertragen“ | Überblick |
| 〃 (Modus A) | Mehrfachauswahl **An Lexware übertragen** | Job-Auftrag, gedrosselt auf ≤ 1,5 Anfr./s |
| Rechnungsformular (Modus A) | **An Lexware übertragen**, nach Erfolg **In Lexware öffnen** (Permalink) | Einzelbeleg |
| Projekt (Modus B) | **An Lexware übertragen** → Übertragungsmaske (Auswahl → Vorschau, 2.2.1) | Angebot / Rechnungsentwurf |
| 〃 (Modus B) | Karte **Lexware-Belege**: Angebote, Entwürfe, Rechnungen, Abschläge, Schlussrechnung mit Status/Betrag/bezahlt am, je **In Lexware öffnen**; **Jetzt abgleichen** | Ersetzt die bisherige Rechnungs-/Abschlagsanzeige im Projekt |
| 〃 (Modus B) | Abschlagsplan mit **Abschlag in Lexware erstellen** (Permalink zum Angebot), Haken „erstellt/bezahlt“ aus Abgleich | 2.2.2 |
| Positionslisten (Modus B) | Kennzeichen „abgerechnet in RE …“ an Material/Arbeitszeit/Pauschale | Teilabrechnung sichtbar |
| Kundenformular | Badge verknüpft, **In Lexware öffnen**, **Verknüpfung lösen** (Admin) | – |
| Überwachung | Meldung bei dauerhafter Störung (Schlüssel ungültig, 401/403) | vorhandene Outbox |

### 2.5 Zusätzlich zu erfassende Informationen

| Objekt | Feld | Grund |
|---|---|---|
| Rechnung | **Leistungsdatum / Leistungszeitraum (von–bis)** | Pflichtangabe § 14 Abs. 4 Nr. 6 UStG, fehlt bisher; Lexware-Beleg und ZUGFeRD nutzen es mit |
| Rechnung | **Bezahlt am** (aus Lexware, sonst manuell) | Zahlstatus mit Datum, Auswertung |
| Rechnung (Modus A) | **Festgeschrieben** (nach Übertragung) | Änderungen danach nur per Storno/Gutschrift (GoBD) |
| Projekt | **§13b-Bauleistung** (Leistungsempfänger ist Bauleistender) | Steuerart `constructionService13b`, beide Modi |
| Projekt (Modus B) | **Abschlagsplan** (Bezeichnung, % oder Betrag, Fälligkeit) statt eigener Abschlagsrechnungen | 2.2.2 |
| Position (Modus B) | **abgerechnet in** (Lexware-Beleg-ID je Material/Arbeitszeit/Pauschale) | Teilabrechnung ohne Doppelungen |
| Pauschale/Material | optional **Lexware-Artikel-ID** (später, `/v1/articles`) | nur falls Artikelstamm in Lexware gewünscht (G1-Frage 8) |
| Kunde | **Land** (Standard DE), **USt-IdNr.** | Lexware-Kontakt braucht `countryCode`; §13b/EU-Fälle |
| Kunde | optional **Leitweg-ID** | öffentliche Auftraggeber (XRechnung), auch für ZUGFeRD nutzbar |
| Einstellungen | `lexoffice_api_key` (geheim), Kategorien, Auto-Übertragung, Zahlstatus-Abgleich, Startdatum | s. 2.4 |

### 2.6 Daten und Schnittstellen-Vertrag

- **Migration** `…_create_lexoffice_tables.php` (SQLite-DDL, `PgsqlDialect`):
  `lexoffice_verknuepfung` (`objektTyp` kunde|rechnung, `objektId`, `lexId`, `lexVersion`, `organisationId`,
  `status` wartend|sendet|uebertragen|fehler, `fehlerCode`, `inhaltHash`, `uebertragenAm`, `zahlstatus`,
  `geprueftAm`; UNIQUE(`objektTyp`,`objektId`)); Spalten `rechnungen.leistungsdatum`, `leistungBis`,
  `bezahltAm`, `festgeschrieben`; `kunden.land`, `ustId`, `leitwegId`.
  Modus B zusätzlich `lexoffice_belege` (Spiegel: `lexId` UNIQUE, `belegart` quotation|invoice|downpaymentinvoice|
  creditnote, `nummer`, `datum`, `netto`, `brutto`, `status`, `bezahltAm`, `baustelleId`, `kundeId`,
  `vorgaengerLexId`, `schlussrechnung`, `geprueftAm`) und `lexoffice_abrechnung` (`baustelleId`, `positionArt`
  material|arbeitszeit|pauschale, `positionId`, `lexId`; UNIQUE(`baustelleId`,`positionArt`,`positionId`,`lexId`)).
  Projektfelder `reverseCharge13b`, `abschlagsplan` im Projekt-JSON (kein Schema nötig).
- **Einstellungen** (`Auth::getSettings` Defaults): `modul_lexoffice=false`, `rechnung_erzeugung='baukalkulation'`,
  `lexoffice_api_key` in `SECRET_SETTINGS`, `lexoffice_kategorie_*`, `lexoffice_auto`, `lexoffice_zahlstatus`,
  `lexoffice_ab_datum`, `lexoffice_material_modus`, `lexoffice_arbeitszeit_modus`, `lexoffice_texte`.
- **Zentrale Prüfung** `rechnungenAktiv()` (PHP + JS-Gegenstück) = `modul_rechnungen && !(modul_lexoffice &&
  rechnung_erzeugung === 'lexware')`; alle Rechnungs-Endpunkte und -Ansichten nutzen nur diese Prüfung.
- **Recht** `canLexoffice` (Standard admin, master).
- **Backend** `modules/lexoffice/backend/`: `LexofficeClient` (fester Host, Timeout, Token-Bucket, 429 mit
  `Retry-After`/Backoff, injizierbarer Transport), `LexofficeMapper` (reine Funktionen Kunde→Kontakt,
  Rechnung→Beleg, Projekt→Angebot/Rechnungsentwurf; Summen über `BelegPdfService` bzw. `ProjektPositionen`),
  `LexofficeSync` (erst Claim, dann Senden, dann bedingt markieren), Job `lexoffice_abgleich` (Zahlstatus Modus A,
  Belegspiegel + Abschläge Modus B).
- **Endpunkte (nur POST für Änderungen):** `lexoffice_test`, `lexoffice_kategorien`, `lexoffice_kunden_abgleich`,
  `lexoffice_rechnung_senden` (A), `lexoffice_projekt_vorschau`, `lexoffice_projekt_senden` (B),
  `lexoffice_status`, `lexoffice_abgleich_starten`, `lexoffice_modus_setzen`.
- **Zahlstatus:** nur `offen → bezahlt` automatisch (bedingtes UPDATE), nie `storniert`/Handänderung überschreiben.
- **Fremde Sicherung:** passt `organisationId` nicht zu `/v1/profile`, gelten Verknüpfungen als ungültig
  (kein Schreiben in ein fremdes Lexware-Konto).

### 2.7 Berücksichtigte Erkenntnisse

E-003/E-022 (Claim vor langsamem Aufruf, keine Doppelbelege) · E-010 (Schlüssel per SecretBox) · E-012/E-026
(kein frei einstellbarer Host) · E-014 (keine Ausnahmetexte speichern) · E-015 (POST) · E-027 (Zustandsfelder
nur aus eigenem Pfad) · E-034 (injizierbarer Transport) · E-081 (Anleitung) · E-086 (alle Schreibpfade von
Rechnungen/Kunden gegen Festschreibung prüfen: Desktop, Mobil, Import, Sicherung) · E-088 (alte Clients) ·
E-090–E-095, E-037, E-100 (Tests, Token) · E-099 (Rechte am gespeicherten Datensatz).

### 2.8 Bündel (je Bündel Entwickler → Tester → Reviewer, ein Release für die Serie)

| Bündel | Inhalt | Hauptdateien |
|---|---|---|
| **B0** | Tokenfreier Prüflauf: `scripts/pruefen.ps1` + `make pruefen` (PHPUnit-Filter/Gruppe, `test:js`, PHPStan) → Logdatei in `tests/tmp/`, Ausgabe ≤ 15 Zeilen (grün/rot, fehlgeschlagene Tests), Exit-Code; Leitstand/Tester nutzen nur diesen Aufruf (E-100) | `scripts/`, `Makefile` |
| **B1** | Migration, Einstellungen inkl. `rechnung_erzeugung`, `rechnungenAktiv()`, Recht, Modulgerüst, Client + Fake-Transport, Verbindung testen, Kategorien, Modulschalter + Karte | `migrations/`, `src/Auth.php`, `modules/lexoffice/`, `public/script.js` (Karte), `verwaltung-nav.js` |
| **B2** | Migration `lexoffice_*` + Backup-Fixture (aus B1 verschoben), Kundenfelder, Kontakt-Mapping, Kunden-Erstabgleich, Kunden-Badge (beide Modi) | `migrations/`, Kunden-Handler, `modules/lexoffice/` |
| **B3** | Modus A: Leistungsdatum, Beleg-Mapping, PDF/ZUGFeRD-Anhang, Festschreibung in allen Schreibpfaden, Buttons Liste/Formular, Sammelübertragung | `CrudActions.php`, `BelegPdfService.php`, `ZugferdService.php`, `modules/lexoffice/` |
| **B4** | Modus B: `ProjektPositionen` (auch für Export), Projektdialog mit Vorschau, Angebot/Rechnungsentwurf, Teilabrechnung, §13b-Projektfeld | `ExportActions.php`, neuer Dienst, `modules/lexoffice/`, Einhängepunkt Projektansicht |
| **B5** | Modus B: Belegspiegel, Abschlagsplan + Rückspiegel, Projektkarte „Lexware-Belege“, Umschalten der Betriebsart + E-086-Inventur aller Leser (Projekt, Auswertung, Kunde, Mobil) | `script.js`, `mobile.html`, `AuswertungView.vue`, `modules/lexoffice/` |
| **B6** | Abgleich-Job (Zahlstatus A / Spiegel B), Überwachungsmeldung, Storno → Gutschrift (A) | Jobs, `modules/lexoffice/` |
| B1–B6 | jeweils `public/bedienungsanleitung.html`, Backup-Export/-Import + Fixture | – |

**Serie 1 (jetzt, Modus B – G1-Antwort 2):** B0 → B1 → B2 → B4 → B5 → B6 (nur Abgleich/Spiegel Modus B).
Modus A ist in Serie 1 das heutige Verhalten ohne Übertragung. **Serie 2 (später):** B3 + Zahlstatus/Storno
Modus A aus B6.

### 2.9 Risiken

Doppelbelege bei Zeitüberschreitung nach erfolgreichem POST (→ Claim + vor erneutem Senden per `voucherlist`
nach Belegnummer suchen) · Rundungsdifferenzen PDF ↔ Beleg (→ Summen aus einer Quelle, Fallliste) ·
Altrechnungen doppelt in Lexware (→ Startdatum) · Tarif ohne Public API (→ G1-Frage) · Rate-Limit bei
Sammelübertragung (→ Job, Drossel) · Festschreibung bricht bestehende Bearbeitungsabläufe (→ E-086-Inventur) ·
Modus B: Abschläge nur in Lexware anlegbar (API-Grenze, 2.2.2) · Zuordnung Lexware-Beleg → Projekt nur über
die Belegkette des übertragenen Angebots/Entwurfs; in Lexware frei angelegte Belege bleiben ohne Projekt
(Anzeige „nicht zugeordnet“, manuell zuordnen) · Arbeitszeit-Beschreibungen dürfen keine Personaldaten außer
Kürzel nach Lexware tragen · Modusumschaltung bei offenen Vorgängen (→ Sperre 2.2.3).

### 2.10 Abnahmekriterien

1. Modul aus (Standard): keine Lexware-Buttons/-Spalten, Endpunkte 403, kein Abruf im Planer.
2. Modul an, ohne Schlüssel: Karte zeigt „Schlüssel fehlt“; Schlüssel wird verschlüsselt gespeichert, nie ausgeliefert.
3. Verbindung testen zeigt Firmenname/Steuerart; 401 → verständliche Meldung, kein Ausnahmetext.
4. Rechnung übertragen erzeugt genau einen Beleg mit Kontakt, Leistungsdatum, Summen = PDF und angehängter PDF; erneuter Klick erzeugt keinen zweiten Beleg.
5. Nach Übertragung ist die Rechnung festgeschrieben (Desktop, Mobil, Import); Storno erzeugt Gutschrift.
6. Zahlstatus-Job setzt `offen → bezahlt` + `bezahltAm`, überschreibt nie `storniert`; 429 wird nachgeholt.
7. Ausschalten/Einschalten erhält Verknüpfungen; fremde Sicherung (andere `organisationId`) überträgt nicht.
8. Alte Sicherungen bleiben importierbar; SQLite und PostgreSQL grün; Anleitung ergänzt.
9. Modus B: Menü/Knöpfe/Endpunkte für Angebote und Rechnungen deaktiviert (Desktop + Mobil, 403); Altbelege für Admin nur lesend; Umschalten bei offenen Übertragungen gesperrt; B → A stellt alles wieder her.
10. Modus B: Übertragungsmaske bietet je Übertragung Belegart, Gruppen, Darstellung Material/Arbeitszeit/Pauschalen und Positionshäkchen, merkt die letzte Auswahl je Projekt; die Vorschau entspricht dem späteren Lexware-Beleg (Netto/USt/Brutto auf den Cent wie Projekt-Export) für jede Darstellungsoption; Kleinunternehmer/§13b richtig.
11. Modus B: zweiter Rechnungsentwurf enthält nur noch nicht abgerechnete Positionen; ein in Lexware verworfener Entwurf gibt sie beim Abgleich frei.
12. Modus B: in Lexware erzeugte Abschläge/Schlussrechnung erscheinen nach dem Abgleich im Projekt (Nummer, Betrag, bezahlt am) und haken den Abschlagsplan ab; Auswertung nutzt diese Erlöse.

### 2.11 Testmatrix (tokenfrei: ohne echte API, ohne Klicktests)

| Kriterium | Test |
|---|---|
| 1–3, 7 | `tests/Api/LexofficeActionsTest.php` (Fake-Transport per Einstellung im Testmodus) |
| 4, 6 | `tests/Unit/Lexoffice/LexofficeSyncTest.php` mit aufgezeichneten Antworten `tests/fixtures/lexoffice/*.json` (200, 401, 429, Timeout nach POST) |
| 4 (Summen) | `tests/Unit/Lexoffice/LexofficeMapperTest.php` über Fallliste `tests/fixtures/lexoffice/faelle.json` (Rabatt, Gruppen, Kleinunternehmer, §13b, Brutto/Netto) |
| 5, 8 | bestehende Rechnungs-/Backup-Tests + neue Fixture |
| 9 | `tests/Api/LexofficeModusTest.php` (Rechnungs-Endpunkte 403 in Modus B, Umschaltsperre) + `tests/js/rechnungen-aktiv.test.mjs` (gemeinsame Fallliste PHP ↔ JS, E-036) |
| 10, 11 | `tests/Unit/ProjektPositionenTest.php` + `LexofficeMapperTest` über Fallliste `tests/fixtures/lexoffice/projekte.json` (Material mit Aufschlag/Rabatt/`vkDirekt`, Arbeitszeit mehrere Sätze, Pauschalen, Teilabrechnung) |
| 12 | `LexofficeSyncTest` mit aufgezeichneter `voucherlist`/`down-payment-invoices`/Schlussrechnung |

**Nur Echtkonto, kein Testkonto (G1-Antwort 1):**
- Kein automatischer Test und kein Agent ruft jemals die echte API auf; Antworten stammen aus den Beispielen
  der offiziellen Doku (`tests/fixtures/lexoffice/*.json`).
- Aus Modus B werden nur **Entwürfe** (Angebot, Rechnung ohne `finalize`) angelegt – keine Buchungswirkung,
  in Lexware löschbar. Ein `finalize` sendet die Baukalkulation nie.
- Erste echte Probe nur durch den Nutzer: **Verbindung testen** (nur lesend), danach ein Angebotsentwurf eines
  Testprojekts, in Lexware prüfen und löschen. Optional `bin/console lexoffice:aufzeichnen` (nur GET, Namen/
  Adressen/Beträge anonymisiert) zum Ergänzen der Fixtures – nur auf ausdrücklichen Wunsch.

### 2.12 Offene Fragen (G1) – Antworten 2026-11-26

| Nr | Frage | Antwort / Entscheidung |
|---|---|---|
| 1 | Public API, Testkonto? | API ja, **nur aktives Echtkonto** → Schutzregeln 2.11 |
| 2 | Welcher Modus zuerst? | **Modus B – Übertragung direkt aus Projekten** → Serie 1 (2.8) |
| 3 | Modus A: Startdatum, automatisch? | offen bis Serie 2 |
| 4 | Modus A: Storno als Gutschrift? | offen bis Serie 2 |
| 5 | Abschläge wie 2.2.2? | **in Ordnung** |
| 6 | Darstellung Material/Arbeitszeit? | **in einer zusätzlichen Maske je Übertragung wählbar** → Übertragungsmaske 2.2.1 |
| 7 | Lexware-Kundennummer zurückschreiben? | Vorgabe Leitstand: nein, nur Anzeige im Kunden-Badge (Kundennummernkreis der Baukalkulation bleibt) |
| 8 | Lexware-Artikel? | Vorgabe Leitstand: nicht in Serie 1 |

**Freigabe G1:** ☑ durch Nutzer am 2026-11-26 (Start B0 + B1)

## 3. Umsetzung

**B0 (Leitstand):** `scripts/pruefen.mjs` (eine Node-Implementierung für Windows/Linux statt `.ps1` + Makefile, E-098),
`make pruefen`, `npm run pruefen`; Logs `tests/tmp/pruefen/` (selbst-ignoriert), Ausgabe ≤ 15 Zeilen, Exit-Code;
PostgreSQL-Container wie `test-pgsql` (Portprüfung E-064). AGENTS.md + docs/entwicklung.md ergänzt.

**B1 (Leitstand):** `src/Services/RechnungsModus.php` + `public/rechnungen-modus.js` (gemeinsame Fallliste);
`modules/lexoffice/` (Manifest, `Module.php`: `status`, `verbindung_testen`, `modus_setzen`; `LexofficeClient`
mit Drossel 0,55 s/429-Retry/festen Meldungen; `CurlTransport` fester Host, `FakeTransport` nur per
`BK_LEXOFFICE_FAKE_DIR`); `Auth`: Defaults, `SECRET_SETTINGS` + `lexoffice_api_key`, `SERVER_SETTINGS`,
`setzeEinstellung()`, Recht `canLexoffice`; Sperren in `CrudActions` (save/delete) und `EmailActions`;
`script.js` (Modulschalter, Karte, Rechnungsstellen, Rechteliste), `index.html`/`sw.js`, Anleitung, CHANGELOG.

**Abweichungen vom Plan (bewusst):**
- Migration `lexoffice_*` + Backup-Fixture → **B2** (B1 braucht keine Tabelle; Fixture nur einmal erneuern).
- Erlöskategorien (`/v1/posting-categories`) → **Serie 2** (nur Modus A).
- Umschaltsperre bei offenen Übertragungen → **B4** (vorher gibt es keine Übertragungen).
- AK 9 präzisiert: In Betriebsart Lexware bleibt **Lesen** serverseitig rechtebasiert (Auswertung und Kundenformular
  lesen `list_rechnungen`); Menü nur für Admins; **Status ändern** nur Admin, nur `{id,typ,status}` (sonst 409).
- Sperren nutzen `RechnungsModus::inLexware()` – `modul_rechnungen = false` verhält sich serverseitig wie bisher.

**B2 (Leitstand, 2026-11-27):** Migration `20261127000000_create_lexoffice_tables` (alle drei Tabellen aus 2.6 +
Kundenfelder); `saveKunde` übernimmt Land/USt-IdNr./Leitweg-ID nur, wenn gesendet (E-088); Verknüpfung wird beim
(DSGVO-)Löschen entfernt. `LexofficeMapper` (Kontakt, Zuordnung per E-Mail → Name → PLZ), `LexofficeSync`
(Kontoprüfung `/v1/profile` vor jedem Schreiben, Claim `sendet` + Dublettensuche vor `POST /v1/contacts`, Timeout →
`fehler`, sonst Claim frei), Aktionen `kunden_status`, `kunden_abgleich_vorschau`, `kunden_abgleich_ausfuehren`
(≤ 10 je Aufruf), `kunde_uebertragen`, `kunde_verknuepfung_loesen`; Frontend `public/modules/lexoffice/`.
**Entscheidungen:** Lexware-Kontakte werden nie geändert (kein PUT) – Aktualisieren bei Bedarf in B4;
Leitweg-ID geht erst mit dem Beleg (`buyerReference`, B4) nach Lexware; Backup-Fixture beim Release (Version im Namen).
**Abweichungen vom Plan (bewusst):** `rechnungen.leistungsdatum/leistungBis/bezahltAm/festgeschrieben` erst mit
Modus A (Serie 2); Kunden-Abgleich synchron in Zehnerblöcken (Browser ruft blockweise auf) statt Job-Auftrag –
reicht für den Erstabgleich und braucht keinen Planer; Projekt-Sammelläufe in B4/B6 als Job.
**B2 Korrekturrunde 1:** einheitlicher Claim mit Token für Anlegen und Verknüpfen, bedingter Abschluss, `unklar` bei
nicht durchsuchbaren Kunden nach Zeitüberschreitung, Log ohne Personendaten, `zahlstatus`, DSGVO-Hinweis, Lösen lokal,
Rechte `canReadKunden`/`canWriteKunden`.

**B4 (Leitstand, 2026-11-27):** `src/Services/ProjektPositionen.php` (eine Rechenquelle inkl. `zeilenbetrag()` für
IN-FORM-Export und Lexware, Export erstmals getestet), `LexofficeBeleg` (rein: Darstellungen, Steuerart, Leistungszeitraum,
Anfrage-JSON; EP 4 Stellen, Gutschriften als negativer EP), `LexofficeProjekt` (Vorschau ohne API, Senden mit Sperre je
Projekt – unklar nur nach Beleg-POST, Vermerk `lexoffice_belege`/`lexoffice_abrechnung` nur für Rechnungsentwürfe,
`voided` gibt frei), Aktionen `projekt_vorschau`, `projekt_senden`, `projekt_belege`, Umschaltsperre; Maske in
`public/modules/lexoffice/lexoffice.js`, Aktionsmenü „An Lexware übertragen“.
**Abweichungen (bewusst):** Auswahl je Projekt im Browser (`localStorage`) statt im Projekt-JSON (keine Server-Schreibzugriffe
aufs Projekt, E-085); § 13b als Projektfeld `reverseCharge13b`, gesetzt aus der Maske über den normalen Client-Speicherweg;
Einleitung/Schlusstext je Übertragung, Vorbelegung aus der Verwaltung → später; zusammengefasste Zeilen können um Cent von der
Summe der Einzelpositionen abweichen (Vorschau weist darauf hin). **Für B5:** in Lexware gelöschte Entwürfe beim Abgleich als
`voided` markieren (AK 11).

**B5 (Leitstand, 2026-11-27):** `LexofficeProjekt::abgleichen()` (nur lesend: GET je Beleg, `relatedVouchers` → neue
Abschlags-/Schlussrechnungen, 404 → `geloescht`, `paidDate` über `/v1/payments` nur einmal, Schlussrechnung mit
`claimedGrossAmount`), Aktionen `projekt_abgleichen` und `bezahlt_summen` (nur lokaler Spiegel, `canSeePrices`, je Projekt
`canSeeBaustelle`); Frontend: Lexware-Zeilen in der Abschlagsliste; alle Leser erhaltener Beträge (Projekt-`recalc()`,
Übersicht, Exporte, Auswertung) über `projektAbschlagSumme()`/`projektLexwareBezahlt()` in `script.js` (E-086).
**B5 Korrekturrunde 1:** Zeitbudget/Obergrenze mit `vollstaendig`, 404 gibt nur bisherige Entwürfe frei (sonst Fehler),
Entdeckung per `ON CONFLICT DO NOTHING`, Zahlungsabruf fehlertolerant, Belege bleiben bei Ladefehlern erhalten,
Anleitung warnt vor Doppelerfassung. Die Vue-`AuswertungView` ist inaktiv und wurde nicht angepasst.
**Bewusst (R2 Hinweis):** bezahlte Lexware-Beträge zählen auch nach Rückwechsel in Betriebsart A weiter – es sind echte
Zahlungseingänge; bereits nach Lexware übertragene Positionen dürfen danach nicht erneut in der Baukalkulation berechnet werden.

**B6 (Leitstand, 2026-11-27):** `modules/lexoffice/backend/LexofficeJob.php` – Planer-Schritt `lexoffice` (Kern lädt die
Moduldatei nur, wenn vorhanden und lizenziert), höchstens stündlich, aktiv in jeder Betriebsart Lexware (fehlt die
Verbindungsprüfung, meldet der Lauf `LEXWARE_ZUGANG` statt still zu pausieren); Kontoprüfung zu Beginn, Projekte mit Belegen
außerhalb `ENDSTATUS` bzw. bezahlt ohne Zahlungsdatum (`offenSql`; angenommenes Angebot nur ohne gültige
Schlussrechnung), älteste Prüfung zuerst, Budget 40 s, `abgleichen(nurOffene)`. Überwachung: Job `lexoffice`, Zugriff
verweigert (`LEXWARE_ZUGANG`) sofort, sonst (`LEXWARE_ABGLEICH`) nach 24 h; kein Ausbleiben-Alarm; Laufprotokoll nur bei
Zustandswechsel. Anzeige über die vorhandene Überwachung der Serverjobs (keine eigene Oberfläche), Hinweis in der Lexware-Karte.
**Nicht in B6 (Serie 2, Modus A):** Zahlstatus-Abgleich für Rechnungen der Baukalkulation, Storno → Gutschrift.
**Später (R2 Hinweis H2):** bezahlter Beleg ohne `paidDate` wird stündlich erneut abgefragt (2 GET) – bei Bedarf deckeln.
**Abweichung (bewusst, Nutzer informiert):** kein eigener Abschlagsplan mit %/Fälligkeit – die vorhandene Abschlagsliste bleibt
für außerhalb erstellte Rechnungen, Lexware-Abschläge erscheinen gespiegelt (sonst Doppelzählung in allen Summen).
**Offen:** Mobil-Ansicht und Kundenformular zeigen Lexware-Belege/-Zahlungen noch nicht (nur Desktop-Projekt); Abgleich
aller Projekte automatisch → B6.

## 4. Tests

| Abnahmekriterium | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|
| 1 Modul aus → 403 | `tests/Api/LexofficeActionsTest::testModulAusSperrtAlleAktionen` | grün | grün | – |
| 2 Schlüssel verschlüsselt, nie ausgeliefert; Wechsel verwirft Prüfung | `…::testSchluesselWirdVerschluesseltGespeichertUndNieAusgeliefert`, `…::testSchluesselwechselVerwirftDieAlteVerbindungspruefung` | grün | grün | – |
| 3 Verbindung testen, Fehler ohne Details | `…::testVerbindungTestenLiefertProfilUndSpeichertEs`, `…::testFehlerDerApiWerdenOhneDetailsGemeldet` (401/429/404 aus Aufzeichnungen) | grün | grün | – |
| 7 Aus-/Einschalten erhält Einstellungen | `…::testBetriebsartLexwareSperrtNeueBelegeUndLaesstNurStatusZu` | grün | grün | – |
| 9 Betriebsart Lexware: Sperren, Archiv, nur Admin-Status, alte Clients 409 | `…::testBetriebsartLexware…`, `…::testArchivLaesstNurAdminsDenStatusAendern`, `…::testAenderndeAktionenNurAdminUndPost` | grün | grün | – |
| 9 Entscheidungslogik PHP = JS | `tests/Unit/RechnungsModusTest` + `tests/js/rechnungen-modus.test.mjs` (Fallliste `tests/fixtures/lexoffice/rechnungen-modus.json`), Einbindung in index.html/sw.js | grün | grün | grün |
| 11 Gelöschter Entwurf / Storno gibt Positionen frei; fertiger Beleg 404 → Fehler | `tests/Api/LexofficeProjektTest::testAbgleichSpiegeltBelegketteUndGibtGeloeschteEntwuerfeFrei`, `…::testOhnePreisrechtKeineUebertragungUndStornoGibtPositionenFrei` | grün | grün | – |
| 12 Abschläge/Schlussrechnung gespiegelt, bezahlt zählt (nur `bezahlt_summen`, ohne Preisrecht 403) | `…::testAbgleichSpiegeltBelegketteUndGibtGeloeschteEntwuerfeFrei`, `…::testNurInBetriebsartLexwareUndMitRechten` | grün | grün | – |
| B6 Automatischer Abgleich: nur Betriebsart Lexware, stündlich, nur offene Belege, nie schreibend, Zugriffsfehler → Systemmeldung | `…::testPlanerGleichtStuendlichAbUndMeldetZugangsfehlerSofort` (CLI `system:planer`) | grün | grün | – |
| B0 Prüfskript | `tests/js/pruefen.test.mjs` | – | – | grün |

Lauf: `node scripts/pruefen.mjs --filter 'Lexoffice|RechnungsModus|Rechnung|Beleg|Email|AuthTest|Settings|Permission|Module' --pgsql --stan --versionen`
→ 97 Tests SQLite + PostgreSQL, 55 JS, PHPStan 0, Versionen ok; php-cs-fixer 0 Dateien. Gesamtläufe vor Release (E-092).
`EndpunktInventarTest` (aus dem nicht committeten AP-20261119-testabdeckung-klicktests): eigene Lücken behoben
(Test nutzt `->module('lexoffice', …)`, `send_rechnung` und `vor_routen.module` aus `endpunkt-luecken.json` entfernt);
bleibt rot wegen 17 **vorbestehender** veralteter Einträge jenes AP (z. B. `set_visibility`, `send_vde`) – nicht angefasst.

## 5. Review

- **B2 R1:** CHANGES_REQUESTED – 2 Major (Claim nicht in allen Schreibpfaden, Claim-Pfade ungetestet), 6 Minor, 2 Hinweise.
  **R2 (nur Befunde):** APPROVE; 2 Hinweise (Test abgelaufener Claim, Knopf nur mit `canWriteKunden`) sofort behoben.
  Tests B2: `LexofficeKundenTest` (Felder/alte Clients, Vorschau, Abgleich ohne Dubletten, Timeout/Claim/unklar, Fremdkonto,
  Rechte), `LexofficeMapperTest` (Falllisten `kontakte.json`, `zuordnung.json`) – 81 gezielte Tests SQLite + PostgreSQL grün.
- **B4 R1:** CHANGES_REQUESTED – 4 Major (Rundung Export ≠ Lexware, Gutschriften verworfen, § 13b nur lokal, Beleg-Fehlerpfade
  ungetestet), 4 Minor, 1 Hinweis. **R2 (nur Befunde):** APPROVE; 3 Hinweise (Gruppierung nach 4-stelligem EP, Zeilen mit
  Menge 0, Export-EP im CHANGELOG) sofort behoben. Tests B4: `LexofficeBelegTest` (Fallliste `projekte.json`, 5 Fälle),
  `LexofficeProjektTest` (Betriebsart/Rechte, Angebot ohne Abrechnung, Teilabrechnung, Sperre/unklar, Beleg 503/406,
  ohne Preisrecht, `voided`, § 13b, IN-FORM-Export) – 62 gezielte Tests SQLite + PostgreSQL grün.
- **B5 R1:** CHANGES_REQUESTED – 3 Major (bezahlte Lexware-Beträge nur in `recalc()`, nicht in Übersicht/Exporten/Auswertung;
  Summen nur aus dem Belege-Cache des offenen Projekts), 4 Minor (Ladefehler leert Belege, 404 gibt fertige Belege frei, kein
  Zeitbudget, Doppelerfassung undokumentiert), 2 Hinweise. **R2 (nur Befunde):** APPROVE; R1 Minor (sichtbare Übersicht/
  Auswertung nach Laden neu zeichnen), R2/R3 Hinweise sofort behoben bzw. dokumentiert.
  Tests B5: `LexofficeProjektTest::testAbgleichSpiegeltBelegketteUndGibtGeloeschteEntwuerfeFrei` (Belegkette, 404 nur Entwurf,
  `bezahlt_summen`), Rechte 403 – 63 gezielte Tests SQLite + PostgreSQL grün.
- **B6 R1:** APPROVE mit 5 Minor (nur `LexofficeFehler` gefangen → Dauerfehler unsichtbar; kein Abbruch bei 429/nicht
  erreichbar; Aktivität an geprüftes Profil gekoppelt → Job pausiert still nach Schlüsselwechsel; Zahlungsdatum nie
  nachgeholt; stornierte Schlussrechnung blockierte Angebot) und 3 Hinweisen (Schreibprüfung im Test, Testlücken
  Entwarnung/ABGLEICH, Anleitung Ampel) – alle sofort behoben. Tests B6:
  `LexofficeProjektTest::testPlanerGleichtStuendlichAbUndMeldetZugangsfehlerSofort` (CLI `system:planer`, inaktiv,
  stündlich, nur offene Belege, Zahlungsdatum nachholen, ABGLEICH nach 24 h, Entwarnung, ZUGANG sofort, nie schreibend,
  Projektauswahl) – gezielte Läufe SQLite + PostgreSQL grün, PHPStan 0.

- **R1** (Reviewer-Agent): CHANGES_REQUESTED – 1 Major (Profil nach Schlüsselwechsel), 9 Minor (Lesen serverseitig,
  alte Clients, Meldung je Ursache, Gesamtstand nach langem Aufruf, Plan 2.3, Exit-Code/Ergebniszeile `pruefen`, AP-Doku, Testlücken).
- **R2** (nur Befunde, E-094): APPROVE; 2 Hinweise (Profil eines inzwischen ersetzten Schlüssels, `nurStatus` im Frontend)
  sofort behoben (`setzeEinstellung(…, nurWennGleich)`, `bkRechnungenInLexware`), Tests erneut grün.

## 6. Lernpunkte

- E-037, E-100 (Vorgabe Nutzer 2026-11-26: Prüfläufe und Tests tokenfrei) – E-100 in AGENTS.md (auf Wunsch).
- E-003 2. Auftreten → php-backend.instructions.md. Kandidaten: abhängige Einstellungen beim Ändern der Grundlage
  zurücksetzen; Werkzeug-Exit-Code statt Logtext auswerten.
