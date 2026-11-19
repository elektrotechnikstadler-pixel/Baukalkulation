# AP-20261119-sammel-aenderungen: Sammelplan Änderungen vom 2026-11-19

| Feld | Wert |
|---|---|
| Status | in Umsetzung (B1 abgeschlossen, B2–B9 offen) |
| Typ | Feature · Bugfix · Sicherheit (Sammelplan) |
| Testläufe rot | 1 |
| Review-Runden | 1 |
| Commit | 4e8997c (B1) |

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

## 6. Lernpunkte

<!-- Leitstand: neue Einträge in erkenntnisse.md (E-Nr) und ggf. übernommene Regeln -->

**B1 (2026-11-19):** Zwei Lernpunkt-Kandidaten (1. Auftreten, noch keine E-Nr) ins Lernprotokoll eingetragen:
(1) Statische Strukturtests nicht an exakte CSS-Werte/Reihenfolge koppeln (Tests);
(2) Collapse-Zustand bei Flex-/min-height-Konstruktionen mit abnehmen (Frontend).
Hinweis: `lernprotokoll.md` trägt zum Zeitpunkt des B1-Commits unkommittierte Fremdänderungen des Klicktest-APs – Einträge werden mit dem nächsten `docs(ki)`-Commit dieser Datei versioniert.
