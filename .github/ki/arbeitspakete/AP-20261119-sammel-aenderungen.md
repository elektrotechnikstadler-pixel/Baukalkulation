# AP-20261119-sammel-aenderungen: Sammelplan Änderungen vom 2026-11-19

| Feld | Wert |
|---|---|
| Status | in Umsetzung (B1 + B2a abgeschlossen; B2b–B2d, B3–B9 offen) |
| Typ | Feature · Bugfix · Sicherheit (Sammelplan) |
| Testläufe rot | 1 |
| Review-Runden | 2 |
| Commit | 4e8997c (B1), 95fbc02 (B2a) |

## 1. Auftrag

Nutzerauftrag vom 2026-11-19 (wörtlich):

> Änderungen:
> - Seitenleiste Menü und Projekte eigene Scrollbar (wird Menü zu umfangreich, sieht man die Projekte nicht mehr)
>
> Baustellen/Projekte:
> - Marge/Aufschlag separat je Artikel und gesamt aufschlüsseln
> - Je Position soll der globale Aufschlag deaktiviert werden können
> - Arbeitszeit: unproduktive Stunden sollen nicht in der Gesamtsumme Arbeitszeit und Projekt gesamt hinzugezählt werden
> - Dateien und Fotos: der Dateiname wird vorne abgeschnitten, dies fixen
>
> Gesamtübersicht:
> - Der Kunde soll in einer separaten Spalte angezeigt werden, dies soll auch über den Filter gefiltert werden können
>
> Stundenerfassung (alle Versionen: mobile, mobile light und desktop):
> - Beim Filtern sollen die Baustellen mit Kunden angezeigt werden. Bessere Suchbarkeit der Projekte
>
> KI-Scan (Lager, Projekte und allgemein):
> - Die Modelle von Gemini sollen abgerufen und eingestellt werden können (über dem Feld für den API-Key)
> - Verbesserung der Belegerkennung (z. B. werden Preiseinheiten als Einheit erkannt …)
>
> Allgemein:
> - Eingaben optimieren und smoother machen (z. B. per Cursor auf die Eingabestelle setzen, wo verbessert werden soll; aktuell wird der Cursor am Zeilenanfang der z. B. Position gesetzt) (jede Eingabezelle betroffen!)
> - Im Klicktest prüfen, welche Fenster unabhängig voneinander aufgehen, aber beim Wechsel nicht zuverlässig wechseln (weiteres Fenster wird unten angehängt)
> - Benutzerverwaltung wie Verwaltung allgemein eingebettet anzeigen, ist übersichtlicher
> - Zugriffsrechtevergabe prüfen und härten
> - Global und allgemein die Filterfunktion nochmal testen und härten
> - PDF/Exporte allgemein: alles prüfen, ob Funktionen vorhanden und korrekt
> - Rechnungen und Angebote als separates Modul separieren und optimieren
> - Allgemein das Programm härten

## 2. Plan

### 2.1 Ist-Analyse je Punkt (Fundstellen geprüft 2026-11-19, Zeilen ≈)

| # | Punkt | Befund (belegt) |
|---|---|---|
| 1 | Sidebar-Scrollbars | `public/index.html:120–174`: `#appSidebar` > `.sidebar-scrollable` enthält `#secMenu` **und** `#secProjekte`/`#baustellenList` gemeinsam. `public/style.css:307–313` `.sidebar-scrollable { overflow-y:auto }` (eine gemeinsame Scrollfläche); `.baustellen-list` (≈332–336, 1358–1364) hat zwar `overflow-y:auto; flex:1`, aber keinen getrennten, gleichrangigen Scrollbereich für das Menü → langes Menü schiebt Projekte aus dem Sichtfeld. |
| 2 | Marge/Aufschlag aufschlüsseln | `public/script.js:2194–2202` `matVk()`: VK = EK + Positionsaufschlag + `b.materialAufschlagGlobal` − Rabatt; `vkDirekt` umgeht beides. Summen in `recalc()` ≈5438–5491 (`matSum`, `matEkSum`, Gewinn = VK−EK). Eine Aufschlags-/Margen-**Aufschlüsselung** je Artikel oder als Summe existiert nicht (nur Gewinn gesamt). PHP-Parität: `src/Handlers/ExportActions.php:49–59` und `src/Handlers/BaustelleActions.php:744–754` rechnen dieselbe Formel nach. |
| 3 | Globalen Aufschlag je Position deaktivieren | Positions-Flag existiert nur als `vkDirekt` (`script.js:6650–6654`), das Positions- **und** Globalaufschlag ausnimmt. Ein Flag „nur globalen Aufschlag aus“ gibt es nicht. Einstellung `materialAufschlagGlobal` liegt am Projekt (`script.js:2618–2623`, `setMatAufschlagGlobal` ≈4249–4255). |
| 4 | Unproduktive Stunden | Flag `r.unproduktiv` an der Projekt-Arbeitszeitposition (`script.js:4755` Checkbox, `setAz` ≈4564–4569). `recalc()` ≈5440–5448: `azHoursTotal` und `azSum` (Betrag) summieren **alle** Zeilen, nur `azHoursProduktiv` filtert `!r.unproduktiv`. Tabellenkopf-Tooltip (≈2699) verspricht bereits „Unp. zählt nicht in Projekt-Gesamtstunden“ → Widerspruch zum Ist. Kein `unproduktiv` in `mobile.html` oder `src/` (Archiv-Auswertung `BaustelleActions.php:744–762` ignoriert das Flag). |
| 5 | Dateiname vorne abgeschnitten | `public/script.js:14778` (`renderCard` in `loadBaustelleFiles`-Kontext): `displayName = f.alias \|\| f.name.substring(20) \|\| f.name` – `substring(20)` schneidet die **ersten 20 Zeichen** ab (Bug; gemeint war wohl `substring(0, 20)` bzw. Ellipsis). CSS `.file-card-name` (`style.css:2071–2079`) nur `white-space:nowrap`, kein `rtl`-Trick. |
| 6 | Gesamtübersicht Kundenspalte | `script.js:3021` `renderUebersicht()`: Spalten Projekt-Nr., Baustelle, Material, Arbeitszeit, Pauschalen, Gesamt, Abschläge, Offen, Gewinn – **kein Kunde**; `rows` (≈3031–3054) ohne Kundenfeld. Kunde liegt als `kundeId` am Projekt (`script.js:1597`, Unterprojekte ≈1458). `filterUebersichtRows(q)` ≈3254–3260 filtert `tr.textContent`; Summen über `public/uebersicht-summen.js` `bkUebersichtSummen(rows, sichtbareIds)`. |
| 7 | Stundenerfassung Projektauswahl | Desktop: generisches Dropdown `bsDdHtml()` `script.js:7950`, Suche nur `b.name`/`b.projektNr` (≈7977) – kein Kunde. Mobile: `mobile.html:5055` `openSeBaustellePicker()` / `:5075` `_renderSeBsPickerList(q)` – Suche Name/Projekt-Nr., kein Kunde. Mobile Light: `mobile_light.html:650` `_renderLightBsPickerList(q)` sucht bereits tokenweise inkl. Kundenname (≈656–661), zeigt ihn aber nicht an (≈676). Datenquelle `action=load` → `src/DataService.php:14–20` liefert `kundeId` je Baustelle; Kundenname muss über die Kundenliste aufgelöst werden. |
| 8 | KI-Scan Gemini-Modelle | `src/Handlers/AiActions.php:23–29` `scanMaterial`: Key-Einstellung `gemini_api_key`, Modell aus Einstellung `gemini_model`, Standard `gemini-2.5-flash-lite`; HTTP-Aufruf direkt in `AiActions::callGemini` (:149), kein Service. UI: `script.js:11581–11602` (Modell-Auswahl + freies Feld + API-Key). Kein Abruf der verfügbaren Modelle von Google. Aufrufer: `script.js:3444`, `mobile.html:3668`, Route `ai_scan_material` `public/api.php:427`; im Lager-Modul selbst keine eigene Fundstelle (Scan läuft über die Haupt-UI). |
| 9 | Belegerkennung | Prompt `AiActions.php:52–77`: fordert Menge und Einheit, kennt aber **kein separates Feld Preiseinheit/Preisbasis je Position** (z. B. „€/100 Stk“) → Preiseinheiten landen als Einheit. |
| 10 | Cursor in Eingabezellen | **Ursache nicht belegt:** kein `contenteditable`, kein `setSelectionRange`/klick-ersetzendes Input in den Positions-/Artikeltabellen gefunden (Treffer ≈6854/6935 gehören zum Excel-Import). Reproduktion + Ursachenanalyse ist erster Schritt des Bündels B7 (keine Annahme raten). |
| 11 | Fensterwechsel | Zentrale Umschaltung über `show(id)`/`hide(id)` (`script.js:7933–7934`, Klasse `.hidden`) und je Ansicht eigene `show…()`-Funktionen (z. B. `showUebersicht()` ≈2993–3018 blendet andere Hauptansichten aus). Ein Mechanismus, der Ansichten „unten anhängt“, wurde nicht gefunden → Befunde kommen aus den Klicktests (AP-20261119-testabdeckung-klicktests), nicht aus Code-Vermutung. |
| 12 | Benutzerverwaltung | Heute Modal: `index.html:101` `#btnUserMgmt` → `openUserModal()` (`script.js:10539–10548`), Markup `#userMgmtModal` `index.html:514–565`. Vergleich: allgemeine Verwaltung nutzt `public/verwaltung-nav.js` (Bereichsliste + Suche, eingebettete Ansicht). |
| 13 | Zugriffsrechte | `src/Auth.php:16/44` `requireAuth`/`requireRole`; Rechte-JSON in `permissions_config` (`Auth::loadPermissions/savePermissions/canDo` :351–378, 414); `AdminActions::get/setPermissions` (:17–29). Prüfung je Aktion, **nicht** zentral im Router – Beispiel-Lücke: `AiActions::scanMaterial` nur `requireAuth()` (:25). |
| 14 | Filterfunktion global | Bekannte Filter: Gesamtübersicht (`filterUebersichtRows`), Baustellen-Dropdowns (`bsDdHtml`), mobile Picker, Verwaltungssuche (`verwaltung-nav.js`). Härtung = Tests + Typ-Normalisierung (E-096), Befunde über Klicktests. |
| 15 | PDF/Exporte | Serverseitig: `ExportActions` (exportInformCsv, subunternehmerReport, generateZugferd :200 – nur `typ=rechnung` :220–223 –, renderBelegPdf :256 via `BelegPdfService`), `EmailActions` (sendVde :60, sendRechnung :113, sendAngebot :174, sendDin1090 :227, testSmtp :259). Browser: jsPDF-Nutzungen `script.js:7292, 7764, 22771, 22820`; Beleg-PDF-Routen im Frontend (≈14021, 14143) rufen serverseitiges Rendering. Laut AP-20261119-testabdeckung-klicktests sind EmailActions 1/5 und ExportActions 1/4 getestet. |
| 16 | Rechnungen/Angebote | CRUD in `src/Handlers/CrudActions.php` (`listRechnungen` :292, `saveRechnung` :328 inkl. Rechteprüfung :328–339, `deleteRechnung` :406); Tabelle `rechnungen` in `src/Database/LegacySchema.php:607–625` (Angebot = Belegtyp derselben Tabelle, keine eigene Tabelle); Frontend-Blöcke `script.js` ≈13257–14236 (~38 Treffer). Modul-Vorlage: `modules/lager/` (`module.json` mit Backend-Klasse, Frontend-Assets, Rollen, Feature-Flag, Lizenz-Tier; `src/Core/ModuleLoader.php:31–171`: Discovery, Dispatch mit Session/Rolle/Flag/Lizenz; `LicenseService.php:67–74`). |

### 2.2 Ziel & Abgrenzung (gesamt)

- **Ziel:** Alle 16 Punkte in priorisierte Bündel überführen; Bugfixes und Sicherheit zuerst; jedes Bündel einzeln umsetzbar, testbar, committbar (E-091/E-092).
- **Nicht Teil dieses AP:** Umsetzung (erst nach Freigabe G1); Aufbau der Klicktest-Infrastruktur (→ AP-20261119-testabdeckung-klicktests, wird hier nur **genutzt**); Beheben von erst durch Klicktests gefundenen Einzelbefunden (eigene Folge-Befundliste); Umbau der Zeiterfassungs-Datenhaltung; neue Fremdpakete.

### 2.3 Bündelung und Reihenfolge

| Bündel | Inhalt (Punkt-Nr. aus 2.1) | Priorität/Begründung |
|---|---|---|
| B1 | Bugfixes Anzeige: Dateiname (5), Sidebar-Scrollbars (1) | zuerst – echte Bugs, klein, gleiche Dateien (`script.js`, `style.css`, `index.html`) |
| B2 | Sicherheit: Rechte-Härtung (13) + allgemeine Härtung (16-„härten“) | hoch gewichtet, früh – unabhängig von Features |
| B3 | Kalkulation & Summen: Marge-Aufschlüsselung (2), Aufschlag-Opt-out je Position (3), unproduktive Stunden (4) | ein Bündel, da alle `matVk`/`recalc` + PHP-Parität berühren; braucht Antworten F1–F3 |
| B4 | Gesamtübersicht: Kundenspalte + Filter (6, Teil von 14) | nach B3 (gleiche `renderUebersicht`-Region, Konfliktvermeidung) |
| B5 | Stundenerfassung: Projektauswahl mit Kunde in 3 UIs (7) | unabhängig; `script.js` + `mobile.html` + `mobile_light.html` |
| B6 | KI-Scan: Gemini-Modellabruf + Belegerkennung (8, 9) | unabhängig; `AiActions.php` + Einstellungs-UI |
| B7 | UX & Klicktest-Befunde: Cursor (10), Fensterwechsel (11), Filter-Härtung global (14), PDF/Export-Prüfung (15) | **nach** Klicktest-Infrastruktur aus AP-20261119-testabdeckung-klicktests; Befunde statt Vermutungen |
| B8 | Benutzerverwaltung eingebettet (12) | eigenständig, mittel |
| B9 | Rechnungen/Angebote als Modul (16) | Großvorhaben, zuletzt; empfohlen als eigenes Folge-AP nach Beantwortung F6 |

### 2.4 Bündel im Detail

#### B1 – Bugfixes Anzeige (Dateiname, Sidebar)

| Datei | Begründung |
|---|---|
| `public/script.js` (≈14778) | `f.name.substring(20)` → vollständigen Namen rendern, Kürzung nur per CSS-Ellipsis am **Ende**; `title`-Attribut mit vollem Namen |
| `public/style.css` | `.file-card-name`: `overflow:hidden; text-overflow:ellipsis`; Sidebar: `#secMenu` und `#secProjekte` je eigener Scrollcontainer (`max-height`/`flex` + `overflow-y:auto`), `.sidebar-scrollable` anpassen |
| `public/index.html` | ggf. Container-Umbau Sidebar (zwei Scrollbereiche) + Cache-Busting `?v=` |
| `public/script.min.js`, `public/sw.js` | Minify + `CACHE_VERSION` |
| `public/bedienungsanleitung.html` | sichtbare Änderung Sidebar (E-081) |

Schritte: (1) Dateinamen-Fix + CSS, (2) Sidebar-Split mit Mindesthöhe der Projektliste, (3) Anleitung, (4) Cache-Busting, (5) JS-/Klicktest.
Risiken: keine DB; Cache-Busting; sehr schmale Bildschirme (mobile nutzt eigene Dateien, nicht betroffen).
Abnahme: (a) Datei `Langer_Dateiname_für_Test_2026.pdf` zeigt Anfang des Namens, Kürzung hinten mit „…“, voller Name im Tooltip; (b) bei 40 Menüeinträgen bleibt die Projektliste sichtbar und scrollt eigenständig; (c) Menü scrollt eigenständig.
Tests: JS-Test der Anzeigenamen-Funktion (reine Funktion extrahieren), Klicktest Sidebar (Viewport-Höhen).

#### B2 – Sicherheit: Zugriffsrechte + Härtung

| Datei | Begründung |
|---|---|
| `src/Auth.php`, `src/Handlers/*.php` | Inventar: jede `api.php`-Aktion → geforderte Rolle/`canDo`-Recht tabellieren (grep `requireAuth`/`requireRole`/`canDo` je Handler); Lücken wie `AiActions::scanMaterial` (nur `requireAuth`) schließen |
| `public/api.php` | Prüfen: POST-only für zustandsändernde Aktionen (E-015), Origin-Prüfung, anonyme Endpunkte auf Ausgabefilter (E-013) |
| `tests/Api/` | Rechtematrix-Tests (403 je Rolle), 405-Tests |
| `public/bedienungsanleitung.html` | falls Rechteverhalten sichtbar geändert (E-081) |

Schritte: (1) Rechte-Inventar als Tabelle in §3 des Umsetzungs-APs, (2) Lücken schließen (Reihenfolge 404→403→405 beibehalten, vgl. AP-20261101 Review), (3) anonyme Endpunkte prüfen, (4) Tests.
Risiken: alte Clients (`mobile.html` Vorversion) dürfen nicht durch neue 403 brechen (E-088) – Rechte nur dort verschärfen, wo die UI das Recht ohnehin voraussetzt; keine DB-Änderung (Rechte-JSON bleibt kompatibel, Backups importierbar).
Abnahme: (a) jede zustandsändernde Aktion lehnt GET mit 405 ab; (b) Aktionen ohne Rechtevergabe-Eintrag sind dokumentiert begründet; (c) `ai_scan_material` verlangt ein definiertes Recht; (d) Rechtematrix-Test grün auf SQLite + PostgreSQL.

##### B2 – Feinplanung (Architekt, 2026-11-19)

Grundlage: grep über alle Handler-Methoden (`requireAuth`/`requireRole`/`canDo`/`canSeeBaustelle`/`REQUEST_METHOD`/`getMessage()`), `public/api.php`, `src/Core/ModuleLoader.php:134–164`, `modules/*/backend/Module.php`, `modules/din1090/Din1090Actions.php:24–83`, `public/din1090_api.php`, Frontend-Aufrufer per grep. Die unkommittierten Dateien des Klicktest-APs (`tests/Unit/EndpunktInventarTest.php`, `tests/fixtures/endpunkt-luecken.json`, Erweiterung `tests/Support/ApiClient.php`) wurden **nicht** als Grundlage genutzt. Keine Schemaänderung, **keine neuen Rechte-Keys** nötig.

**Inventar-Kennzahlen** (≈ = gezählt aus Inventartabellen, Entwickler bestätigt in Schritt 1)

| Einstieg | Aktionen | anonym | nur `requireAuth` (davon schreibend) | zustandsändernd | davon mit 405 | mit Sichtbarkeitsprüfung |
|---|---|---|---|---|---|---|
| `public/api.php` | 183 (180 Routen + `features`/`list_modules`/`module`) | 10 | ≈ 47 (≈ 17) | ≈ 113 | 9 (davon `metallzuschlag_auto_fetch` nur für `force`) | ≈ 12 (`load`, `save`, 7× `FileActions`, `export_inform_csv`, `subunternehmer_report`, `move_position` bedingt) |
| Module via `ModuleLoader` | 43 (lager 12, aufmass 8, vde0100 23) | 0 | 3 (`ping`) | 27 | 0 | 0 |
| `din1090_api.php` (gleiche Logik via `module=din1090`) | 32 | 0 | 0 (alle `canReadDin1090`) | 19 (+ `canWriteDin1090`) | 0 | 0 |
| Interne Fehlertexte an Client (E-014) | ≈ 27 Stellen | | | | | |

Anonym (E-013): `check` (anonym nur Leerwerte + Lizenz-Tier ohne Kunde/Ablauf – ok), `setup` (nur ohne User – ok), `login`, `logout`, `features` (ok), `get_logo` (nur Logo – ok), `export_wochenplan_ical` (Token aus `cal_tokens`, DB-Vergleich – ok), `oci_start`/`oci_hook` (32-Byte-Nonce, TTL, Einmalverbrauch – ok), **`load_wochenplanung_display` (Lücke L7)**.

**Prüfreihenfolge (Konvention bestätigt und präzisiert):** 401 (`requireAuth`) → 403 globales Aktionsrecht (Rolle/`canDo`, objektunabhängig) → 404 Objekt → 403 objektbezogen (Typrecht, Sichtbarkeit, Eigentümer) → **405** → 400/409 Eingabeprüfung. Grundlage: E-015, AP-20261101 (`updateSchnellnotiz` liest `id` auch aus `$_GET`, 404 → 403 → 405, `PlanActions.php:502–518`), `renderBelegPdf` (403 `canSeePrices` vor 404). Präzisierung: Wird das Objekt nur über den JSON-Body adressiert, kann ein GET es nicht finden → `requirePost()` direkt nach dem globalen Recht (also vor 404/400); Objektprüfung vor 405 nur, wenn die Aktion das Objekt (auch) per Query adressiert.

**Warum 405 nötig ist:** Session-Cookie `SameSite=Lax` (`api.php:57–63`) wird bei Top-Level-GET-Navigation von Fremdseiten mitgesendet; die Origin-Prüfung (`api.php:84–99`) greift nur bei POST. Aktionen, die per GET ohne Body wirken, sind damit per Link auslösbar (CSRF).

**Lückenliste**

| L | Schwere | Aktion(en) · Fundstelle | Befund |
|---|---|---|---|
| L1 | **Blocker** | `send_rechnung`, `send_angebot` · `EmailActions.php:113,174` | nur `requireAuth`: jeder Angemeldete mailt jede Rechnung/jedes Angebot (Kunde, Preise) an beliebige Adresse; PDF-Ansicht derselben Belege verlangt Rechte (`ExportActions.php:256–300`) |
| L2 | Major | `send_vde`, `send_din1090` · `EmailActions.php:60,227` | nur `requireAuth`; `send_din1090` versendet beliebiges PDF an beliebigen Empfänger mit Firmenabsender, auch ohne DIN-Recht/Feature |
| L3 | Major | `ai_scan_material` · `AiActions.php:25,145` | nur `requireAuth` (externe Kosten); Gemini-Fehlertext an Client (E-014) |
| L4 | Major | Lager `foto_ai` · `modules/lager/backend/Module.php:57` | Gemini-Aufruf mit `canReadLager` (normal-Default **true**) |
| L5 | Major | GET-wirksame Schreibaktionen (ohne Body/mit Query): `clear_archive&confirm=1`, `repair_archiv_projekte&confirm=1` (Frontend ruft selbst per GET: `script.js:9549,9576`), `trigger_erinnerung`, `trigger_material_erinnerung`, `trigger_backup_email`, `trigger_stundenauswertung_email`, `auto_import_stunden`, `archive_all_schnellnotizen`, `datanorm_clear`, `datanorm_reindex`, `metallprofile_auto_update`, `hicad_import`, `backup_create` | kein 405 → per Link auslösbar (admin/master-Session) |
| L6 | Major | `din1090_api.php:72–76,114–131` | eigene Session-Prüfung statt `requireAuth` → Rollenänderung/`sessionInvalidatedAt` und `mustChangePassword` wirken nicht; Timeout-Zweig startet leere Session und läuft mit Default-Rolle `normal` weiter; kein Feature-Flag-/Lizenz-Gate (`ModuleLoader::userHasAccess` umgangen); alle GET-Parameter werden in den Body gemischt → `?sub=delete_project&id=…` per GET wirksam |
| L7 | Major | `load_wochenplanung_display` · `PlanActions.php:89–126` | **anonym**: alle Zeitbuchungen aller User (inkl. `krank`, Bemerkung, Stunden) und alle Baustellennamen; Display nutzt nur Teilmenge (`wochenplan_display.html:526–576`) |
| L8 | Major | `load_all_zeiterfassung` · `ZeiterfassungActions.php:1766` | jeder Angemeldete erhält alle Buchungen aller User inkl. Bemerkung/Von-Bis/Fehlertexte → **F-B2-2** |
| L9 | Major/Minor | Sichtbarkeit (IDOR): `save_tagesbericht`, `save_tagebuch_export` (Major: Dateien in fremde Projektordner, `FileActions.php:551,580`); `save_sub_sort_order`, `mark_ordered`, `toggle_auswertung_flag` (`BaustelleActions.php:820–949`), `save_schnellnotiz`/`load_schnellnotizen` mit `baustelleId` (`PlanActions.php:408–489`), `save_termin` mit `baustelleId` (`TerminActions.php:65`) (Minor) | keine `canSeeBaustelle`-Prüfung |
| L10 | Minor | `delete_file` ohne Kategorie · `FileActions.php:470–480` | löscht in der Altablage (Wurzel) ohne Schreibrecht |
| L11 | Minor | `rename_file` (kein Schreibrecht), `move_file` (nur Zielrecht), `save_generated_file` (kein Ordnerrecht) · `FileActions.php:237,355,502` | Ordnerrechte unvollständig → **F-B2-4** |
| L12 | Minor | ≈ 113 übrige Schreibaktionen api.php, 27 Modul-, 19 DIN-Schreibaktionen | kein 405 (GET ohne Body meist wirkungslos – Härtung/Abnahme a) |
| L13 | Minor | E-014 in Pfaden für Nicht-Admins: `EmailActions.php:87,103,146,164,199,217,249`, `BaustelleActions.php:933`, `AiActions.php:145`, `modules/aufmass/backend/Module.php:232,322`, `modules/lager/backend/Module.php:267`, `Din1090Actions.php:737`, `CatalogActions::metallprofileCatalog` `fetchError` | Exception-/Upstream-Texte an Client |
| L14 | Minor | `get_permissions` · `AdminActions.php:17` | `requireAuth` liefert komplette Rechtematrix aller Rollen; Aufrufer nur Admin-Editor (`script.js:14470,14605`) |
| L15 | Minor | `Auth::getCurrentUserPerms` (admin) · `Auth.php:382–408` | Admin-Liste fehlen 17 Keys: 14 Dokumentenordner-Rechte, `canReadDin1090`, `canWriteDin1090`, `canSeeWochenpruefung`; `mobile_light.html:565` `canDo` hat keinen Admin-Kurzschluss |
| L16 | Minor | `Din1090Actions::dispatch` `:31–41` | Rechte-Ablehnung mit HTTP 200 (`ok:false`) statt 403 |

**Maßnahmenliste** (Reihenfolge = Priorität; alle ohne neue Rechte-Keys)

| M | Aktion(en) | Neue Prüfung | Begründung | E-088: UI-Stelle, die das Recht schon voraussetzt |
|---|---|---|---|---|
| M1 | `send_rechnung`, `send_angebot` | 401 → 403 Typrecht wie gespeicherter Beleg in `renderBelegPdf`: Rechnung `canManageRechnungen` ODER `canSeeRechnungen`, Angebot `canManageAngebote` ODER `canSeeAngebote` → 405 → 400/404 | L1; gleiches Recht wie PDF-Ansicht desselben Belegs | Mail-Dialog nur in Rechnungs-/Angebotsansicht (`script.js:14064,14076`), Menü nur bei `canSeeRe`/`canSeeAn` (`script.js:≈8490–8495`; Entwickler bestätigt See ODER Manage). `canSeePrices` zusätzlich nur nach F-B2-3b |
| M2 | `send_vde`, `send_din1090` | `send_vde`: `canReadVde0100`; `send_din1090`: `canReadDin1090` + `feature_enabled('din1090')`; danach 405 | L2 | Versand nur aus dem Modul (`vde0100.js:637`, `din1090.js:1646`), das nur mit Leserecht nutzbar ist (DIN-Dispatch verlangt `canReadDin1090` für jeden Aufruf). Strengeres Recht für DIN nur nach F-B2-5 |
| M3 | `ai_scan_material` | `canEditMaterial` ODER `canEditArbeitszeit` (403) → 405; Gemini-Fehler → feste Meldung + `error_log` (E-014) | L3; Scan-Ergebnis wird nur in Material-/Arbeitszeitpositionen übernommen; Recht = exakt das bestehende UI-Gate | Kopf-Knopf `btnKiScanHeader` nur bei genau dieser Bedingung (`script.js:8498`), Material-Scan bei `canEditMaterial` (`script.js:≈2516`), mobil `canDo('canEditMaterial')` (`mobile.html:2003`); Aufrufe bereits POST (`script.js:3444`, `mobile.html:3669`). **Für B6:** `ai_list_models` = `requireRole('admin')` + POST (KI-Einstellungen nur Admin, `save_settings` admin-only) |
| M4 | Lager `foto_ai` | `canWriteLager` statt `canReadLager` (+405); `foto_ai_available` bleibt `canReadLager` (liefert nur bool) | L4 (Kosten) | Aufruf nur aus Artikeldialog (`lager.js:684–692,988`), der nur mit `canWriteLager` öffnet (`lager.js:155,214` – Entwickler verifiziert) |
| M5 | `public/din1090_api.php` | Session-Prüfung → `Auth::requireAuth()` **nach** DB-Connect und Timeout (Reihenfolge wie `api.php`); `mustChangePassword` → 403 wie `api.php:448–458`; `feature_enabled('din1090')` und `LicenseService::isModuleAllowed('din1090')` → 403; GET-Merge bleibt (nur Lese-subs nach M7 wirksam) | L6 | DIN-Modul erscheint nur, wenn `list_modules` es liefert (gleiche Gates); `din1090.js:19–27` sendet immer POST |
| M6 | `clear_archive`, `repair_archiv_projekte` | Vorschau per GET bleibt; `confirm=1` nur per POST (405 nach `requireRole`) – analog `force` (E-015/E-016) | L5 (destruktiv per Link) | Frontend `script.js:9549,9576` auf POST umstellen (+ Cache-Busting); alter Client erhält beim Bestätigen 405 mit Meldung – nur admin/master, nach Neuladen behoben (vertretbar wie `update_start`) |
| M7 | Alle zustandsändernden `api.php`-Aktionen | neuer Helfer `Auth::requirePost()` (405, feste Meldung) in jeder Schreibmethode an der Stelle gemäß Prüfreihenfolge; **zuerst** die L5-Gruppe, dann Rest (L12). Bestehende 405-Stellen (`DataActions:326`, `SystemJobActions:79,204,274,322,448`, `PlanActions:516`, `AdminActions:453`, `CatalogActions:400`) auf den Helfer umstellen ohne Verhaltensänderung | L5, L12, Abnahme (a) | Regel: POST-Pflicht nur, wenn grep **alle** Aufrufer (`public/*.html`, `public/*.js` ohne `.min`, `public/modules/**`) **und** die Vorversion (`git show <letztes Release-Tag>:public/script.js`/`mobile.html`/`mobile_light.html`) als POST belegt; sonst GET-Ausnahme. **GET-Ausnahmen (dokumentiert, idempotent/lesend):** `logout` (`mobile_light.html:450`), `get_wochenplan_cal_token` ohne `users` (`mobile.html:6100`), `project_presence` (`script.js:1681`), `oci_prepare` (`script.js:6339`), `oci_start` (Browser-Navigation), `oci_hook` (Lieferanten-POST), `metallzuschlag_auto_fetch` ohne `force` (`mobile.html:3307`), `metallprofile_catalog`, `list_files`, `load_kunden`, `datanorm_status`, `backup_download`, `download_archive_*`, `update_check` ohne `force` |
| M8 | Modul-Schreib-subs (lager 5, aufmass 4, vde0100 18) und DIN (19) | Modul: `Auth::requirePost()` im `dispatch`-Zweig direkt nach `requirePerm`; DIN: in `Din1090Actions::dispatch` nach der `canWriteDin1090`-Prüfung; DIN-Ablehnungen als `jsonOut([...ok:false, error...], 403)` (Body-Felder unverändert) | L6 (GET-Merge), L12, L16 | Entwickler belegt per grep, dass Modul-Wrapper (`lager.js` `api()`, Aufmaß, VDE) Schreib-subs per POST senden; `din1090.js` prüft nur `j.ok`, Status 403 statt 200 bricht nichts |
| M9 | `load_wochenplanung_display` | Datenminimierung ohne Feldumbenennung: nur User mit `showInWochenplanung`; je Eintrag nur `datum`, `typ`, `baustelleId`, `tagesanteil`, `stunden` nur bei `typ=arbeit`, `bemerkung` nur bei `typ=sonstig`; `baustellenNamen` nur für referenzierte IDs; Zeitfenster = vom Display tatsächlich angezeigter Bereich (Entwickler ermittelt in `wochenplan_display.html`) | L7 (DSGVO, `krank` bleibt nur als Typ) | Display liest genau diese Felder (`wochenplan_display.html:391,526–576`); Zugriffsschutz nur nach F-B2-1 |
| M10 | `save_tagesbericht`, `save_tagebuch_export`, `save_sub_sort_order`, `mark_ordered`, `toggle_auswertung_flag`, `save_schnellnotiz`/`load_schnellnotizen` (mit `baustelleId`), `save_termin` (mit `baustelleId`) | `Auth::canSeeBaustelle()` für jede übergebene Baustellen-ID → 403 (nach globalem Recht, vor 405) | L9 | UI bietet nur Baustellen aus `load` an, das per `getEffectiveVisibility` filtert (`DataActions.php:26`) → kein Bestandsnutzer verliert Zugriff; Restfall: Termin/Notiz an inzwischen unsichtbarer Baustelle → 403 beim Speichern (gewollt) |
| M11 | `delete_file` ohne/ungültige Kategorie | Schreibrecht `canWriteDateien` (Altablage ≙ „Dateien“) | L10 | Desktop und mobil senden immer `category` (`script.js:14790,14933`, `mobile.html:6332` – Entwickler bestätigt) |
| M12 | L13-Stellen (nur für Nicht-Admins erreichbar) | feste Meldung + `error_log()` (E-014); eigene, bewusst formulierte Domänenmeldungen (z. B. `MailService.php:71`) dürfen bleiben, `ErrorInfo`/PDO/PHP-Texte nicht | L13 | Antwortfeld `error` bleibt, nur Text ändert sich |
| M13 | `get_permissions` | `requireRole('admin')` | L14 | einzige Aufrufer Admin-Rechteeditor (`script.js:14470,14605`); `set_permissions` ist bereits admin-only |
| M14 | `Auth::getCurrentUserPerms` (admin) | Admin-Liste aus allen Keys von `getDefaultPermissions()['master']` + `canSeeWochenpruefung` erzeugen (statt Handliste) | L15 | rein additiv; `canDo()` serverseitig unverändert (admin immer true) |

**Bewusst nur `requireAuth` (Abnahme b, Grundfunktion jedes Mitarbeiters, intern eigen-/objektgeprüft):** `change_password`, `set_theme`, `list_users_basic`, `load`/`save` (Sichtbarkeit + bedingte `canDo` intern), `project_heartbeat`, `project_presence`, `load_zeiterfassung`/`save_zeiterfassung` (nur eigener User), `get_user_sollstunden`, `get_zeiterfassung_historie`/`get_gleitzeitkonto_buchungen` (fremd nur mit `canSeeStundenauswertung`), `load_wochenplanung`/`save_wochenplanung` (normal nur eigener Plan), `get_wochenplan_cal_token`, Schnellnotizen (Zuweisungslogik, AP-20261101), `load_termine` (`canSeeOwn/AllTermine` intern), `load_gruppen_for_user`, `list_dienstleister`, `datanorm_search`, `metallzuschlag_get`, `metallprofile_catalog`, `hicad_list`, `material_top_used`, `oci_list_lieferanten`, `oci_prepare`, `list_modules`, `save_tagesbericht`/`save_tagebuch_export` (+ Sichtbarkeit M10), `save_weekly_export` (Folgepunkt). Diese Liste wird als Kommentar/Konstante in der neuen Testklasse geführt.

**Betroffene Dateien**

| Datei | Grund |
|---|---|
| `src/Auth.php` | `requirePost()` (M7), Admin-Liste (M14) |
| `src/Handlers/EmailActions.php` | M1, M2, M12, 405 |
| `src/Handlers/AiActions.php` | M3 |
| `src/Handlers/AdminActions.php`, `AuthActions.php`, `UserActions.php`, `DataActions.php`, `SystemJobActions.php`, `BaustelleActions.php`, `ZeiterfassungActions.php`, `PlanActions.php`, `TerminActions.php`, `DashboardActions.php`, `GruppenActions.php`, `CrudActions.php`, `FileActions.php`, `CatalogActions.php`, `OciActions.php` | M6, M7, M9, M10, M11, M12, M13 |
| `modules/lager/backend/Module.php`, `modules/aufmass/backend/Module.php`, `modules/vde0100/backend/Module.php`, `modules/din1090/Din1090Actions.php` | M4, M8, M12 |
| `public/din1090_api.php` | M5 |
| `public/script.js`, `public/script.min.js`, `public/index.html` (`?v=`), `public/sw.js` (`CACHE_VERSION`) | nur M6 (zwei `fetch` auf POST) + Cache-Busting |
| `public/bedienungsanleitung.html` | Abschnitt Berechtigungen: KI-Scan (Material- oder Arbeitszeitrecht), Lager-KI-Foto (Lager-Schreibrecht), E-Mail-Versand von Belegen/Protokollen (jeweiliges Leserecht) (E-081) |
| `tests/Api/RechteHaertungTest.php`, `tests/Api/PostPflichtTest.php` (neu) | Testplan unten |

**Struktur (copier-astral):** keine neuen Ordner/Make-Targets/CLI/CI-Jobs; Tests in bestehender Suite `tests/Api/` (E-050).

**Schritte (Commit-Reihenfolge)**

1. **B2a Rechte** (M1–M4, M10, M11, M13, M14) + `RechteHaertungTest` → gezielte PHPUnit-Läufe SQLite + PostgreSQL → Commit.
2. **B2b DIN-Einstieg** (M5, M8-DIN-Teil, L16) + Tests → Commit.
3. **B2c POST-Pflicht**: (a) grep-Nachweis je Schreibaktion (Aufrufer aktuell + Vorversion, Ergebnis als Tabelle in §3), (b) `Auth::requirePost()`, (c) L5-Gruppe + M6 inkl. Frontend/Cache-Busting, (d) Rest-Handler + Module, (e) `PostPflichtTest` inkl. Klassifizierungs-Wächter → `npm.cmd run test:js`, `node scripts/check-versions.mjs --strict` → Commit.
4. **B2d Ausgaben** (M9, M12) + Tests + Anleitung → Commit.
5. Gesamtlauf PHPUnit SQLite + PostgreSQL, PHPStan ohne neue Baseline.

**Testplan** (neue Klassen, keine Änderung an Fremddateien; je Maßnahme mind. ein Test, der **ohne Fix rot** ist; Rechte über `set_permissions` als Admin setzen, Sichtbarkeit über `set_visibility`)

| Test | rot ohne Fix, weil … |
|---|---|
| M1 `testNormalOhneBelegrechtKannRechnungNichtMailen` (403 für `send_rechnung`/`send_angebot`, kein SMTP nötig, da 403 vor Versand) + Gegenprobe Master ≠ 403 | heute 400/500 statt 403 |
| M2 `send_vde`/`send_din1090` ohne Leserecht → 403 | heute erreicht Handler den Versand |
| M3 normal ohne beide Rechte → 403; nur `canEditArbeitszeit` → nicht 403; GET mit Recht → 405 | heute 400 („Kein API-Key“) |
| M4 `module=lager&sub=foto_ai` mit Lese-, ohne Schreibrecht → 403 | heute erreicht Handler |
| M5 DIN-Endpunkt: nach `set_role` (Session-Invalidierung) → 401; `mustChangePassword` → 403 | heute 200 |
| M6 `clear_archive?confirm=1` per GET → 405, Archiv unverändert; Vorschau-GET → 200 | heute wird geleert |
| M7/M8 Datenprovider über Schreibaktionen: Admin-GET → 405 (Objekt-ID als Query, wo Objektprüfung vor 405); Normal-GET auf admin-Aktion → 403 (Reihenfolge); Wächtertest: jede Route aus `public/api.php` (Regex auf `$routes`-Keys) ist in genau einer Liste SCHREIB/LESE/GET-AUSNAHME – neue Routen müssen klassifiziert werden | heute 200/400 statt 405 |
| M8 DIN `delete_project` per GET → 405, Projekt existiert noch; Schreib-sub ohne `canWriteDin1090` → HTTP 403 | heute gelöscht bzw. 200 |
| M9 anonym: kein Eintrag von User mit `showInWochenplanung=0`, kein `bemerkung` bei `typ=krank`/`arbeit`, keine nicht referenzierten Baustellennamen | heute enthalten |
| M10 normal mit eingeschränkter Sichtbarkeit: `save_tagesbericht`, `save_sub_sort_order`, `save_schnellnotiz` mit fremder `baustelleId` → 403 | heute ok |
| M11 `delete_file` ohne Kategorie, Rolle ohne `canWriteDateien` → 403 | heute ok |
| M12 `send_rechnung` mit absichtlich falschem SMTP-Host (Master) → `error` enthält keinen Hostnamen/`ErrorInfo` | heute enthalten |
| M13 normal `get_permissions` → 403 | heute 200 |
| M14 Admin `check` → `permissions.canReadDin1090`, `canWriteEingangsbelege` = true | heute fehlen Keys |

Abhängigkeit: Tests direkt gegen `din1090_api.php` brauchen einen Skript-Aufruf im `ApiClient`. Liegt `skript()`/`din1090()` nur in der **unkommittierten** Erweiterung des Klicktest-APs, entscheidet der Leitstand: zuerst jenes AP committen oder M5-Tests bis dahin zurückstellen (M8-DIN-Tests laufen über `module=din1090` mit `post('module', …, ['module'=>…,'sub'=>…])`).

**Risiken**

- **SQLite/PostgreSQL:** keine Schemaänderung; nur Tests auf beiden DBs (`scripts/test-pgsql.ps1`). Sichtbarkeits-Cache `Auth::expandVisibleBaustellenIds` ist `static` – im Testserver je Request neu, unkritisch.
- **Migration/Fixture/Backup:** keine; Rechte-JSON unverändert, alte Sicherungen importierbar.
- **Alte Clients (E-088):** größtes Risiko ist M7 → harte Regel „POST-Pflicht nur mit grep-Nachweis aktueller **und** vorheriger Clients“, sonst GET-Ausnahme. M6 bewusst mit 405 für alte Admin-Clients.
- **Rechte:** M1–M4, M10, M11, M13 entsprechen bestehenden UI-Gates; Sonderkonfigurationen (z. B. Belegrecht ohne Preisrecht) siehe F-B2-3.
- **Cache-Busting:** nur durch M6 (`script.js`).
- **Modul-Lizenz/Flag:** M5 erzwingt Flag/Lizenz am Legacy-Einstieg – Instanzen ohne DIN-Lizenz, die den Endpunkt bisher direkt nutzten, erhalten 403 (UI zeigte das Modul dort ohnehin nicht).
- **Umfang:** M7 berührt ≈ 100 Methoden mechanisch → Unterbündel B2c, schmale Review-Runde (E-094).

**Abnahme (Konkretisierung von a–d):** (a) jede Aktion der SCHREIB-Liste (api.php, Module, DIN) liefert bei GET 405, GET-Ausnahmen sind im Test und hier begründet; (b) Liste „bewusst nur `requireAuth`“ oben + im Test; (c) `ai_scan_material` verlangt `canEditMaterial` ODER `canEditArbeitszeit`; (d) `RechteHaertungTest` + `PostPflichtTest` grün auf SQLite und PostgreSQL; zusätzlich: keine Rolle verliert eine Funktion, die ihre UI heute anbietet (Gegenproben Master/normal mit Default-Rechten ≠ 403).

**Nicht Teil von B2 (Folgepunkte):** CSP-Header und Frontend-Härtung (`innerHTML`/`esc`-Audit); Origin-Ausnahme für private IPs (`api.php:91–94`); E-014 in admin/master-only Pfaden (`DataActions` Backup/Restore, `SystemJobActions:124`, `BaustelleActions:543,713`, `CatalogActions:855,907,1022`; `test_smtp` behält SMTP-Text bewusst als Diagnose); Baustellen-Sichtbarkeit in Modulen (Aufmaß/VDE/DIN) und bei Zeitbuchung auf unsichtbare Baustelle (Offline-Queue, E-088); `save_weekly_export` nur `requireAuth`; `material_top_used` projektübergreifend; Rate-Limit für KI-Scan; `logout` per GET.

**Offene Fragen an den Nutzer (B2)**

- **F-B2-1 (Wochenplan-Anzeige):** Die TV-Anzeige `wochenplan_display.html` ist ohne Anmeldung abrufbar. B2 reduziert die Daten auf das Angezeigte (M9). Soll sie zusätzlich per geheimem Link (Token wie beim iCal-Abo) geschützt werden? Folge: bestehende Anzeigen brauchen einmalig eine neue URL.
- **F-B2-2 (Zeitbuchungen aller Kollegen):** Jeder angemeldete Mitarbeiter erhält heute alle Buchungen aller Kollegen inkl. Bemerkung und Von/Bis (genutzt u. a. in der mobilen Wochenplanung, `mobile.html:5599`). Sollen Mitarbeiter ohne „Alle Stunden sehen“/„Stundenauswertung“ von Kollegen nur noch Datum, Typ, Baustelle und Stunden erhalten?
- **F-B2-3 (Belege):** (a) Sollen Rechnungen/Angebote (Liste, PDF, Versand) zusätzlich auf die sichtbaren Baustellen des Nutzers beschränkt werden? (b) Soll der E-Mail-Versand – wie die PDF-Ansicht – zusätzlich „Preise sehen“ verlangen (betrifft nur selbst angepasste Rollen)?
- **F-B2-4 (Dateien):** Sollen Umbenennen und Verschieben (aus dem Quellordner) sowie „In Dateien speichern“ dasselbe Ordner-Schreibrecht verlangen wie Löschen? Rollen mit nur Leserecht verlieren dann Umbenennen/Verschieben; für „In Dateien speichern“ aus Rechnungen/Angeboten würde das Belegrecht als Ersatz gelten.
- **F-B2-5 (DIN-Versand):** Soll der E-Mail-Versand von DIN-1090-Protokollen das Schreibrecht DIN 1090 verlangen (Server prüft den PDF-Inhalt nicht)? Mitarbeiter mit Standardrechten könnten dann nicht mehr versenden.

**Entscheidungen Nutzer B2 (2026-11-19):**
- **F-B2-1:** Ja – Wochenplan-Anzeige zusätzlich per geheimem Token-Link (Muster iCal-Abo); bestehende Anzeigen werden einmalig neu eingerichtet (Hinweis in Anleitung + Admin-UI zum Erzeugen/Erneuern des Links). Umsetzung in B2d zusammen mit M9.
- **F-B2-2:** Ja – Nutzer ohne `canSeeAllStunden`/`canSeeStundenauswertung` erhalten von Kollegen nur Datum, Typ, Baustelle, Stunden (eigene Buchungen vollständig). Umsetzung in B2d.
- **F-B2-3a:** Ja – Rechnungen/Angebote (Liste, PDF, Versand) auf sichtbare Baustellen beschränken (Admin/„alle“ unverändert). Umsetzung in B2a.
- **F-B2-3b:** Ja – Belegversand zusätzlich `canSeePrices` (wie PDF-Ansicht). Umsetzung in B2a (M1).
- **F-B2-4:** Ja – Umbenennen, Verschieben (Quell- und Zielordner) und „In Dateien speichern“ verlangen das Ordner-Schreibrecht. Das Belegrecht gilt als Ersatz **nur** für „In Dateien speichern“ aus Rechnungen/Angeboten (`save_generated_file`), nicht für `move_file`/`rename_file`/`delete_file` (präzisiert durch Leitstand nach Review B2a R1). Umsetzung in B2a (M11).
- **F-B2-5:** Nein – DIN-Versand bleibt beim Leserecht (M2).
- **Leitstand:** Der committete `tests/Support/ApiClient.php` hat bereits `skript()` und `post('module', …, ['module'=>…,'sub'=>…])` – B2-Tests nutzen nur diese, NICHT die unkommittierten Helfer `module()`/`din1090()` des Klicktest-APs.

#### B3 – Kalkulation & Summen

| Datei | Begründung |
|---|---|
| `public/script.js` | `matVk()` (≈2194): neues Positions-Flag `ohneGlobalAufschlag` (nur Globalaufschlag aus, Positionsaufschlag bleibt – anders als `vkDirekt`); `renderMatRows` (≈4415): Checkbox + Anzeige Aufschlagsbetrag/Marge je Artikel; `recalc()` (≈5438): Summen „Aufschlag gesamt“/„Marge gesamt“; `azHoursTotal`/Projekt-gesamt-Betrag ohne `unproduktiv`-Zeilen (gemäß F3) |
| `src/Handlers/ExportActions.php` (≈49–59), `src/Handlers/BaustelleActions.php` (≈744–762) | PHP-Parität: Flag und unproduktiv-Regel identisch nachziehen (E-036, E-086) |
| neue reine JS-Funktion z. B. `public/kalkulation-summen.js` | Rechenregeln testbar auslagern (Muster `uebersicht-summen.js`), Einbindung `index.html` + `sw.js` |
| `tests/js/`, `tests/fixtures/` + `tests/Unit/` | gemeinsame Fallliste PHP↔JS (E-036) |
| `public/bedienungsanleitung.html` | neue Spalten/Checkbox (E-081) |
| `public/script.min.js`, `public/index.html`, `public/sw.js` | Cache-Busting |

Schritte: (1) Fallliste definieren (EK, Positions-/Globalaufschlag, Rabatt, `vkDirekt`, neues Flag, unproduktiv), (2) JS-Rechenkern + Flag + UI, (3) PHP-Parität, (4) Summen-/Aufschlüsselungs-Anzeige, (5) Anleitung, (6) Cache-Busting, (7) Tests.
Risiken: Projektdaten liegen als JSON im Projekt – neues Flag ist abwärtskompatibel (fehlend = false), alte Backups bleiben importierbar; alte Clients kennen das Flag nicht und rechnen ohne (E-088 – Anzeige-, kein Datenkonflikt; im Plan dokumentieren); Archiv-Kennzahlen ändern sich rückwirkend (→ F3); Rundungsparität PHP/JS.
Abnahme: (a) Position mit Flag: VK = EK + Positionsaufschlag − Rabatt, ohne Globalaufschlag; (b) je Artikel sind Aufschlagsbetrag und Marge sichtbar, Summenzeile zeigt „Aufschlag gesamt“ und „Marge gesamt“ konsistent zur Gewinnanzeige; (c) unproduktive Zeile erhöht weder „Gesamtsumme Arbeitszeit“ (Stunden) noch „Projekt gesamt“ gemäß F3-Antwort; (d) PHP-Export und Archiv-Auswertung liefern dieselben Werte wie das Frontend (Fallliste grün SQLite + PostgreSQL + JS).

#### B4 – Gesamtübersicht: Kundenspalte + Filter

| Datei | Begründung |
|---|---|
| `public/script.js` | `renderUebersicht()` (≈3021): Spalte „Kunde“ (Name aus `kundeId` über Kundenliste aufgelöst, `String(id)`-Normalisierung E-096); `rows` um `kunde` erweitern; `filterUebersichtRows` trifft Kundenname (Textfilter) |
| `public/uebersicht-summen.js` | nur falls `rows`-Struktur die Signatur berührt (erwartet: keine Änderung) |
| `tests/js/uebersicht-summen.test.mjs` | Regression: Summen unverändert mit zusätzlichem Feld |
| `public/bedienungsanleitung.html` | neue Spalte (E-081) |
| `public/script.min.js`, `public/index.html`, `public/sw.js` | Cache-Busting |

Schritte: (1) Kundenauflösung als Hilfsfunktion, (2) Spalte + Sortierung, (3) Filter prüfen/erweitern, (4) Anleitung, (5) Cache-Busting, (6) Tests.
Risiken: Projekte ohne `kundeId` → leere Zelle; Doppelzählung Unterprojekte unverändert lassen (E-096); Summenverhalten bei Filter (AP-20261101) nicht regressieren.
Abnahme: (a) Spalte „Kunde“ zeigt den Kundennamen; (b) Filtereingabe „Meier“ zeigt nur Projekte dieses Kunden, Summen/KPIs passen sich an (Bestand); (c) Projekte ohne Kunde bleiben sichtbar (leere Zelle); (d) Sortierung nach Kunde funktioniert.

#### B5 – Stundenerfassung: Projektauswahl mit Kunde (3 UIs)

| Datei | Begründung |
|---|---|
| `public/script.js` | `bsDdHtml()` (≈7950/7977): Anzeige „Projekt-Nr – Name (Kunde)“ + Suche über Kundenname |
| `public/mobile.html` | `openSeBaustellePicker`/`_renderSeBsPickerList` (≈5055/5075): Anzeige + Suche inkl. Kunde |
| `public/mobile_light.html` | `_renderLightBsPickerList` (≈650–676): Kunde zusätzlich **anzeigen** (Suche kann ihn schon) |
| `public/bedienungsanleitung.html` | sichtbare Änderung (E-081) |
| Cache-Busting-Dateien | wie oben |

Schritte: (1) gemeinsame Auflösungs-/Formatfunktion je UI (kein geteiltes Modul zwischen den drei Dateien erzwingen – mobile-Dateien sind eigenständig), (2) Desktop, (3) Mobile, (4) Mobile Light, (5) Anleitung, (6) Cache-Busting, (7) Tests.
Risiken: `kundeId`-Typmix (E-096); Performance der Picker bei vielen Projekten (tokenweise Suche wie Mobile Light übernehmen); keine API-Änderung nötig (`load` liefert `kundeId` bereits) → alte Clients unberührt (E-088).
Abnahme: In allen drei Oberflächen zeigt die Baustellenauswahl den Kundennamen je Projekt, und die Suche nach einem Kundennamen findet dessen Projekte.

#### B6 – KI-Scan: Gemini-Modelle + Belegerkennung

| Datei | Begründung |
|---|---|
| `src/Handlers/AiActions.php` | neue Aktion `ai_list_models` (POST, Auth + Recht aus B2): serverseitiger Abruf `GET …/v1beta/models` mit gespeichertem Key (Key nie an den Client, E-010); HTTP-Aufruf injizierbar machen (E-034); Prompt (≈52–77): getrennte Felder `mengenEinheit` und `preisBasis`/`preisEinheit` (z. B. „pro 100“), serverseitige Normalisierung des Einzelpreises |
| `public/api.php` | Route `ai_list_models` |
| `public/script.js` (≈11581–11602) | Modell-Dropdown über dem API-Key-Feld aus `ai_list_models` befüllen („Modelle laden“-Knopf), freies Feld als Fallback behalten |
| `tests/Api/` + `tests/Unit/` | Modelle-Abruf mit injizierter Antwort; Parser-Fälle „€/100 Stk“, „pro 1000“, Preis ohne Basis |
| `public/bedienungsanleitung.html` | Einstellungs-UI (E-081) |
| Cache-Busting-Dateien | wie oben |

Risiken: Fehlerpfade (kein Key, Quota, Netzfehler) mit fester Meldung, Details nur `error_log()` (E-014); Antwortformat des Scans nicht umdeuten – neue Felder zusätzlich, alte bleiben (E-088); Drosselung des Modellabrufs (kein `force` aus dem Frontend, E-016).
Abnahme: (a) Knopf „Modelle laden“ füllt das Dropdown mit den Gemini-Modellen des hinterlegten Keys, Fehler zeigen eine feste Meldung; (b) gewähltes Modell wird gespeichert und beim Scan verwendet; (c) Beleg mit „€/100 Stk“ liefert Einheit „Stk“ und korrekt normalisierten Einzelpreis; (d) Key erscheint nie in einer API-Antwort.

#### B7 – UX & Klicktest-Befunde (Cursor, Fensterwechsel, Filter, PDF/Exporte)

Verzahnung statt Doppelung mit **AP-20261119-testabdeckung-klicktests**: Die dortigen Klicktests liefern die Befundliste; dieses Bündel behebt sie.

| Datei | Begründung |
|---|---|
| `tests/e2e/*.spec.mjs` (aus Klicktest-AP) | gezielte Szenarien: alle Eingabezellen (Klick mitten ins Wort → Caretposition), Ansichtswechsel-Matrix (jede Ansicht → jede Ansicht, genau eine sichtbar), alle Filterfelder, alle PDF-/Export-Knöpfe |
| `public/script.js` | Fixes gemäß Befund: Cursor-Ursache (erst reproduzieren – Fundstelle offen, siehe 2.1 Punkt 10), `show…()`-Funktionen, die fremde Ansichten nicht ausblenden |
| `src/Handlers/ExportActions.php`, `EmailActions.php` | Korrekturen aus PDF/Export-Prüfung; API-Testlücken deckt das Klicktest-AP (B2/B3 dort) |
| `public/bedienungsanleitung.html` | falls sichtbares Verhalten korrigiert (E-081) |

Schritte: (1) Szenarien als Klicktests schreiben (rot bei Fehlverhalten), (2) Befundliste in §3, (3) Fixes je Befund, (4) Klicktests grün.
Risiken: ohne reproduzierte Ursache keine Code-Änderung (E-072: Behauptungen belegen); Umfang erst nach Befundliste schätzbar → ggf. in Folge-APs schneiden.
Abnahme: (a) Klick mitten in den Text einer Eingabezelle setzt den Cursor an die Klickposition; (b) nach jedem Ansichtswechsel ist genau eine Hauptansicht sichtbar (Klicktest-Matrix grün in Chromium/Firefox/WebKit); (c) jeder dokumentierte PDF-/Export-Knopf erzeugt eine gültige Datei; (d) Filter-Klicktests grün.

#### B8 – Benutzerverwaltung eingebettet

| Datei | Begründung |
|---|---|
| `public/index.html` (≈101, 514–565) | Inhalt von `#userMgmtModal` als Bereich der eingebetteten Verwaltung |
| `public/script.js` (≈10539–10548) | `openUserModal()` → Bereichswechsel in der Verwaltungsansicht; Rendering wiederverwenden |
| `public/verwaltung-nav.js` | neuer Bereich „Benutzer“ in der Bereichsliste (nur Admin) |
| `public/bedienungsanleitung.html` | geänderter Weg zur Benutzerverwaltung (E-081) |
| Cache-Busting-Dateien | wie oben |

Risiken: Rechte (Bereich nur für Admin sichtbar – serverseitige Prüfung bleibt maßgeblich); tief verlinkte Aufrufe von `openUserModal()` per grep inventarisieren (E-086-Muster).
Abnahme: Benutzerverwaltung öffnet eingebettet in der Verwaltung (kein Modal), alle bisherigen Funktionen (Anlegen, Rolle, Passwort-Reset, Sichtbarkeit) erreichbar; Verwaltungssuche findet den Bereich.

#### B9 – Rechnungen/Angebote als Modul (Großvorhaben, empfohlen als eigenes Folge-AP)

Struktur nach Modul-Konvention (Vorlage `modules/lager/`, E-050): `modules/belege/` mit `module.json` (Backend-Klasse, Frontend-JS/CSS, Rollen, Feature-Flag, Lizenz-Tier), Backend `modules/belege/backend/Module.php`, Frontend `public/modules/belege/`.

| Datei | Begründung |
|---|---|
| `modules/belege/**` (neu) | Modulgerüst nach `module.json`-Manifest |
| `src/Handlers/CrudActions.php` (≈292–406), `ExportActions.php` (≈200–300), `EmailActions.php` (≈113–226) | Rechnungs-/Angebotslogik dorthin verschieben; alte `api.php`-Routen als dünne Weiterleitungen **behalten** (alte Clients, E-088) |
| `public/script.js` (≈13257–14236) | UI-Blöcke in Modul-Frontend extrahieren |
| `src/Core/ModuleLoader.php` | unverändert (Discovery reicht); `LicenseService` nur Konfiguration |
| `tests/Api/` | bestehende Rechnungs-Tests müssen unverändert grün bleiben (Vertrag), neue Modultests |
| `public/bedienungsanleitung.html` | Modulbeschreibung (E-081) |

Risiken: **Backup-Kompatibilität** – Tabelle `rechnungen` (LegacySchema) bleibt unverändert, keine Migration nötig; falls doch, nur per Phinx + neue Fixture (E-020); alte Clients rufen alte Routen (E-088) → Weiterleitungen dauerhaft; Feature-Flag/Lizenz: Bestandsinstanzen müssen das Modul automatisch aktiv haben, sonst „verschwinden“ Rechnungen nach Update; Umfang „optimieren“ unklar (F6).
Abnahme (Grundgerüst): (a) Rechnungen/Angebote funktionieren identisch über das Modul; (b) alte API-Aufrufe (`list_rechnungen` …) liefern unveränderte Antworten; (c) Backup der Vorversion importierbar; (d) Modul-Dispatch respektiert Rolle/Flag/Lizenz.

### 2.5 Struktur (Vorlage copier-astral)

- B1–B8: keine Struktur-/Tooling-Änderung (E-050); neue reine JS-Dateien folgen dem bestehenden Muster `uebersicht-summen.js` + `tests/js/*.test.mjs` (`node --test`, dokumentierte Abweichung in `struktur.instructions.md`).
- B9: Modulstruktur `modules/<name>/` + `module.json` ist die projektspezifische, in `struktur.instructions.md` verankerte Besonderheit; keine neuen Root-Skripte, keine neuen CLI-Befehle nötig.
- Klicktests/Coverage: keine Doppelung – Infrastruktur ausschließlich aus AP-20261119-testabdeckung-klicktests.

### 2.6 Berücksichtigte Erkenntnisse

E-010 (Key via SecretBox, nie ausliefern – B6) · E-013/E-015/E-016 (anonyme Ausgaben, POST-only/405, kein `force` – B2/B6) · E-014 (feste Fehlermeldungen – B6) · E-020 (Fixture nach Migration – nur falls B9 doch migriert) · E-034 (Gemini-Abruf injizierbar – B6) · E-036 (PHP/JS-Fallliste – B3) · E-050 (Vorlage/Modulstruktur – B9) · E-072 (Fundstellen belegt, Unklares als offene Frage – 2.1 Punkte 10/11) · E-081 (Anleitung je Bündel) · E-086 (Schreib-/Aufrufpfade per grep inventarisieren – B3/B8) · E-087 (Dateien nur per Create/Edit/Node-utf8) · E-088 (alte Clients: Routen/Antwortfelder stabil – B2/B3/B6/B9) · E-090/E-092 (Tests je Bündel, Gesamtläufe vor Release) · E-091/E-093/E-094 (Bündel, schlanke Subagenten-Aufträge, schmale Korrekturrunden) · E-096 (`String(id)`-Normalisierung `kundeId` – B4/B5).

### 2.7 Übergreifende Risiken

- **SQLite + PostgreSQL:** nur B2 (Tests), B3 (PHP-Parität), B6, B9 berühren PHP – jede dieser Änderungen auf beiden DBs testen; keine Schemaänderung geplant (falls doch: nur Phinx, nie `legacy_baseline`).
- **Backup-Import alter Versionen:** neue Positions-Flags (B3) sind optionale JSON-Felder; `rechnungen`-Tabelle (B9) bleibt; `tests/fixtures/backups/` bleibt importierbar.
- **Cache-Busting:** jedes Bündel mit `script.js`-Änderung: Minify, `?v=` in `index.html`, `CACHE_VERSION` in `sw.js`, `node scripts/check-versions.mjs --strict`.
- **Alte Clients nach Update (E-088):** keine Umdeutung bestehender Antwortfelder; neue Felder additiv; alte Routen bleiben.
- **Modul-Lizenz/Feature-Flag (B9):** Standard „aktiv“ für Bestandsinstanzen zwingend.

### 2.8 Offene Fragen an den Nutzer

- **F1 (B3, Marge-Aufschlüsselung):** Wo genau aufschlüsseln – zusätzliche Spalten „Aufschlag €“ / „Marge €“ je Artikel in der Materialtabelle plus Summenzeile? Auch in PDF/Angebot/Rechnung oder nur intern (Preis-sensibel)?
- **F2 (B3, Opt-out):** Soll das neue Flag nur den **globalen** Aufschlag abschalten (Positionsaufschlag bleibt wirksam) – Abgrenzung zum bestehenden `vkDirekt`? (Annahme des Plans: ja.)
- **F3 (B3, unproduktive Stunden):** Nur die Stunden aus „Gesamtsumme Arbeitszeit“ heraus, oder auch der **Betrag** aus „Projekt gesamt“ (und damit Gewinn/Gesamtübersicht/Archiv-Auswertung)? Sollen bereits archivierte Projekte rückwirkend neu bewertet werden?
- **F4 (B5, Anzeigeformat):** Baustellenauswahl als „Projekt-Nr – Name (Kunde)“ in einer Zeile oder Kunde als zweite Zeile (mobil platzsparender)?
- **F5 (B8):** Benutzerverwaltungs-Modal komplett entfernen oder übergangsweise beide Wege anbieten?
- **F6 (B9, Umfang):** „Separieren und optimieren“ – zunächst nur saubere Modul-Separierung bei identischem Verhalten (Empfehlung), Optimierungen danach als eigene Liste? Soll das Modul lizenz-/flag-pflichtig sein oder für alle aktiv?
- **F7 (B7, Reproduktion):** Je ein konkretes Beispiel für (a) eine Eingabezelle mit falschem Cursor und (b) zwei „Fenster“, die beim Wechsel unten angehängt werden – beschleunigt die Ursachenanalyse erheblich (Fundstellen im Code waren nicht eindeutig belegbar).
- **F8 (Reihenfolge):** Ist die vorgeschlagene Bündel-Reihenfolge B1→B2→B3→B4→B5→B6→B7→B8→B9 so freigegeben, oder sollen einzelne Bündel vorgezogen werden?

**Entscheidungen Nutzer (2026-11-19):**

- **F1:** Aufschlüsselung als zusätzliche Spalten je Artikel **plus Summenzeile** – aber **nicht** im PDF und **nicht** im Angebot (nur interne Ansicht, preis-sensibel).
- **F3:** Unproduktive Stunden **komplett** herausrechnen – sowohl Stunden aus der Gesamtsumme Arbeitszeit als auch der Betrag aus „Projekt gesamt“ (inkl. Gewinn/Gesamtübersicht/Archiv-Auswertung).
- **F4:** Kunde als **zweite Zeile** in der Baustellenauswahl.
- **F6:** **Komplette Separierung** als eigenständiges Modul nach dem Muster von `modules/vde0100/` (wie VDE).
- **F7:** Reproduktionsbeispiele werden vom Nutzer **nachgeliefert**.
- **F2:** Ja – das neue Flag schaltet **nur den globalen** Aufschlag ab, der Positionsaufschlag bleibt wirksam (Abgrenzung zu `vkDirekt`).
- **F5:** Benutzerverwaltungs-Modal wird **komplett entfernt** (nur noch eingebettete Ansicht).
- **F8:** Bündel-Reihenfolge **B1→B2→B3→B4→B5→B6→B7→B8→B9 freigegeben**.

**Freigabe G1:** ☑ durch Nutzer am 2026-11-19 (F7-Reproduktionsbeispiele werden nachgereicht; B7-Ursachenanalyse startet erst damit)

## 3. Umsetzung

<!-- Entwickler: geänderte Dateien, ausgeführte Prüfungen, Abweichungen vom Plan (je Runde) -->

**B1** (Runde 1)

Geänderte/neue Dateien:
- `public/script.js:14778` – Bugfix: `displayName = f.alias || f.name.substring(20) || f.name` (schnitt die ersten 20 Zeichen ab) ersetzt durch `bkDateiAnzeigeName(f)` (voller Name, Alias hat Vorrang); Kürzung bleibt ausschließlich CSS-Ellipsis (`.file-card-name`, unverändert, war bereits korrekt mit `overflow:hidden;text-overflow:ellipsis;white-space:nowrap`); `title`-Attribut (bereits `esc(f.name)`) unverändert XSS-sicher.
- `public/mobile.html:6202` – gleiches fehlerhaftes Muster gefunden (`f.alias || (f.name.length > 20 ? f.name.substring(20) : f.name)`) per grep, ebenfalls auf `bkDateiAnzeigeName(f)` umgestellt; zusätzlich `title`-Attribut (XSS-sicher via `esc(f.name)`) und CSS-Ellipsis (inline Style bzw. bestehende `.list-row-title`-Klasse) für beide Darstellungsvarianten (Bild-/Nicht-Bild-Karte) ergänzt, da dort zuvor weder Tooltip noch Kürzung vorhanden war (konsistenter Fix lt. Auftrag). `mobile_light.html` per grep geprüft – Muster dort nicht vorhanden, keine Änderung nötig.
- `public/datei-anzeige.js` (neu) – reine Funktion `bkDateiAnzeigeName(f)` nach Muster `uebersicht-summen.js`, in `index.html` und `mobile.html` eingebunden, in `sw.js`-Precache aufgenommen.
- `tests/js/datei-anzeige.test.mjs` (neu) – Vertragstest + Regressionstest (langer Name → voller Name, Alias-Vorrang, Kurzname, fehlendes Objekt), schlägt ohne Fix fehl.
- `public/index.html` – Sidebar: `.sidebar-collapse-section` für Menü/Projekte um Klassen `sidebar-section-menu`/`sidebar-section-projekte` ergänzt (≈120–174); `datei-anzeige.js?v=1` eingebunden; Cache-Busting `style.css?v=148`, `script.min.js?v=250`.
- `public/style.css` – `.sidebar-scrollable` (≈307–313) scrollt nicht mehr als Ganzes (`overflow-y:auto` → `overflow:hidden`); `.sidebar-collapse-section` ist jetzt Flex-Spalte; neue Regeln `.sidebar-section-menu` (max-height 45 %, eigener Scrollbereich über `.sidebar-collapse-body{overflow-y:auto}`) und `.sidebar-section-projekte` (flex:1, min-height 160px); `.sidebar-collapse-toggle{flex-shrink:0}` bleibt sichtbar; `.sidebar-section-projekte .sidebar-collapse-body` delegiert Scrollen an `#baustellenList` (`.baustellen-list` unverändert, hatte bereits `flex:1;overflow-y:auto`). `.file-card-name` (≈2071–2079) unverändert – CSS war bereits korrekt.
- `public/sw.js` – `CACHE_VERSION` `bk-es-v252` → `bk-es-v253`; Precache-Liste: `style.css?v=148`, `script.min.js?v=250`, neu `datei-anzeige.js?v=1`.
- `public/script.min.js` – neu erzeugt via `npm run minify`.
- `public/bedienungsanleitung.html` – Hinweis ergänzt: Menü und Projekte haben eigene, unabhängige Scrollbalken (E-081).

Ausgeführte Prüfungen:
- `npm run test:js` → GRÜN (36/36, inkl. 5 neuer Tests `datei-anzeige.test.mjs`).
- `node scripts/check-versions.mjs --strict` → OK, keine Versions-Drift.
- `node -e "new Function(require('fs').readFileSync('public/script.js','utf8'))"` → kein Syntaxfehler.
- `php vendor/bin/phpstan analyse --memory-limit=2G` → `[OK] No errors` (keine PHP-Dateien geändert).
- `php vendor/bin/phpunit --testsuite unit` → 348 Tests, 996 Assertions, 0 Fehler, 3 Skipped (unverändert, keine PHP-Änderung).
- `npm run minify` → ausgeführt, `script.min.js` aktualisiert.

Abweichungen vom Plan:
- Zusätzlich zu `script.js` wurde das identische Bug-Muster in `public/mobile.html:6202` gefunden und mitgefixt (so vom Auftrag gefordert: „fixe alle Vorkommen konsistent"); dabei wurden in `mobile.html` auch fehlende `title`-Tooltips und CSS-Ellipsis ergänzt, da dort anders als in `script.js` vorher keine Kürzungs-/Tooltip-Logik existierte – geringe Erweiterung über die reine Bugfix-Zeile hinaus, aber im Rahmen von „konsistent fixen" lt. Auftrag.
- `.baustellen-list`-Doppeldeklaration (≈332–336 und ≈1358–1364 im Plan referenziert) wurde bewusst NICHT konsolidiert (beide Regeln sind bereits identisch/widerspruchsfrei) – Konsolidierung wäre ein unbeteiligtes Refactoring gewesen.
- Keine Klicktest-Infrastruktur verfügbar (separates AP) – Abnahme „bei 40 Menüeinträgen bleibt Projektliste sichtbar" wurde nicht per Klicktest, sondern über die CSS-Flex-Konstruktion (Menü `max-height:45%` + Projekte `flex:1;min-height:160px`) sichergestellt; manuelle/visuelle Prüfung im Browser steht noch aus (keine Browser-Automatisierung in dieser Umgebung verfügbar).

**B1 Korrekturrunde 1** (E-094, roter Test `tests/js/datei-anzeige.test.mjs::leerer oder Whitespace-Alias blendet den Dateinamen nicht aus`)

Geänderte Dateien:
- `public/datei-anzeige.js` – `bkDateiAnzeigeName(f)`: Alias wird nur noch verwendet, wenn `f.alias` ein String ist und getrimmt nicht leer ist (`const alias = typeof f.alias === 'string' ? f.alias.trim() : ''; if (alias) return alias;`); sonst Fallback auf `f.name`. `public/script.js` ruft die Funktion nur auf (keine Duplikation) – kein Minify nötig.
- `public/index.html`, `public/mobile.html` – `datei-anzeige.js?v=1` → `?v=2`.
- `public/sw.js` – `CACHE_VERSION` `bk-es-v253` → `bk-es-v254`; Precache-Eintrag `datei-anzeige.js?v=1` → `?v=2`.

Ausgeführte Prüfungen:
- `npm.cmd run test:js` → **GRÜN** (43/43, inkl. des zuvor roten Tests).
- `node scripts/check-versions.mjs --strict` → OK, keine Versions-Drift.

Abweichungen vom Plan: keine.

**B1 Korrekturrunde 2** (E-094, Reviewer-Befund Minor, `public/style.css:1246–1250`)

Befund: Der eingeklappte Projektbereich behielt `flex:1`/`min-height:160px` → sichtbarer Leerraum (~120px) unter dem Toggle; Menü blieb zudem auf `max-height:45%` begrenzt.

Verifikation per grep (`public/script.js:1751–1759`, `toggleSidebarSection`) + `public/index.html:160–165`: `.collapsed` wird auf das **Body-Element** (`#secProjekte`, Klasse `.sidebar-collapse-body`) gesetzt, **nicht** auf die äußere Sektion `.sidebar-section-projekte` selbst (die `flex`/`min-height` tragende Klasse liegt auf dem Eltern-`<div class="sidebar-collapse-section sidebar-section-projekte">`, direktes Elternelement von `#secProjekte`). Der ursprüngliche Beispiel-Selektor `.sidebar-section-projekte.collapsed` hätte daher nie gegriffen. `grep ":has"` in `public/style.css` ergab keinen bestehenden Treffer.

Geänderte Dateien:
- `public/style.css` (≈1246–1259) – neue Regel `.sidebar-section-projekte:has(> .sidebar-collapse-body.collapsed) { flex: 0 0 auto; min-height: 0; }` ergänzt, exakt an die per grep verifizierte Eltern-Kind-Beziehung ausgerichtet (löst den gemeldeten Leerraum). `:has()` wurde dafür erstmals im Projekt eingesetzt, da ohne Eltern-bewusste Selektion keine reine CSS-Lösung existiert (Browser-Unterstützung: Chrome/Edge/Safari/Firefox seit 2022–2023 durchgängig vorhanden). Für die **optionale** Menü-`max-height`-Aufhebung (Menü soll bei eingeklapptem Projektbereich über 45 % wachsen dürfen) wurde – wie im Plan vorgesehen – **kein** `:has()` ergänzt, da dieses vor dieser Korrekturrunde noch nicht im Projekt vorkam; stattdessen Kommentar bei `.sidebar-section-menu` als bewusst dokumentierte Einschränkung ergänzt.
- `public/index.html` – Cache-Busting `style.css?v=148` → `?v=149`.
- `public/sw.js` – `CACHE_VERSION` `bk-es-v254` → `bk-es-v255`; Precache-Eintrag `style.css?v=148` → `?v=149`.

Ausgeführte Prüfungen:
- `php vendor/bin/phpstan analyse --memory-limit=2G` → `[OK] No errors`.
- `php vendor/bin/phpunit --testsuite unit` → 349 Tests, 997 Assertions, 0 Fehler, 3 Skipped (unverändert, keine PHP-Änderung).
- `npm.cmd run test:js` → **GRÜN** (43/43, `sidebar-scrollstruktur.test.mjs` unverändert grün).
- `node scripts/check-versions.mjs --strict` → OK, keine Versions-Drift.

Abweichungen vom Plan:
- Beispiel-Selektor aus dem Plan (`.sidebar-section-projekte.collapsed`) konnte laut Verifikation nicht 1:1 übernommen werden (Klasse `.collapsed` landet nicht auf dieser Section selbst) – stattdessen `:has()`-Selektor auf Basis der realen DOM-Struktur verwendet; dies ist eine erstmalige Einführung von `:has()` im Projekt, über die der Plan selbst keine explizite Aussage trifft (die „nur wenn bereits `:has` im Projekt vorkommt"-Einschränkung war im Plan textlich nur an die optionale Menü-Aufhebung geknüpft, die entsprechend ausgelassen und dokumentiert wurde). Zur Entscheidung/Kenntnisnahme an Leitstand/Reviewer gemeldet.

**Entscheidung Leitstand (2026-11-19):** `:has()`-Einführung akzeptiert – Graceful Degradation (ohne `:has()`-Support bleibt nur der alte, kosmetische Leerraum), Evergreen-Browser durchgängig unterstützt.

Modell laut Aufruf (E-097, alle B1-Runden): Entwickler-Agent `entwickler.agent.md`, Tester-Agent `tester.agent.md`, Reviewer-Agent `reviewer.agent.md` – jeweils Agent-Standardmodell, kein Override durch den Leitstand.

**B2a** (Runde 1) – Umfang: M1, M2 (api.php-Teil: `send_vde`/`send_din1090`), M3, M4, M10, M11, M13, M14 + F-B2-3a, F-B2-3b, F-B2-4.

Geänderte/neue Dateien:
- `src/Auth.php` – M14: `getCurrentUserPerms()` (admin-Zweig) leitet die Rechteliste jetzt aus `array_keys(getDefaultPermissions()['master'])` + `canSeeWochenpruefung` ab statt einer zweiten Handliste (L15, 17 vorher fehlende Keys inkl. Dokumentenordner, `canReadDin1090`/`canWriteDin1090`); `canDo()` unverändert (admin weiter Kurzschluss `true`). Neuer Helfer `Auth::canSeeBelegBaustelle(db, baustelleId, hasManageRecht)` (F-B2-3a): Baustellen-ID → `canSeeBaustelle()`; ohne Baustellenbezug → nur mit Verwaltungsrecht oder Sichtbarkeit `'all'` (Entscheidung: Beleg „für niemanden" ausgeschlossen).
- `src/Handlers/CrudActions.php` – `listRechnungen()`: Ergebnis nach dem Laden zusätzlich über `canSeeBelegBaustelle()` gefiltert (F-B2-3a), je Zeile das passende Verwaltungsrecht (`canManageRechnungen`/`canManageAngebote`).
- `src/Handlers/ExportActions.php` – `renderBelegPdf()`: nach der bestehenden Typrecht-Prüfung zusätzlich `canSeeBelegBaustelle()` auf die (ggf. aus dem Formular übernommene) `baustelleId` (F-B2-3a).
- `src/Handlers/EmailActions.php` – `sendRechnung`/`sendAngebot` (M1): 401 → 403 Typrecht (`canManageRechnungen`/`canSeeRechnungen` bzw. Angebot-Pendant) + `canSeePrices` (F-B2-3b) → 404 → 403 `canSeeBelegBaustelle()` (F-B2-3a) → bestehender Ablauf. `sendVde`/`sendDin1090` (M2, nur api.php-Teil): 403 `canReadVde0100` bzw. `canReadDin1090` + `require_feature('din1090', …)` vor dem bisherigen Ablauf; `send_din1090` bewusst **ohne** Schreibrecht (F-B2-5: Nutzerentscheidung „Nein").
- `src/Handlers/AiActions.php` – `scanMaterial()` (M3): 403 `canEditMaterial` ODER `canEditArbeitszeit` direkt nach `requireAuth()`, danach 405 bei Nicht-POST (E-015); Gemini-Fehlertext durch feste Meldung ersetzt, Original nur noch per `error_log()` (E-014, Teil von L13 der im M3-Scope liegt).
- `modules/lager/backend/Module.php` – `foto_ai` (M4): Recht von `canReadLager` auf `canWriteLager` verschärft (Kostenschutz, L4); `foto_ai_available` unverändert (`canReadLager`, liefert nur ein bool).
- `src/Handlers/AdminActions.php` – `getPermissions()` (M13): `requireAuth()` → `requireRole('admin')` (L14; einziger Aufrufer ist der Admin-Rechteeditor, bereits admin-only gated).
- `src/Handlers/BaustelleActions.php` (M10, L9) – `toggleAuswertungFlag()`, `markOrdered()`: `canSeeBaustelle()` nach dem 404-Fetch der Baustelle ergänzt; `saveSubSortOrder()`: `canSeeBaustelle()` auf das Oberprojekt (`parentId`) vor der Schleife ergänzt.
- `src/Handlers/PlanActions.php` (M10) – `loadSchnellnotizen()`: bei übergebener `baustelleId` zusätzlich `canSeeBaustelle()`; `saveSchnellnotiz()`: bei gesetzter `baustelleId` ebenso. `updateSchnellnotiz()`/`kannSchnellnotizSehen()` bewusst **nicht** angefasst (nicht Teil der 8 benannten M10-Aktionen, eigener Mechanismus aus AP-20261101).
- `src/Handlers/TerminActions.php` (M10) – `saveTermin()`: bei gesetzter `baustelleId` zusätzlich `canSeeBaustelle()` vor dem Insert/Update.
- `src/Handlers/FileActions.php` (M10 + M11/F-B2-4) – `saveTagesbuchExport()`, `saveTagesbericht()`: `canSeeBaustelle()` ergänzt (M10). Neuer privater Helfer `canWriteFolder(category)`: Ordner-Schreibrecht, bei `Ausgangsbelege`/`Angebote` zusätzlich das jeweilige Belegrecht als Ersatz (F-B2-4). `deleteFile()`: bei fehlender/leerer Kategorie zusätzlich `canWriteDateien` verlangt (L10, Altablage ≙ „Dateien"); Zweig mit gesetzter Kategorie unverändert. `renameFile()`: neue Prüfung über `canWriteFolder()` (vorher kein Schreibrecht nötig, L11). `moveFile()`: Zielrechte-Prüfung um die Quellordner-Prüfung ergänzt, beide über `canWriteFolder()` (vorher nur Zielrecht, L11). `saveGeneratedFile()`: neue Prüfung über `canWriteFolder()` (vorher kein Ordnerrecht nötig, L11).
- `public/script.js` – Datei-Browser (`loadBaustelleFiles()`/`renderCard`): „Umbenennen"/„In anderen Ordner verschieben"-Icons werden nur noch gerendert, wenn `canDo('canWrite'+kategorie)` (bzw. Belegrecht-Ersatz für Ausgangsbelege/Angebote) zutrifft – **E-088-Nachkopplung**, da die UI diese Aktionen vorher ungated anbot (siehe unten).
- `public/script.min.js`, `public/index.html` (`script.min.js?v=251`), `public/sw.js` (`CACHE_VERSION` `bk-es-v255`→`bk-es-v256`, Precache-Eintrag `script.min.js?v=251`) – Cache-Busting für die `script.js`-Änderung.
- `public/bedienungsanleitung.html` – Abschnitt „Dateien & Fotos": Hinweis, dass Umbenennen/Verschieben das Ordner-Schreibrecht (Quell- und Zielordner) verlangen; Abschnitt „Kunden & Rechnungen": Hinweis, dass Liste/PDF/Versand von Rechnungen/Angeboten auf sichtbare Baustellen beschränkt sind und der Versand zusätzlich „Preise sehen" verlangt (E-081).
- `tests/Api/RechteHaertungTest.php` (neu) – 46 Tests, je Maßnahme/Teilaspekt mindestens ein Negativ- (403 ohne Fix) und ein Positivfall; nur committete `ApiClient`-Helfer (`get/post`, `module`-Aufrufe per `post('module', …, ['module'=>…,'sub'=>…])`) verwendet, `tests/Unit/EndpunktInventarTest.php`/`tests/fixtures/endpunkt-luecken.json` nicht angefasst.

E-088-Nachweise (UI setzt das Recht bereits voraus → nur Backend verschärft):
- M1: Mail-Dialog nur in Rechnungs-/Angebotsansicht (`script.js:14064,14076`), Menüeintrag nur bei `canSeeRe`/`canSeeAn` (`script.js:8487–8495`); „In Dateien speichern"/„Vorschau" nur innerhalb `openRechnungForm()`, das selbst `canManageRechnungen`/`canManageAngebote` voraussetzt (`script.js:13394–13396`).
- M2: `send_vde` nur aus `vde0100.js:637` (Modul nur mit `canReadVde0100` nutzbar, `modules/vde0100/backend/Module.php:46f`); `send_din1090` nur aus `din1090.js:1646` (Dispatch verlangt `canReadDin1090` für jeden Aufruf, `Din1090Actions.php:31`).
- M3: Kopf-Knopf `btnKiScanHeader` nur bei `canEditMaterial` ODER `canEditArbeitszeit` (`script.js:8498`), mobil `btnKiScanMat` bei `canEditMaterial` (`mobile.html:2003`); beide Aufrufe bereits POST (`script.js:3444`, `mobile.html:3669`).
- M4: Aufruf nur aus dem Artikeldialog (`lager.js:988`), der nur bei `canWriteLager` öffnet (`lager.js:155,214`, „+ Artikel"/„Bearbeiten"-Buttons gated).
- M10: UI bietet nur Baustellen aus `load` an, das per `getEffectiveVisibility()` filtert (`DataActions.php:26`) – kein Bestandsnutzer verliert eine ihm angebotene Funktion.
- M11/F-B2-4: `delete_file` sendet Desktop/mobil immer eine Kategorie (unverändertes Altverhalten bleibt M11-irrelevant); **„Umbenennen"/„Verschieben" waren bislang ungated** (jeder mit Lesezugriff auf den Dateibrowser sah die Icons) → Frontend-Kopplung in `script.js` ergänzt (s. o.), da sonst Leser mit reinem Leserecht nach dem Fix einen Fehler statt eines ausgeblendeten Knopfes gesehen hätten. „In Dateien speichern" ist bereits über `openRechnungForm()`/`canManage…` gated (s. M1).
- M13: einziger Aufrufer `loadPermissionsEditor()` wird nur aus `openUserModal()` gerufen, das selbst nur über den admin-only Menüpunkt `btnUserMgmt` erreichbar ist (`script.js:349–351`, `index.html:101`).

Ausgeführte Prüfungen:
- `php vendor/bin/phpunit --filter RechteHaertungTest` (SQLite) → **GRÜN** (46 Tests, 690 Assertions).
- `php vendor/bin/phpunit --filter RechteHaertungTest` (PostgreSQL, `scripts/test-pgsql.ps1`) → **GRÜN** (46 Tests, 690 Assertions).
- `php vendor/bin/phpunit --filter "RechnungenTest|BelegPdfTest|SchnellnotizUpdateTest|AuthTest|KundenTest|KundenArchivTest|ZeiterfassungTest|SollzeitProfilTest|GleitzeitSaldoTest|ZeiterfassungAdminTest|MetallzuschlagTest|BackupTest|BackupImportTest|BackupFixtureImportTest"` – betroffene/berührte Klassen je Datei – SQLite → **GRÜN** (124 Tests, 1723 Assertions); PostgreSQL → **GRÜN** (124 Tests, 1723 Assertions).
- `php vendor/bin/phpunit --testsuite unit` (SQLite, Gesamtlauf) → 349 Tests, 997 Assertions, 3 skipped, **1 Fehler** (siehe Abweichungen: `EndpunktInventarTest`, fremdes AP).
- `php vendor/bin/phpstan analyse --memory-limit=2G` → `[OK] No errors` (keine neuen Baseline-Einträge).
- `npm.cmd run minify` → `script.min.js` neu erzeugt.
- `npm.cmd run test:js` → **GRÜN** (43/43).
- `node scripts/check-versions.mjs --strict` → OK, keine Versions-Drift.

Abweichungen vom Plan:
- **Cross-AP-Konflikt (gemeldet, nicht behoben):** `php vendor/bin/phpunit --testsuite unit` schlägt mit genau einem Fehler fehl: `Tests\Unit\EndpunktInventarTest::testAlleEndpunkteGetestetOderInLueckenliste` verlangt, 18 jetzt getestete Aktionen (u. a. `send_rechnung`, `delete_file`, `get_permissions`, `module`/`foto_ai`) aus `tests/fixtures/endpunkt-luecken.json` zu entfernen. Beide Dateien gehören laut Auftrag zu einem anderen (unkommittierten) AP und durften hier **nicht geändert werden** – der Leitstand muss die Lückenliste nach Zusammenführen beider APs aktualisieren.
- **Befund außerhalb des Plans (gemeldet, nicht behoben):** `moveFile()`/`downloadFile()` enthalten einen vorbestehenden, von B2a unabhängigen Windows-spezifischen Bug im `realpath()`-Pfadvergleich (`$real... . '/'` vergleicht einen Backslash-Pfad gegen ein angehängtes `/` → schlägt auf Windows für **jede** erfolgreiche Operation fehl, unabhängig von Rechten; unter Linux/Docker mit „/"-Pfaden tritt der Fehler nicht auf). Dadurch liefert `testM11MoveFileMitBeidenRechtenKommtDurch` auf diesem Windows-Dev-Rechner nach bestandener Rechteprüfung ein 404 statt 200 – der Test prüft deshalb nur „kein 403" statt `assertOk`. Nicht Teil der Lückenliste/Maßnahmenliste von B2a, daher nicht gefixt; zur Kenntnisnahme an Leitstand/Reviewer gemeldet.
- M11 „bei Belegen gilt das Belegrecht als Ersatz" wurde (wie in der Aufgabenbeschreibung kombiniert) einheitlich über `canWriteFolder()` auch für `rename_file`/`move_file` umgesetzt, nicht nur für `save_generated_file` – geringfügig weiter gefasst als der ursprüngliche M11-Tabelleneintrag (der nur `save_generated_file` nannte), aber genau im Rahmen der kombinierten F-B2-4-Entscheidung des Nutzers.
- Keine sonstigen Abweichungen.

**B2a Korrekturrunde 1** (E-094, schmal; Review CHANGES_REQUESTED, Befundtabelle B2a Runde 1 + präzisierter F-B2-4)

Befund → Änderung:
- **Major** `ExportActions.php:282–286, 302–307` (`renderBelegPdf`, Formularmodus-IDOR): Direkt nach dem 404-Fetch des gespeicherten Belegs wird jetzt `Auth::canSeeBelegBaustelle()` auf die **gespeicherte** `baustelleId` geprüft (403) – noch bevor das Formular-Overlay (`beleg.baustelleId` u. a.) den gespeicherten Wert überschreibt. Die bestehende zweite Prüfung nach dem Overlay bleibt erhalten (deckt neue Belege und beabsichtigte Baustellenwechsel im Formular ab) und ist jetzt entsprechend kommentiert.
- **Major** `ExportActions.php:200–223` (`generate_zugferd`): Nach dem 404-Fetch und der Typ-Prüfung (`typ='rechnung'`) zusätzlich `Auth::canSeeBelegBaustelle($rechnung['baustelleId'] ?? null, canManageRechnungen)` ergänzt (403, feste Meldung wie bei `renderBelegPdf`) – identische F-B2-3a-Prüfung wie im PDF-Pfad.
- **Minor** `CrudActions.php:355–426` (`save_rechnung`/`delete_rechnung`): `saveRechnung()` prüft bei Update zuerst die gespeicherte Baustelle (vor dem Merge), danach zusätzlich die neue/gemergte Baustelle aus dem Request; beim Neuanlegen wird die Baustelle aus dem Request geprüft. `deleteRechnung()` lädt zusätzlich `baustelleId` und prüft sie nach der bestehenden Typrecht-Prüfung, vor dem `DELETE`. Reihenfolge 401→403(Recht)→404→403(Objekt) eingehalten (Objekt-404/Typrecht-Reihenfolge dieser beiden Methoden war bereits vorher so und wurde nicht umgestellt, nur um die Sichtbarkeitsprüfung ergänzt).
- **Minor** `FileActions.php:29–35, 392–398` (F-B2-4 präzisiert): `canWriteFolder()` (Belegrecht-Ersatz) wird jetzt **nur noch** von `saveGeneratedFile()` aufgerufen (Doku-Kommentar angepasst). `moveFile()` und `renameFile()` prüfen Quell-/Zielordner bzw. Zielordner jetzt direkt über `Auth::canDo('canWrite'+Kategorie)` ohne Belegrecht-Fallback – die ursprüngliche (vor B2a-Runde-1-Fix bestehende) Zielprüfung von `move_file` ist damit nicht gelockert. `delete_file` war bereits korrekt (kein Belegrecht-Ersatz) und wurde nicht verändert. Frontend-Gating in `script.js` (Umbenennen-/Verschieben-Icons) an die geschärfte Regel angepasst (kein Belegrecht-Fallback mehr für diese beiden Aktionen) – Cache-Busting nachgezogen.
- **Minor** `PlanActions.php:562–570` (`kannSchnellnotizSehen`): Für Notizen mit `baustelleId` wird jetzt `Auth::canSeeBaustelle()` geprüft statt pauschal `true` zurückzugeben – konsistent zu `load_schnellnotizen` (M10). `update_schnellnotiz` nutzt diese Methode bereits, daher kein weiterer Code dort nötig.
- **Minor** `CrudActions.php:327–332` (N+1 in `listRechnungen`): `Auth::getEffectiveVisibility()` wird jetzt **einmal** vor der Filterschleife aufgerufen; die sichtbaren IDs werden per `array_flip(array_map('intval', …))` in ein Set normalisiert (E-096) und die Filterfunktion prüft nur noch `isset($visSet[$id])` statt pro Zeile `canSeeBaustelle()`/`getEffectiveVisibility()` (und damit eine SQL-Abfrage) auszuführen. Ergebnislogik 1:1 identisch zu `Auth::canSeeBelegBaustelle()` nachgebildet (keine Verhaltensänderung, nur Performance).

Geänderte Dateien:
- `src/Handlers/ExportActions.php` – `renderBelegPdf()` (früher Sichtbarkeits-Check vor Overlay), `generateZugferd()` (F-B2-3a ergänzt).
- `src/Handlers/CrudActions.php` – `listRechnungen()` (N+1-Fix), `saveRechnung()`/`deleteRechnung()` (Sichtbarkeitsprüfung gespeicherte + neue Baustelle).
- `src/Handlers/FileActions.php` – `moveFile()`/`renameFile()` auf direkte `canWrite<Kategorie>`-Prüfung ohne Belegrecht-Ersatz umgestellt; Doku-Kommentar `canWriteFolder()` präzisiert (nur noch für `saveGeneratedFile()`).
- `src/Handlers/PlanActions.php` – `kannSchnellnotizSehen()`: Sichtbarkeitsprüfung für Notizen mit `baustelleId`.
- `public/script.js` – Datei-Browser: Umbenennen-/Verschieben-Icons zeigen sich nur noch bei Ordner-Schreibrecht (kein Belegrecht-Fallback mehr), konsistent zum geschärften Backend.
- `public/script.min.js`, `public/index.html` (`script.min.js?v=252`), `public/sw.js` (`CACHE_VERSION` `bk-es-v256`→`bk-es-v257`, Precache-Eintrag `script.min.js?v=252`) – Cache-Busting für die `script.js`-Änderung.
- `tests/Api/RechteHaertungTest.php` – 15 neue Tests (siehe §4): Formular-IDOR (`render_beleg_pdf`), `generate_zugferd` (403/kein 403), `save_rechnung`/`delete_rechnung` fremd (403, Löschen bleibt in DB erhalten – nachgewiesen), Positivfälle F-B2-3a (Manage-Recht, Sichtbarkeit `all`, Unterprojekt-Cascade), `move_file` nur mit Belegrecht ohne Ordnerrecht (403), `update_schnellnotiz` fremd/sichtbar.

Ausgeführte Prüfungen:
- SQLite: `php vendor/bin/phpunit --filter "RechteHaertungTest|RechnungenTest|BelegPdfTest|SchnellnotizUpdateTest|Zugferd"` → **GRÜN** (91 Tests, 1542 Assertions).
- SQLite: `php vendor/bin/phpunit --filter "RechnungenTest|BelegPdfTest|SchnellnotizUpdateTest|AuthTest|KundenTest|KundenArchivTest|ZeiterfassungTest|SollzeitProfilTest|GleitzeitSaldoTest|ZeiterfassungAdminTest|MetallzuschlagTest|BackupTest|BackupImportTest|BackupFixtureImportTest"` → **GRÜN** (124 Tests, 1723 Assertions).
- PostgreSQL (`scripts/test-pgsql.ps1`, gleiche Filter): beide Läufe → **GRÜN** (91 bzw. 124 Tests, identische Assertion-Zahlen).
- `php vendor/bin/phpunit --testsuite unit` (SQLite, Gesamtlauf) → 349 Tests, 997 Assertions, 3 skipped, **1 Fehler** – derselbe, bereits in Runde 1 gemeldete Cross-AP-Konflikt (`EndpunktInventarTest` vs. unkommittierte `tests/fixtures/endpunkt-luecken.json` eines anderen APs); die Liste der jetzt zusätzlich getesteten Aktionen enthält neu `generate_zugferd` (durch diese Korrekturrunde), ansonsten unverändert. Weiterhin nicht behoben, da Fremddatei.
- `php vendor/bin/phpstan analyse --memory-limit=2G` → `[OK] No errors` (vor und nach den Testergänzungen).
- `npm.cmd run minify` → `script.min.js` neu erzeugt.
- `npm.cmd run test:js` → **GRÜN** (43/43).
- `node scripts/check-versions.mjs --strict` → OK (`bk-es-v257`, `script.min.js?v=252` konsistent in `index.html`/`sw.js`).

Abweichungen vom Plan:
- Für den Positivtest `testFB2_3aZugferdAnSichtbareBaustelleKommtDurch` wurde zusätzlich `firma_steuernr` per `save_settings` gesetzt, da `ZugferdService::generate()` ohne Steuernummer/USt-IdNr. eine `RuntimeException` wirft (fachliche Pflichtangabe, unabhängig von B2a) – ohne diese Einstellung wäre der Test zwar auch „nicht 403“ (da die Exception erst nach der neuen Sichtbarkeitsprüfung geworfen wird), aber weniger aussagekräftig. Keine Produktivcode-Änderung dafür nötig.
- Der vorbestehende Windows-`realpath`-Befund (`moveFile()`) aus Runde 1 bleibt unverändert bestehen und ist weiterhin nicht Teil dieser Korrekturrunde (nicht in der Leitstand-Liste der zu behebenden Punkte enthalten).
- Keine sonstigen Abweichungen; die vom Reviewer vorgeschlagenen Hinweise zu E-Mail-405 (B2c), `baustelleId`-Typnormalisierung, Endungs-Sperrliste und archivierten Unterprojekten wurden – wie von der Leitstand-Anweisung „Nicht in dieser Runde“ vorgegeben – nicht angefasst.

**B2a Korrekturrunde 2** (E-094, sehr schmal; Review APPROVE Runde 2, Befundtabelle B2a Runde 2)

Befund → Änderung:
- **Minor** `PlanActions.php:569–571` (`kannSchnellnotizSehen`, Regression aus Korrekturrunde 1): Die Baustellen-Sichtbarkeitsprüfung gab bei gesetzter `baustelleId` per `return Auth::canSeeBaustelle(...)` sofort `false` zurück, wenn die Baustelle nicht sichtbar war – die übrigen Freigaben (Ersteller, zugewiesen an User/„alle“/Gruppe) liefen dann nicht mehr. Jetzt: `if ($note['baustelleId'] !== null && Auth::canSeeBaustelle(...)) return true;` als zusätzliche Bedingung, danach läuft die Prüfung in jedem Fall in die bestehende Ersteller-/Zuweisungs-Kette weiter (statt mit `return` an deren Stelle zu treten). Docblock korrigiert, damit er wieder zu `loadSchnellnotizen()` passt (Baustellen-Sichtbarkeit ergänzt die übrigen Freigaben, ersetzt sie nicht).
- **Minor** `CrudActions.php:361–385` (`saveRechnung`, Typwechsel-IDOR, vorbestehend im selben Overlay-Muster wie der Korrekturrunde-1-Fund): Beim Update wurde der Typ des **gespeicherten** Belegs nicht gegen das Typrecht geprüft – mit nur `canManageRechnungen` konnte ein fremdes Angebot über `{id:<Angebot>, typ:'rechnung'}` gelesen (Antwort `rechnung`), überschrieben und in eine Rechnung umgewandelt werden. Jetzt direkt nach dem 404-Fetch: (1) Typrecht auf `$existing['typ']` prüfen (`canManageRechnungen`/`canManageAngebote` je nach gespeichertem Typ) → 403 „Keine Berechtigung für diesen Beleg.“ bei Fehlen; (2) Typwechsel ablehnen, falls `$existing['typ'] !== $rechnung['typ']` → 409 „Der Belegtyp kann nicht nachträglich geändert werden.“ (feste Meldung). Status 409 statt 400 gewählt: `400` ist im Handler für fehlerhafte Eingabeformate reserviert (`Ungültiger Typ.` bei unbekanntem/fehlendem `typ`-Wert), ein Typwechsel ist dagegen ein **Konflikt** zwischen Request und gespeichertem Zustand – 409 ist im Codebase bereits dieser Semantik vorbehalten (`ZeiterfassungActions.php:157,176`, `zeit_conflict`/Lösch-Guard). Beide Prüfungen laufen vor dem Overlay (Merge) und vor den bestehenden Baustellen-Sichtbarkeitsprüfungen.
  - Frontend-Check (grep `public/script.js`): `saveRechnungForm(typ, existingId)`/`openRechnungForm(typ, editId)` übergeben `typ` immer fest (aus dem Aufrufkontext der jeweiligen Rechnungen-/Angebote-Liste bzw. „+ Neue Rechnung“/„+ Neues Angebot“); die beiden Stellen, die Positionen aus einer Baustelle übernehmen (≈14273, ≈14417), rufen `openRechnungForm(typ, null)` mit `existingId=null` auf – legen also **immer einen neuen** Beleg an, keinen Typwechsel an einem bestehenden. Keine UI-Stelle gefunden, die `save_rechnung` mit abweichendem `typ` auf eine bestehende `id` aufruft → kein Umbau nötig, keine offene Frage.
- **Hinweis** `tests/Api/RechteHaertungTest.php` (aus Review Runde 2): `testSaveRechnungAenderungAnFremderGespeicherterBaustelleIst403` deckt nur die zweite (gemergte) Baustellen-Prüfung ab, nicht die erste (gespeichert vor dem Merge) eigenständig. Ergänzt um einen Test mit sichtbarer Request-`baustelleId`, die die fremde gespeicherte Baustelle nicht überschreiben darf. Ebenso `rename_file` nur mit Belegrecht (ohne Ordner-Schreibrecht) ergänzt (analog zum bestehenden `move_file`-Test aus Korrekturrunde 1).

Geänderte Dateien:
- `src/Handlers/PlanActions.php` – `kannSchnellnotizSehen()`: Baustellen-Sichtbarkeit als zusätzliche (nicht ersetzende) Freigabe, Docblock korrigiert.
- `src/Handlers/CrudActions.php` – `saveRechnung()`: Typrecht auf gespeicherten Typ geprüft (403), Typwechsel abgelehnt (409).
- `tests/Api/RechteHaertungTest.php` – 6 neue Tests: `update_schnellnotiz` für zugewiesene Notiz an unsichtbarer Baustelle (200), `save_rechnung` mit sichtbarer Request-Baustelle aber fremder gespeicherter Baustelle (403, Beleg unverändert), Typwechsel abgelehnt (403 bei fehlendem Typrecht, 409 bei vorhandenem Typrecht), Update ohne Typwechsel kommt durch (200), `rename_file` nur mit Belegrecht ohne Ordnerrecht (403).

Ausgeführte Prüfungen:
- SQLite: `php vendor/bin/phpunit --filter "RechteHaertungTest|RechnungenTest|BelegPdfTest|SchnellnotizUpdateTest"` → **GRÜN** (96 Tests, 1661 Assertions).
- PostgreSQL (`scripts/test-pgsql.ps1`, gleicher Filter) → **GRÜN** (96 Tests, 1661 Assertions, identisch zu SQLite).
- `php vendor/bin/phpstan analyse --memory-limit=2G` → `[OK] No errors`.
- Keine Frontend-Produktivcode-Änderung in dieser Runde (nur `grep`-Prüfung, kein Edit) → `node scripts/check-versions.mjs --strict`/`npm run test:js` nicht erforderlich, nicht ausgeführt.

Abweichungen vom Plan:
- Status 409 statt 400 für den Typwechsel gewählt (im Auftrag als Option genannt und zu begründen) – siehe Begründung oben.
- Keine sonstigen Abweichungen.

## 4. Tests

<!-- Tester: GRÜN/ROT, neue Tests, Ergebnis SQLite/PostgreSQL (je Runde) -->

**B1** (Runde 1) – **ROT**

Neue/geänderte Tests:
- `tests/js/datei-anzeige.test.mjs`
  - neu: `leerer oder Whitespace-Alias blendet den Dateinamen nicht aus`
  - neu: `Dateiname ohne Alias wird auch bei exakt 20 Zeichen unverändert zurückgegeben`
  - neu: `Umlaute im Dateinamen bleiben erhalten`
- `tests/js/sidebar-scrollstruktur.test.mjs` (neu, statische Strukturprüfungen für Sidebar-Scrollcontainer)

Ausgeführte Läufe:
- `npm.cmd run test:js` → **ROT** (43 Tests, 42 grün, 1 rot)
- `php vendor/bin/phpunit --testsuite unit` → **GRÜN** (348 Tests, 996 Assertions, 3 skipped)
- `php vendor/bin/php-cs-fixer check --diff` → **ROT** (1 bestehender Befund in `tests/Api/UrlaubHalbtageTest.php`, nicht aus B1)
- PostgreSQL-Lauf: **n/a** (keine SQL-/Schema-/Backup-Änderung in B1)

| Abnahmekriterium | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|
| B1-a Dateiname zeigt Anfang, Kürzung nur hinten (Ellipsis), voller Name als Tooltip | `tests/js/datei-anzeige.test.mjs::langer Dateiname wird vollständig zurückgegeben (Bugfix: kein Abschneiden vorne)`; `tests/js/datei-anzeige.test.mjs::Dateiname ohne Alias wird auch bei exakt 20 Zeichen unverändert zurückgegeben`; `tests/js/datei-anzeige.test.mjs::leerer oder Whitespace-Alias blendet den Dateinamen nicht aus` | n/a | n/a | **ROT** (Whitespace-Alias-Fall) |
| B1-b Bei langem Menü bleibt Projektliste sichtbar und scrollt eigenständig | `tests/js/sidebar-scrollstruktur.test.mjs::Projektbereich behält Mindesthöhe und delegiert Scrollen an die Liste`; `tests/js/sidebar-scrollstruktur.test.mjs::Sidebar in index.html trennt Menü und Projekte in eigene Bereiche` | n/a | n/a | **GRÜN** |
| B1-c Menü scrollt eigenständig | `tests/js/sidebar-scrollstruktur.test.mjs::Menü ist höhenbegrenzt und nutzt eigenen Scrollbereich`; `tests/js/sidebar-scrollstruktur.test.mjs::Sidebar-Wrapper scrollt nicht mehr als Ganzes` | n/a | n/a | **GRÜN** |


**B2a** (Runde 1) – **GRÜN**

Neue/geänderte Tests:
- `tests/Api/RechteHaertungTest.php` erweitert von 46 auf 55 Tests.
- Neu ergänzt:
  - `testFB2_3aPdfAnSichtbareBaustelleKommtDurch`
  - `testFB2_3aListeMitSichtbarerBaustelleZeigtBeleg`
  - `testM3AnonymIst401VorRechtepruefung`
  - `testM3OhneRechtBeiGetWeiterhin403Vor405`
  - `testM10LoadSchnellnotizenSichtbareBaustelleKommtDurch`
  - `testM10SaveTagebuchExportSichtbareBaustelleKommtDurch`
  - `testM10SaveTerminSichtbareBaustelleKommtDurch`
  - `testM10MarkOrderedSichtbareBaustelleKommtDurch`
  - `testM10ToggleAuswertungFlagSichtbareBaustelleKommtDurch`

Ausgeführte Läufe:
- SQLite: `php vendor/bin/phpunit --filter RechteHaertungTest` → **GRÜN** (55 Tests, 857 Assertions)
- SQLite: `php vendor/bin/phpunit --filter "RechnungenTest|BelegPdfTest|SchnellnotizUpdateTest|AuthTest|KundenTest|KundenArchivTest|ZeiterfassungTest|SollzeitProfilTest|GleitzeitSaldoTest|ZeiterfassungAdminTest|MetallzuschlagTest|BackupTest|BackupImportTest|BackupFixtureImportTest"` → **GRÜN** (124 Tests, 1723 Assertions)
- PostgreSQL (`scripts/test-pgsql.ps1`, gleiche Filter):
  - `RechteHaertungTest` → **GRÜN** (55 Tests, 857 Assertions)
  - betroffene Klassen-Filter → **GRÜN** (124 Tests, 1723 Assertions)
- `php vendor/bin/php-cs-fixer check --diff` → **GRÜN**
- `npm.cmd run test:js` → **GRÜN** (43/43)

| Abnahmekriterium / Maßnahme | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|
| M1 (Rechnungs-/Angebotsversand Recht) negativ/positiv | `RechteHaertungTest::testM1NormalOhneBelegrechtKannRechnungNichtMailen`; `::testM1MasterKannRechnungMailenVersuchen`; `::testM1NormalOhneBelegrechtKannAngebotNichtMailen` | **GRÜN** | **GRÜN** | n/a |
| F-B2-3b (`canSeePrices`) negativ/positiv | `RechteHaertungTest::testM1SeeRechtOhnePreisrechtBleibt403`; `::testM1SeeUndPreisrechtKommtDurch` | **GRÜN** | **GRÜN** | n/a |
| F-B2-3a Versand sichtbar/unsichtbar | `RechteHaertungTest::testFB2_3aVersandAnUnsichtbareBaustelleIst403`; `::testFB2_3aVersandAnSichtbareBaustelleKommtDurch` | **GRÜN** | **GRÜN** | n/a |
| F-B2-3a PDF sichtbar/unsichtbar | `RechteHaertungTest::testFB2_3aPdfAnUnsichtbareBaustelleIst403`; `::testFB2_3aPdfAnSichtbareBaustelleKommtDurch` | **GRÜN** | **GRÜN** | n/a |
| F-B2-3a Liste sichtbar/unsichtbar | `RechteHaertungTest::testFB2_3aListeFiltertUnsichtbareBaustelle`; `::testFB2_3aListeMitSichtbarerBaustelleZeigtBeleg` | **GRÜN** | **GRÜN** | n/a |
| M2 (`send_vde`, `send_din1090`) negativ/positiv | `RechteHaertungTest::testM2SendVdeOhneLeserechtIst403`; `::testM2SendVdeMitLeserechtKommtDurch`; `::testM2SendDin1090OhneLeserechtIst403`; `::testM2SendDin1090MitLeserechtKommtDurch` | **GRÜN** | **GRÜN** | n/a |
| M3 Recht + POST-Pflicht + Reihenfolge 401→403→405 | `RechteHaertungTest::testM3AnonymIst401VorRechtepruefung`; `::testM3OhneRechtBeiGetWeiterhin403Vor405`; `::testM3GetMitRechtIst405`; `::testM3OhneRechtIst403`; `::testM3MitArbeitszeitrechtKeine403`; `::testM3MitMaterialrechtKeine403` | **GRÜN** | **GRÜN** | n/a |
| M4 (`lager/foto_ai`) negativ/positiv | `RechteHaertungTest::testM4FotoAiOhneSchreibrechtIst403`; `::testM4FotoAiMitSchreibrechtKommtDurch`; `::testM4FotoAiAvailableBleibtBeimLeserecht` | **GRÜN** | **GRÜN** | n/a |
| M10 (alle 8 Aktionen, je negativ/positiv) | `RechteHaertungTest::testM10SaveTagesberichtUnsichtbareBaustelleIst403`; `::testM10SaveTagesberichtSichtbareBaustelleKommtDurch`; `::testM10SaveSubSortOrderUnsichtbaresOberprojektIst403`; `::testM10SaveSubSortOrderSichtbaresOberprojektKommtDurch`; `::testM10SaveSchnellnotizUnsichtbareBaustelleIst403`; `::testM10SaveSchnellnotizSichtbareBaustelleKommtDurch`; `::testM10LoadSchnellnotizenUnsichtbareBaustelleIst403`; `::testM10LoadSchnellnotizenSichtbareBaustelleKommtDurch`; `::testM10SaveTagebuchExportUnsichtbareBaustelleIst403`; `::testM10SaveTagebuchExportSichtbareBaustelleKommtDurch`; `::testM10SaveTerminUnsichtbareBaustelleIst403`; `::testM10SaveTerminSichtbareBaustelleKommtDurch`; `::testM10MarkOrderedUnsichtbareBaustelleIst403`; `::testM10MarkOrderedSichtbareBaustelleKommtDurch`; `::testM10ToggleAuswertungFlagUnsichtbareBaustelleIst403`; `::testM10ToggleAuswertungFlagSichtbareBaustelleKommtDurch` | **GRÜN** | **GRÜN** | n/a |
| M11 (`delete`/`rename`/`move` Quelle+Ziel/`save_generated_file`) | `RechteHaertungTest::testM11DeleteFileOhneKategorieVerlangtCanWriteDateien`; `::testM11DeleteFileOhneKategorieMitRechtLoescht`; `::testM11RenameFileOhneSchreibrechtIst403`; `::testM11RenameFileMitSchreibrechtKommtDurch`; `::testM11MoveFileOhneQuellrechtIst403`; `::testM11MoveFileOhneZielrechtIst403`; `::testM11MoveFileMitBeidenRechtenKommtDurch`; `::testM11SaveGeneratedFileOhneOrdnerrechtIst403`; `::testM11SaveGeneratedFileBelegrechtErsatzFuerAusgangsbelege`; `::testM11SaveGeneratedFileMitOrdnerrechtKommtDurch` | **GRÜN** | **GRÜN** | n/a |
| M13 (`get_permissions` nur Admin) | `RechteHaertungTest::testM13GetPermissionsNormalIst403`; `::testM13GetPermissionsMasterIst403`; `::testM13GetPermissionsAdminKommtDurch` | **GRÜN** | **GRÜN** | n/a |
| M14 (Admin-Rechteliste vollständig) | `RechteHaertungTest::testM14AdminPermsEnthaeltDokumentenordnerUndDin1090` | **GRÜN** | **GRÜN** | n/a |
| E-088 Kompatibilität (Standardrechte `normal`) | `RechteHaertungTest::testM2SendVdeMitLeserechtKommtDurch` (Default `canReadVde0100=true`) | **GRÜN** | **GRÜN** | n/a |

Befunde:
- Kein neuer Produktivfehler im B2a-Scope gefunden.
- Bekannter, vorbestehender Windows-`realpath`-Bug (`moveFile()`/`downloadFile()`) bleibt dokumentiert; deshalb bei `testM11MoveFileMitBeidenRechtenKommtDurch` bewusst „nicht 403“ statt hartem 200-Assert.

## 5. Review

<!-- Reviewer: Urteil, Befundtabelle, Lernpunkt-Kandidaten (je Runde) -->

**B1** Runde 1 (inkl. Korrekturrunde 1) – **APPROVE**

Geprüft: Diff aller B1-Dateien, `bkDateiAnzeigeName` (Alias-Trim, Null-Fälle), Aufrufstellen `script.js:14778` + `mobile.html:6203` (per grep: keine weiteren `substring(20)`-Vorkommen, auch nicht in `mobile_light.html`/`script.min.js`), XSS (`esc()` escapt `"` in `script.js:7924`/`mobile.html:2752`; `title`/Name überall `esc(...)` – OK), Einklapp-Logik `toggleSidebarSection`/`restoreSidebarSections` (`script.js:1751–1770`, unverändert kompatibel mit neuem CSS), Cache-Busting (`check-versions --strict` OK: v148/v250/v2/bk-es-v254 konsistent, `datei-anzeige.js?v=2` in beiden HTMLs + Precache; alter SW v252 bleibt in sich konsistent), `npm run test:js` selbst ausgeführt → 43/43 GRÜN. Kein PHP/SQL/Schema berührt (nur `public/` + `tests/js/`) – SQLite/PostgreSQL n/a bestätigt. Abnahmekriterien a–c plausibel erfüllt (a per JS-Test + bestehender CSS-Ellipsis/`title`; b/c per CSS-Konstruktion + Statiktests; Klicktest separates AP, im AP dokumentiert).

| Schwere | Datei:Zeile | Problem | Vorschlag |
|---|---|---|---|
| Minor | public/style.css:1246–1250 | Bei eingeklapptem Projektbereich wirken `flex:1` + `min-height:160px` weiter auf die Section → ~120px Leerraum unter dem Toggle; zudem bleibt das Menü auch dann auf 45 % begrenzt | Beim nächsten Sidebar-AP: `min-height`/`flex` nur bei nicht eingeklapptem Body anwenden (z. B. Zustandsklasse an der Section) |
| Hinweis | public/style.css:307–313, 1240–1250 | Bei sehr niedriger Fensterhöhe (< ~300px, nur Desktop) clippt `overflow:hidden` am Wrapper den Projektbereich (160px Mindesthöhe + 45 % Menü + Header); vorher scrollte der Wrapper als Ganzes. Liste bleibt intern scrollbar – akzeptabel | Beobachten; ggf. Media-Query für kleine Höhen |
| Hinweis | tests/js/sidebar-scrollstruktur.test.mjs:33–66 | Statiktests koppeln an exakte Werte (45 %, 160px) und an die Deklarations-Reihenfolge (`overflow-y` vor `flex` in `.baustellen-list`) – brüchig bei kosmetischem CSS-Tuning | Bei Fehlalarm Regex auf Struktur statt Werte lockern (Teständerung nur mit Begründung, E-090) |
| Hinweis | public/datei-anzeige.js:17 | Alias wird jetzt getrimmt angezeigt (vorher mit umgebendem Whitespace) – gewollte Verbesserung, aber als Verhaltensänderung in §3 nicht explizit benannt | Keine Aktion nötig; künftig kleine Verhaltensänderungen in „Abweichungen“ nennen (E-084) |
| Hinweis | public/bedienungsanleitung.html:113 | Typografisch gemischte Anführungszeichen („Menü" – gerades Schlusszeichen statt “) | Kosmetik, bei nächster Anleitungsänderung glätten |
| Hinweis | AP §3/§4 (B1) | Gelaufenes Modell je Subagenten-Aufruf nicht vermerkt (E-097/E-089) | Leitstand trägt nach |
| Hinweis | Arbeitsbaum | Arbeitsbaum enthält Fremdänderungen anderer APs (Makefile, ci.yml, composer.*, phpunit.xml.dist, tests/Support/ApiClient.php, .dockerignore) | Commit strikt mit expliziten B1-Pfaden (E-076) |

Lernpunkt-Kandidaten:
- (Tests) Statische CSS-/HTML-Strukturtests sollten Strukturaussagen statt exakter Pixel-/Prozentwerte prüfen, sonst blockieren sie kosmetisches Tuning – keine bestehende E-Nr; Kandidat für neue Regel, falls solche Tests erneut brechen.
- (Frontend) Flex-/min-height-Regeln an einklappbaren Containern immer auch im eingeklappten Zustand abnehmen (Leerraum-Falle) – keine bestehende E-Nr; vorerst nur Beobachtung (1. Auftreten).

**Entscheidung Leitstand zu den Befunden (2026-11-19):** Minor (Leerraum eingeklappter Projektbereich) → behoben in **B1 Korrekturrunde 2** (siehe §3, `:has()`-Lösung akzeptiert, 43/43 grün, check-versions OK). Hinweise: Statiktest-Brüchigkeit + Collapse-Falle als Lernpunkt-Kandidaten erfasst (§6); kleine Fensterhöhe beobachten; Anführungszeichen-Kosmetik bei nächster Anleitungsänderung; Modellvermerk nachgetragen (§3); Commit erfolgt strikt mit expliziten B1-Pfaden (E-076).

**B2a** Runde 1 – **CHANGES_REQUESTED**

Geprüft: Diff aller B2a-Dateien (`git diff`), angrenzender Code gezielt (`Auth::getEffectiveVisibility/expandVisibleBaustellenIds/canSeeBaustelle`, `ExportActions::renderBelegPdf/generateZugferd`, `CrudActions::saveRechnung/deleteRechnung`, `BelegPdfService::belegDaten`, `FileActions` move/rename/delete/saveGenerated, `PlanActions` Schnellnotizen, `lager.js` foto_ai-Aufrufer, `script.js` `canDo`/Dateikarten/`_reFormularBeleg`). Selbst ausgeführt: `phpunit --filter "RechteHaertungTest|RechnungenTest|BelegPdfTest"` (SQLite) → GRÜN 64/941; `phpstan analyse` → OK; `node scripts/check-versions.mjs --strict` → OK (v251/bk-es-v256), `script.min.js` enthält das neue Gating. PostgreSQL nicht selbst gelaufen (Tester: GRÜN). Bestätigt OK: M13; M14 (Skript-Abgleich aller `canDo`/`requirePerm`-Keys in `src/`, `modules/`, `public/` → außer `canSeeWochenpruefung` und dynamischem `canWrite`+Kategorie keine Keys außerhalb `master`, alte Handliste war Teilmenge → rein additiv, `normal` ⊂ `master`); M3 (401→403→405, feste Meldung); M4 (einziger Aufrufer im Artikeldialog, `lager.js:330/884/988`, nur bei `canWriteLager`); M10 (8 Aktionen, Unterprojekte über Cascade des Oberprojekts); M11 Quell- **und** Zielordner; keine Schema-/SQL-Änderung (kein PostgreSQL-/Backup-Risiko); kein `@` neu, keine Secrets; Tests nutzen nur committete Helfer (`get/post/skript`, kein `module()`/`din1090()`), keine Abhängigkeit von Fremddateien (`phpunit.xml.dist`-Diff betrifft nur Coverage-Quelle).

| Schwere | Datei:Zeile | Problem | Vorschlag |
|---|---|---|---|
| Major | src/Handlers/ExportActions.php:282–286, 302–307 | IDOR (A01) in `renderBelegPdf`: Im Formularmodus mit `beleg.id` überschreibt `beleg.baustelleId` den **gespeicherten** Wert, bevor F-B2-3a geprüft wird. Nutzer mit `canManageRechnungen`+`canSeePrices` und eingeschränkter Sichtbarkeit sendet `{beleg:{id:<fremd>, typ:'rechnung', baustelleId:<eigene>}}` (oder `null` → Manage-Ersatz greift) → Prüfung besteht; das PDF enthält Kunde (gespeicherte `kundeId`, wenn Feld weggelassen), Nummer, Datum, Fälligkeit, Beschreibung, Notizen und Absender des fremden Belegs (`BelegPdfService.php:47–70`). Genau der Fall „Baustelle aus Request statt aus DB“. | Direkt nach dem 404-Fetch (vor dem Overlay) `canSeeBelegBaustelle()` mit dem gespeicherten `baustelleId` prüfen (404 → 403); nach dem Overlay zusätzlich das Formular-`baustelleId` prüfen (neue Belege/Baustellenwechsel). Test: Formularpfad mit fremder ID + eigener bzw. leerer `baustelleId` → 403. |
| Major | src/Handlers/ExportActions.php:200–223 | `generate_zugferd` liefert das ZUGFeRD-PDF (Kunde, Positionen, Preise) eines Belegs per `rechnungId` **ohne** F-B2-3a-Prüfung → Umgehung der Nutzerentscheidung „PDF nur für sichtbare Baustellen“; die Anleitung (`bedienungsanleitung.html:166–167`) verspricht die Beschränkung der PDF-Ansicht und nennt ZUGFeRD als PDF-Weg (E-081). | Nach dem 404 `Auth::canSeeBelegBaustelle($db, $rechnung['baustelleId'] ?? null, canDo('canManageRechnungen'))` → 403 (feste Meldung wie in `renderBelegPdf`); Test analog `testFB2_3aPdfAnUnsichtbareBaustelleIst403`. |
| Minor | src/Handlers/CrudActions.php:355–369, 412, 415–426 | `save_rechnung`/`delete_rechnung` ohne Sichtbarkeitsprüfung: mit Manage-Recht und fremder ID liefert die Antwort `rechnung` den vollständigen gemergten fremden Beleg (Lesen über Speichern), außerdem Überschreiben/Löschen bzw. Umhängen auf eine fremde `baustelleId`. Nicht in der Entscheidung F-B2-3a (Liste/PDF/Versand) genannt, untergräbt sie aber. | Leitstand entscheidet: in B2a mitnehmen (404 → 403 auf gespeichertes **und** neues `baustelleId`) oder als dokumentierter Folgepunkt (B2-Rest/B9). |
| Minor | src/Handlers/FileActions.php:29–35, 392–398; public/script.js:14782–14784 | `canWriteFolder()` lockert die bisherige Zielprüfung von `move_file` (vorher nur `canWrite<Ziel>`): mit `canManageRechnungen`/`canManageAngebote` ohne Ordner-Schreibrecht darf man jetzt in Ausgangsbelege/Angebote verschieben und dort umbenennen. Der F-B2-4-Fragetext begrenzte den Ersatz auf „In Dateien speichern“; §3 meldet die Ausweitung, benennt sie aber nicht als Lockerung einer bestehenden Prüfung (E-084). | Nutzer/Leitstand bestätigen lassen; sonst Ersatz nur in `saveGeneratedFile` und Frontend-Gating angleichen. |
| Minor | src/Handlers/PlanActions.php:562–570 | `kannSchnellnotizSehen()` gibt Notizen mit `baustelleId` jedem frei („werden jedem Angemeldeten in der Baustellenansicht gezeigt“) – diese Prämisse gilt nach M10 nicht mehr (`load_schnellnotizen` mit `baustelleId` jetzt sichtbarkeitsgeprüft). `update_schnellnotiz` ändert damit weiter Notizen unsichtbarer Baustellen (IDs fortlaufend). | Für Notizen mit `baustelleId` zusätzlich `canSeeBaustelle()`; Test. Alternativ als Folgepunkt in §2.4 B2 dokumentieren. |
| Minor | src/Handlers/CrudActions.php:327–332; src/Auth.php:475–486 | N+1: je Belegzeile `canSeeBaustelle()` → `getEffectiveVisibility()` → eine `SELECT visibleBaustellen`-Abfrage (auch bei Sichtbarkeit `all`). Bei mehreren tausend Belegen spürbar. | Sichtbarkeit einmal vor dem Filter ermitteln (z. B. `$vis` an den Helfer übergeben oder im Request zwischenspeichern). |
| Minor | tests/Api/RechteHaertungTest.php:184–199 | `testFB2_3aBelegOhneBaustelleNurMitManageRechtSichtbar` prüft nur den Negativfall. Es fehlen: Manage-Recht → sichtbar, Sichtbarkeit `all` → sichtbar, Unterprojekt-Cascade (Beleg an Unterprojekt eines sichtbaren Oberprojekts) sowie Formular- und ZUGFeRD-Pfad – deshalb blieben beide Major-Befunde unentdeckt. | Nach dem Fix ergänzen (Tester, E-094). |
| Hinweis | tests/Api/RechteHaertungTest.php:551–560 | `testM11DeleteFileOhneKategorieMitRechtLoescht` legt die Datei in `Dateien/` an, löscht aber ohne Kategorie in der Wurzel → kein Löschen; nachgewiesen wird nur „kein 403“, der Name verspricht mehr. | Datei in der Altablage anlegen und Löschung prüfen oder Test umbenennen. |
| Hinweis | src/Handlers/EmailActions.php:60–66, 117–128, 191–201, 257–265 | Plan M1/M2 nennen „→ 405“; nicht umgesetzt und in §3 nicht als Verschiebung nach B2c (M7) vermerkt. GET ohne Body ist hier wirkungslos (400), daher kein Risiko. | In §3 als B2c-Teil vermerken. |
| Hinweis | src/Handlers/PlanActions.php:472–487; src/Handlers/TerminActions.php:97–101 | Geprüft wird `(int)$baustelleId`, gespeichert der Rohwert aus dem Body. Keine Umgehung gefunden (jeder Rohwert, der auf eine echte ID passt, castet auf dieselbe ID), aber uneinheitlicher Typ. | Normalisierten int bzw. `null` speichern. |
| Hinweis | src/Handlers/FileActions.php:250–288 | Vorbestehend: `save_generated_file` hat keine Endungs-Sperrliste wie `uploadFile` (`.php`, `.htaccess` nach `basename`+Regex zulässig). Durch B2a nun ordnerrechtsgebunden, bleibt aber eine Defense-in-depth-Lücke. | Folgepunkt B2: dieselben `$blockedExts` + Punktdateien ablehnen. |
| Hinweis | src/Handlers/FileActions.php:503–512 | `delete_file` kennt keinen Belegrecht-Ersatz, das Löschen-Icon ist ungated: Nutzer nur mit Belegrecht sehen Umbenennen/Verschieben, Löschen liefert 403. Von F-B2-4 gedeckt, aber inkonsistent. | Bei B7/B9 vereinheitlichen. |
| Hinweis | src/Auth.php:427–441 | Cascade berücksichtigt nur nicht archivierte Baustellen → Beleg an einem archivierten Unterprojekt eines sichtbaren Oberprojekts ist für eingeschränkte Nutzer unsichtbar (bestehende `canSeeBaustelle`-Semantik). | Zur Kenntnis. |

Lernpunkt-Kandidaten:
- (Sicherheit) Objektbezogene Rechte immer am **gespeicherten** DB-Datensatz prüfen, bevor Request-Daten darübergelegt werden (Overlay/Merge) – keine bestehende E-Nr (E-013/E-015 nur verwandt); 1. Auftreten.
- (Planung) Neue Objektregeln (hier „Beleg nur bei sichtbarer Baustelle“) über **alle Zugriffswege des Objekts** inventarisieren (grep auf Tabelle `rechnungen` / `BelegPdfService::belegDaten`), nicht nur über die in der Nutzerfrage genannten Aktionen – `generate_zugferd` und `save_rechnung` fehlten im Inventar. Keine bestehende E-Nr; 1. Auftreten.
- (Tests) Je Sichtbarkeitsregel Positiv- und Negativfall pro Zugriffsweg einschließlich Override-/Formularpfad – keine eigene E-Nr (E-090 allgemein).

**B2a** Runde 2 (nur Korrekturrunde 1, E-094) – **APPROVE**

Geprüft: `git diff` der 8 Dateien, `Auth::canSeeBelegBaustelle/getEffectiveVisibility/expandVisibleBaustellenIds`, `PlanActions::loadSchnellnotizen/updateSchnellnotiz`, `FileActions::listFiles` (Kategorie immer aus `DOC_CATEGORIES`), Frontend-`canDo` (Admin → true), `script.min.js` (neues Gating enthalten). Selbst ausgeführt: `phpunit --filter "RechteHaertungTest|RechnungenTest|BelegPdfTest|SchnellnotizUpdateTest"` (SQLite) → GRÜN 90/1539; `phpstan` → OK; `check-versions --strict` → OK (`bk-es-v257`, `script.min.js?v=252`). Behoben bestätigt: Major 1 (gespeicherte Baustelle vor dem Overlay, zweite Prüfung danach), Major 2 (`generate_zugferd` gespeicherte Baustelle + `canManageRechnungen`), `save_rechnung` alt + neu, `delete_rechnung`, `move_file`/`rename_file` ohne Belegrecht-Ersatz (Backend und Icon konsistent, `canWriteFolder` nur noch in `saveGeneratedFile`), N+1 (Ergebnis identisch zu `canSeeBelegBaustelle`, `(int)`-Set, Cascade über `getEffectiveVisibility`, `all`/Admin → `null` → unverändert). Feste Meldungen. Neue Tests ohne Fix rot (Formular-IDOR, ZUGFeRD, save/delete, `move_file` mit Belegrecht, `update_schnellnotiz`); Lösch-Test weist den Erhalt per Admin-`list_rechnungen` nach.

| Schwere | Datei:Zeile | Problem | Vorschlag |
|---|---|---|---|
| Minor | src/Handlers/PlanActions.php:569–571 | Regression durch die Korrektur: Notiz mit unsichtbarer `baustelleId` liefert sofort `false`, die Prüfungen auf Ersteller/`zugewiesen_an`/Gruppe laufen nicht mehr. Eigene oder zugewiesene (auch „alle“) Notizen zu einer unsichtbaren Baustelle erscheinen in `load_schnellnotizen` ohne `baustelleId` (Z. 442–448, kein Baustellenfilter), `update_schnellnotiz` liefert aber 403 (vorher 200). Der Docblock („wie loadSchnellnotizen“) stimmt nicht mehr. | `if ($note['baustelleId'] !== null && Auth::canSeeBaustelle(...)) return true;` und danach in die Ersteller-/Zuweisungsprüfung weiterlaufen; Test „zugewiesene Notiz an unsichtbarer Baustelle bleibt bearbeitbar“. |
| Minor | src/Handlers/CrudActions.php:361–363, 369–385 | Vorbestehend, im selben Overlay-Muster: Bei einem Update wird der Typ des **gespeicherten** Belegs nicht geprüft. `$hasManageSave` und die Typprüfung nutzen den Request-`typ`, `UPDATE … typ=?` übernimmt ihn. Wer nur `canManageRechnungen` hat, kann mit `{id:<Angebot>, typ:'rechnung'}` ein Angebot lesen (Antwort `rechnung`), überschreiben und in eine Rechnung umwandeln (umgekehrt genauso). | Folgepunkt (B2-Rest/B9, hoch priorisiert): nach dem 404 `typ` von `$existing` gegen das Typrecht prüfen und Typwechsel ablehnen (400) oder `typ` nicht aus dem Request übernehmen. |
| Hinweis | tests/Api/RechteHaertungTest.php:314–329 | `testSaveRechnungAenderungAnFremderGespeicherterBaustelleIst403` sendet keine `baustelleId`. Die zweite Prüfung (gemergter Stand) fängt den Fall allein ab, die neue Prüfung „gespeichert vor dem Merge“ ist nicht eigenständig abgesichert. Ebenso fehlt ein Test „`rename_file` nur mit Belegrecht → 403“. | Variante mit `baustelleId: <eigene>` bzw. `rename_file` in Ausgangsbelege nur mit `canManageRechnungen` ergänzen (Tester nach APPROVE, E-094). |
| Hinweis | src/Handlers/ExportActions.php:279–289 vs. 305–316 | In `renderBelegPdf` kommt die Objektprüfung (gespeicherte Baustelle) vor der Typrechtprüfung, also 404 → 403 (Objekt) → 403 (Recht) statt 403 (Recht) → 403 (Objekt). Es bleibt bei 403 mit fester Meldung, ein Leck entsteht nicht. | Bei Gelegenheit die Typrechtprüfung für den gespeicherten Typ direkt nach dem 404 vorziehen. |
| Hinweis | src/Handlers/CrudActions.php:146–148 | Vorbestehend, weiterer Zugriffsweg auf `rechnungen`: `export_kunde` gibt Belege mit `positionen` nur mit `canWriteKunden` aus. Es fehlen Belegrecht, `canSeePrices` und F-B2-3a. | Folgepunkt B2-Rest/B9 (Inventar „alle Zugriffswege“, siehe Lernpunkt-Kandidat aus Runde 1). |

Lernpunkt-Kandidaten:
- (Sicherheit) Beim Ergänzen einer Sichtbarkeitsprüfung in eine bestehende Freigabekette („A **oder** B **oder** C“) als zusätzliche Bedingung einfügen, nicht mit `return` an die Stelle eines Zweigs setzen – sonst fallen die übrigen Freigaben weg. Keine E-Nr; 1. Auftreten.
- (Sicherheit) Ergänzung zum Runde-1-Kandidaten „gespeicherten Datensatz vor dem Overlay prüfen“: Das gilt für **alle** rechterelevanten Felder (hier `typ`), nicht nur für das gemeldete (`baustelleId`). Keine E-Nr; 2. Auftreten derselben Ursache.

**Entscheidung Leitstand nach Runde 2 (2026-11-19):** APPROVE angenommen. Vor dem Commit schmal nachgebessert (**B2a Korrekturrunde 2**, §3): Schnellnotiz-Regression behoben (Sichtbarkeit wieder additiv) und Typwechsel-IDOR geschlossen (gespeicherter Typ gegen Typrecht, Typwechsel → 409) – beide im B2a-Thema und klein; dazu die zwei fehlenden Tests aus den Hinweisen. Leitstand-Gegenprüfung: Diff `kannSchnellnotizSehen` gesichtet, `phpunit --filter "RechteHaertungTest|RechnungenTest|BelegPdfTest|SchnellnotizUpdateTest"` SQLite selbst → 96/96 GRÜN (PostgreSQL laut Entwickler 96/96). Kein drittes Review, da beide Änderungen exakt den Reviewer-Vorschlägen folgen und testgesichert sind.

**Folgepunkte aus B2a** (offen, nicht Teil von B2a):
- 405 für `send_rechnung`/`send_angebot`/`send_vde`/`send_din1090` → **B2c** (POST-Pflicht).
- `export_kunde` gibt Belege mit Positionen nur mit `canWriteKunden` aus (ohne Belegrecht, `canSeePrices`, F-B2-3a) → B2-Rest bzw. B9 (E-099 „alle Zugriffswege“).
- Reihenfolge in `renderBelegPdf`: Typrecht vor Objektprüfung ziehen (kosmetisch, kein Leck).
- Endungs-Sperrliste für `save_generated_file` (vorbestehend).
- Belege an archivierten Unterprojekten für eingeschränkte Nutzer unsichtbar – fachlich klären.
- `baustelleId` wird als Rohwert gespeichert, aber als `int` geprüft – beim Speichern normalisieren.
- Windows-`realpath`-Vergleich in `moveFile()`/`downloadFile()` (Backslash vs. „/“) lässt die Aktionen unter Windows scheitern (vorbestehend, Linux/Docker nicht betroffen).
- `tests/Unit/EndpunktInventarTest.php` (Klicktest-AP, unkommittiert) wird rot, bis dessen `endpunkt-luecken.json` die in B2a neu getesteten Aktionen austrägt – beim Zusammenführen des Klicktest-APs anpassen.

## 6. Lernpunkte

<!-- Leitstand: neue Einträge in erkenntnisse.md (E-Nr) und ggf. übernommene Regeln -->

**B1 (2026-11-19):** Zwei Lernpunkt-Kandidaten (1. Auftreten, noch keine E-Nr) ins Lernprotokoll eingetragen:
(1) Statische Strukturtests nicht an exakte CSS-Werte/Reihenfolge koppeln (Tests);
(2) Collapse-Zustand bei Flex-/min-height-Konstruktionen mit abnehmen (Frontend).
Hinweis: `lernprotokoll.md` trägt zum Zeitpunkt des B1-Commits unkommittierte Fremdänderungen des Klicktest-APs – Einträge werden mit dem nächsten `docs(ki)`-Commit dieser Datei versioniert.

**B2a (2026-11-19):** Neu **E-099** (Sicherheit): Objektrechte am gespeicherten Datensatz, für alle rechterelevanten Felder und alle Zugriffswege prüfen (2. Auftreten → E-Nr). Lernprotokoll: Kandidat „Zusatzbedingung nicht als Ersatz in Freigabeketten“ (1. Auftreten); E-087 erneut aufgetreten (Pipe an `node -` kodiert ASCII, Commit-Nachricht vor Push per `--amend` korrigiert). `erkenntnisse.md`/`lernprotokoll.md` enthalten weiterhin Fremdänderungen des Klicktest-APs und bleiben deshalb vorerst unkommittiert.
Modell laut Aufruf (E-097): Architekt, Entwickler, Tester, Reviewer jeweils mit dem Standardmodell ihrer `.agent.md`, ohne Override durch den Leitstand.

