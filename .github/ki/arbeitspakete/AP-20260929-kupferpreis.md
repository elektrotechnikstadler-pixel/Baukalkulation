# AP-20260929-kupferpreis: Abruf der Kupferpreise prüfen

| Feld | Wert |
|---|---|
| Status | abgeschlossen (PostgreSQL-Lauf ausstehend) |
| Typ | Bugfix |
| Testläufe rot | 0 |
| Review-Runden | 2 |
| Commit | – |

## 1. Auftrag

„Abruf der Kupferpreise prüfen.“

Funktion, Quelle, Fehlerbehandlung und Aktualität des Kupferpreis-Abrufs prüfen; gefundene Fehler beheben.

## 2. Plan

### 2.1 Ist-Zustand (Analyse 2026-09-29)

| Aspekt | Befund |
|---|---|
| Code | `CatalogActions::metallzuschlagGet/Set/AutoFetch` ([src/Handlers/CatalogActions.php](../../../src/Handlers/CatalogActions.php) ab Z. 286), Routen `metallzuschlag_get/_set/_auto_fetch` in `public/api.php`; Speicherung als JSON in Tabelle `metallzuschlag` (eine Zeile, `id = 1`, `LegacySchema.php`) |
| Quellen (Reihenfolge) | 1. Westmetall „obere Kupfer WM-Notiz“ `markdaten.php?action=table&field=WM_Cu_high` (€/100 kg) · 2. Westmetall `LME_Cu_cash` · 3. `finanzen.net/rohstoffe/kupferpreis` – alle URLs fest im Code |
| Abrufweg | Serverseitig über `fetchUrl()` (`src/Helpers.php`): cURL, Timeout 10 s, Connect 5 s, max. 3 Redirects, TLS-Prüfung an, Browser-User-Agent; Fallback `@file_get_contents`. Worst Case 3 Quellen × 10 s ≈ 30 s pro Aufruf |
| Parsing | Regex auf HTML. Quelle 1: erste `<td … class="last">` → Wert, Plausibilität 100–5000. Quelle 2: erste Zahl `d.ddd,dd` → **÷ 10 als €/100 kg**. Quelle 3: erstes `"price":` im Seitenquelltext oder Text nach „Kupferpreis“ → ÷ 10 |
| Caching | 6 h ab `updated`; nur bei Erfolg. Fehlschläge werden nicht gemerkt → jeder Picker-Aufruf aller Nutzer löst erneut bis zu 3 externe Abrufe aus |
| Fehler | Fehlertexte per `@file_put_contents(DATA_DIR.'error.log')`; bei Fehlschlag alter Wert + `fetchError` (Frontend wertet `fetchError` nicht aus), sonst `{error, delNotierung: 0}` mit HTTP 200 |
| Anzeige | Admin-Modal `#metallzuschlagModal` (`index.html`), Info-Leiste im Katalog-Picker (`renderMzInfoBar`), Mobile-Suche (`mobile.html`). „Stand“ = Abrufdatum, nicht Datum der Notiz |
| Verwendung | Nur Frontend: `calcMetallzuschlag()` (script.js, mobile.html) schlägt beim Einfügen von Datanorm-Kabelartikeln `Adern × mm² × 0,00893 kg/m × DEL / 100` auf den EK auf und hängt `[MZ+…]` an die Bezeichnung → landet in Kalkulation und Belegen. `basisNotierung` wird gespeichert, aber nicht verrechnet |
| Einheit | €/100 kg Cu (Anzeige und Eingabe) |
| Rechte | `get`/`auto_fetch`: jeder angemeldete Nutzer (auto_fetch schreibt in die DB); `set`: admin/master. Einstellung `modul_kupfer_del` blendet nur den Kupfer-Button aus, Server und Aufschlag ignorieren sie |
| Tests | Keine |

**Live-Prüfung der Quellen (nur lesend, 2026-09-29):**
- Westmetall WM_Cu_high: erreichbar, Tabelle „Datum | obere Kupfer WM-Notiz“, neuester Wert oben (28.09.2026: 1.311,22 €/100 kg), Zwischenüberschriften je Monat. Ob `class="last"` im Roh-HTML noch vorhanden ist, konnte das Lesewerkzeug nicht zeigen (liefert nur aufbereiteten Text) → in Schritt 1 mit Roh-HTML prüfen.
- Westmetall LME_Cu_cash: erreichbar, 4 Spalten, Werte in **USD/t** (14.544,50). Code macht daraus 1.454,45 „€/100 kg“ – **Währungsfehler** (≈ +13 % gegenüber EUR).
- finanzen.net: Werte in **USD/t**, Consent-/Werbewand, viele Zahlen auf der Seite (ETC-Kurse wie 139,93). Das erste `"price"` kann ein beliebiger Kurs sein → z. B. 13,99 „€/100 kg“ würde gespeichert (Plausibilitätsprüfung greift erst vor ÷ 10). Zusätzlich Nutzungsbedingungen fraglich.

### 2.2 Gefundene Fehler und Risiken

1. **Falsche Einheit/Währung** in Fallback 2 und 3 (USD/t ÷ 10 ≠ €/100 kg) → falscher Metallzuschlag in Belegen.
2. **Fragiles Parsing** Fallback 3 (erste beliebige Zahl); Quelle 1 hängt an einem CSS-Klassennamen.
3. **Manueller Wert wird überschrieben:** `set` schreibt `updated = jetzt`; 6 h später überschreibt `auto_fetch` den manuellen Wert ohne Hinweis.
4. **Keine Drosselung bei Fehlschlag:** jeder Picker-Aufruf → bis zu 30 s blockierter PHP-Worker und 3 externe Anfragen.
5. **Feature-Flag wirkungslos:** bei `modul_kupfer_del = false` wird weiter abgerufen und aufgeschlagen.
6. **Keine Kennzeichnung veralteter Werte**; „Stand“ zeigt Abruftag statt Notiz-Datum; `fetchError` wird nicht angezeigt.
7. **Keine Eingabeprüfung** in `set` (0, negativ, 1e9 möglich).
8. **Zeichensalat in script.js:** Bezeichnungs-Suffix `' [MZ+…—]'` statt `€` (mobile.html korrekt), Spezifikation `'—'` statt `×`; `CABLE_SPEC_RE` erkennt `×` nur mobil.
9. **Escaping:** `mz.datum`/`mz.quelle` ungeescaped per `innerHTML` (script.js `renderMzInfoBar`, `loadMetallzuschlagSettings`; mobile.html Info-Leiste). Werte stammen aus der DB, können aber über Backup-Import beliebig sein.
10. **`@`-Operator** beim Fehler-Log (verstößt gegen E-063).
11. SSRF: **kein Risiko**, URLs fest im Code, nicht konfigurierbar. So belassen.

### 2.3 Ziel & Abgrenzung

**Ziel:** Kupferpreis wird aus genau einer verlässlichen Quelle (Westmetall obere WM-Notiz, €/100 kg) robust gelesen, mit Notiz-Datum und Abrufzeit gespeichert, bei Fehlschlag gedrosselt und der letzte Wert als „veraltet“ gekennzeichnet; manuelle Eingabe bleibt bis zur bewussten Aufhebung gültig; Feature-Flag wirkt; Parsing ist per Fixture getestet.

**Nicht Teil:**
- Formel des Metallzuschlags (theoretisches Cu-Gewicht statt Cu-Zahl des Herstellers, Basis-Notierung, 1 % Bezugskosten, Ring/Trommel-Annahmen) – nur als offene Frage.
- Neue Quellen mit Währungsumrechnung (LME + EZB-Kurs).
- Umbau von `fetchUrl()` (`@file_get_contents`) – Nebenbefund, eigenes AP.
- Schemaänderung (JSON-Spalte bleibt, neue Felder nur im JSON).
- Metallprofil-Katalog (`metallprofileAutoUpdate`) und übrige Zeichensalat-Stellen in script.js.

### 2.4 Betroffene Dateien

| Datei | Grund |
|---|---|
| `src/Services/Kupferpreis.php` (neu) | Reine Funktionen: `parseWestmetall(string $html): ?array{preis: float, stand: string}` (Tabelle robust per `DOMDocument`/Zeilenregex, dt. Monatsnamen → `Y-m-d`, Zahl `1.311,22` → 1311.22, Plausibilität 100–5000), `istVeraltet()`, Entscheidungslogik „abrufen ja/nein“ mit injizierbarer Uhrzeit. Abruf-Funktion als `callable` injizierbar (Test ohne Netz) |
| `src/Handlers/CatalogActions.php` | `metallzuschlagAutoFetch` auf Service umstellen; Fallback-Quellen 2+3 entfernen; `set` validieren; Flag prüfen; `error_log()` statt `@file_put_contents` |
| `public/script.js` | Info-Leiste/Modal: Notiz-Datum, Hinweis „veraltet“/„manuell“, `esc()`; `force=1` im Admin-Button; Zeichensalat `€`/`×`, `CABLE_SPEC_RE` um `×` ergänzen |
| `public/mobile.html` | Info-Leiste: `esc()`, Veraltet-Hinweis |
| `public/index.html`, `public/sw.js`, `public/script.min.js` | Cache-Busting (`?v=`, `CACHE_VERSION`), Minify |
| `tests/Unit/KupferpreisTest.php` (neu) | Parsing + Fallback-/Drossel-Logik mit Fixture |
| `tests/fixtures/kupferpreis/westmetall_wm_cu_high.html` (neu) | Gekürzte echte Antwort (öffentliche Marktdaten, keine Kundendaten) |
| `tests/Api/MetallzuschlagTest.php` (neu) | Rechte, Validierung, manueller Wert, Flag – ohne Netzabruf |
| `CHANGELOG.md`, `VERSION` (+ `npm run version:sync`) | Patch-Version, Eintrag `fix:` |

### 2.5 Struktur (Vorlage copier-astral)

Entspricht der Vorlage: Logik als typisierte Klasse unter `src/` (≙ `src/<paket>/`), Tests unter `tests/Unit` bzw. `tests/Api` (≙ `tests/`), Testdaten unter `tests/fixtures/` (bestehende Konvention). Keine neuen Make-Targets, CLI-Befehle, CI-Jobs oder Root-Skripte. Keine Abweichung.

### 2.6 Berücksichtigte Erkenntnisse

- **E-063** kein `@` (Fehler-Log, keine neuen `@` im Service).
- **E-010** nicht betroffen (keine Secrets).
- **E-020/Migration**: bewusst keine Schemaänderung → keine Migration, keine neue Backup-Fixture nötig.
- **E-060/E-061** `php` per WinGet-Pfad, `npm.cmd` für Minify/Version-Sync.
- **E-050** Struktur nach Vorlage (s. 2.5).

### 2.7 Schritte (Commit-Reihenfolge)

1. **Fixture anlegen** – Roh-HTML von `WM_Cu_high` einmalig per curl holen, auf Kopf + erste 2 Monatsblöcke kürzen, unter `tests/fixtures/kupferpreis/` ablegen. Dabei prüfen, ob `class="last"` noch vorkommt (Ergebnis in Abschnitt 3 notieren). Zusätzlich Fixture „Seite ohne Tabelle“ (leeres/umgebautes HTML).
   *Prüfbar:* Datei vorhanden, enthält „obere Kupfer WM-Notiz“ und mind. ein Datum/Wert-Paar.
2. **Unit-Test rot** (`test:`) – `KupferpreisTest`: Fixture → Preis 1311.22 + Stand `2026-09-28` (Werte aus Fixture); kaputtes HTML → `null`; Zahl außerhalb 100–5000 → `null`; Zwischenüberschrift „Datum“ wird übersprungen.
3. **Service `App\Services\Kupferpreis`** (`fix:`) – Parser über Tabellenzeilen (erste Zeile mit gültigem Datum + Zahl), unabhängig von CSS-Klassen; Entscheidung „abrufen?“: nein, wenn Flag aus, wenn `quelle = manual` (außer `force`), wenn letzter Erfolg < 6 h oder letzter Fehlversuch (`letzterVersuch`) < 30 min. Unit-Tests für diese Regeln mit fester Uhrzeit. *Prüfbar:* Unit-Suite grün, PHPStan ohne neue Baseline.
4. **Handler umstellen** (`fix:`) – `metallzuschlagAutoFetch`: nur noch Westmetall, Timeout 5 s; `force=1` nur für admin/master (sonst 403); JSON-Felder `delNotierung, basisNotierung, stand, datum (=stand, Rückwärtskompatibilität), quelle, updated, letzterVersuch, veraltet, fetchError`; bei Fehlschlag letzter Wert + `veraltet: true` + `fetchError`, `letzterVersuch` speichern; ohne Wert HTTP 200 `{delNotierung: 0, fetchError}`. `metallzuschlagGet`: bei `modul_kupfer_del = false` → `{delNotierung: 0, aktiv: false}`; `veraltet` berechnen (Stand älter als 5 Kalendertage). `metallzuschlagSet`: `delNotierung` endlich und 100–5000, sonst 400. Log per `error_log()`.
5. **API-Test** (`test:`) – `MetallzuschlagTest` (siehe Abnahmekriterien 4–8), ohne externen Abruf (manueller Wert bzw. Flag aus verhindern den Abruf).
6. **Frontend** (`fix:`) – script.js: `renderMzInfoBar`/`loadMetallzuschlagSettings` zeigen „Stand der Notiz: TT.MM.JJJJ“, „manuell“/„automatisch“, gelben Hinweis bei `veraltet`/`fetchError`; alle Server-Strings über `esc()`; `autoFetchDel()` ruft `…&force=1`; `€`/`×` korrigieren, `CABLE_SPEC_RE` `[x×]`. mobile.html: `esc()` + Veraltet-Hinweis. Modaltext in index.html: „wird automatisch abgerufen, manueller Wert bleibt bis zum nächsten manuellen Abruf aktiv“. Minify, `?v=`/`CACHE_VERSION`, `make check-versions`.
7. **Version/Changelog** (`chore:`) – Patch-Version in `VERSION`, `npm.cmd run version:sync`, CHANGELOG-Eintrag.
8. Volle Testsuite auf SQLite und PostgreSQL (`make test-pgsql`).

### 2.8 Risiken

- **SQLite/PostgreSQL:** nur `UPDATE … WHERE id = 1` mit Parameter und JSON in PHP – unkritisch; trotzdem beide Treiber testen.
- **Migration/Fixture:** keine Schemaänderung → keine Migration, keine neue Backup-Fixture.
- **Backup-Import alter Versionen:** alte JSON-Zeilen ohne `stand`/`letzterVersuch` müssen funktionieren → Service liest mit Defaults, `datum` bleibt als Feld erhalten. Beliebige Inhalte aus Backups → Frontend escapt.
- **Rechte/Sicherheit:** `auto_fetch` bleibt für alle Angemeldeten lesbar (Picker braucht es), löst aber nur gedrosselt und nie bei manuellem Wert einen Abruf aus; `force` nur admin/master. Kein SSRF (feste URL). Kein Aufruf ohne Login (`requireAuth` bleibt).
- **Quelle ändert Aufbau/sperrt Abruf:** Parser liefert `null` → letzter Wert + „veraltet“; manuelle Eingabe bleibt möglich. User-Agent bleibt wie bisher (Browser-UA), um keine Sperre auszulösen – siehe offene Frage 3.
- **Wegfall der Fallback-Quellen:** bei Westmetall-Ausfall kein automatischer Ersatz mehr – bewusst, da die bisherigen Fallbacks falsche Werte lieferten.
- **Cache-Busting/Version:** script.js geändert → Minify, `?v=`, `CACHE_VERSION`, `check-versions` in CI.
- **Modul-Lizenz/Feature-Flag:** kein Lizenzmodul betroffen; `modul_kupfer_del` wird jetzt serverseitig beachtet – Installationen mit Flag „aus“ bekommen keinen Aufschlag mehr (gewollt, war laut UI so gemeint).

### 2.9 Abnahmekriterien

1. Parser liefert für die gespeicherte Westmetall-Fixture Preis und Notiz-Datum der obersten Tabellenzeile in €/100 kg (ohne Netzabruf).
2. Parser liefert für HTML ohne passende Tabelle, mit Zahlen außerhalb 100–5000 oder unbekanntem Datumsformat `null` statt eines Werts.
3. Nach fehlgeschlagenem Abruf liefert `metallzuschlag_auto_fetch` den letzten gespeicherten Wert mit `veraltet: true` und `fetchError`; innerhalb von 30 min erfolgt kein erneuter externer Abruf (Unit-Test mit Zähler im Fake-Fetcher).
4. Nach `metallzuschlag_set` (admin) mit 1300 liefert `metallzuschlag_get` und `metallzuschlag_auto_fetch` (auch > 6 h später, simuliert über gespeicherten `updated`) weiterhin 1300 mit `quelle: manual`.
5. `metallzuschlag_set` mit 0, negativ, `"abc"` oder > 5000 → HTTP 400, gespeicherter Wert unverändert.
6. `metallzuschlag_set` und `metallzuschlag_auto_fetch&force=1` als Nicht-Admin/Master → HTTP 403; ohne Login → 401.
7. Bei `modul_kupfer_del = false` liefern `get` und `auto_fetch` `delNotierung: 0` und `aktiv: false`, ohne externen Abruf.
8. Alte gespeicherte Daten ohne neue Felder (`{delNotierung, basisNotierung, datum, quelle, updated}`) werden ohne Fehler gelesen.
9. Im Browser: Info-Leiste und Modal zeigen Notiz-Datum, Herkunft (manuell/automatisch) und bei veraltetem Wert einen sichtbaren Hinweis; ein `datum` mit `<b>` wird als Text angezeigt.
10. Eingefügte Kabelposition aus Datanorm hat die Bezeichnung `… [MZ+x,xx€]` (Desktop wie mobil); `3×1,5` wird wie `3x1,5` erkannt.
11. Kein `@` im geänderten Code; PHPStan ohne neue Baseline-Einträge; volle Suite grün auf SQLite und PostgreSQL; `check-versions` grün.

### 2.10 Offene Fragen an den Nutzer

1. **Referenzwert:** Soll die *obere* WM-Notiz als „DEL“ gelten (heutiges Verhalten) – oder die untere bzw. obere + 1 % Bezugskosten, wie es viele Kabelhersteller für den Metallzuschlag ansetzen?
2. **Fallback-Quellen:** Einverstanden, LME- und finanzen.net-Fallback ersatzlos zu entfernen (liefern USD/t, finanzen.net zudem Consent-Wand/Nutzungsbedingungen)? Alternative wäre LME Cash + EZB-Kurs (eigenes AP).
3. **User-Agent:** Browser-UA beibehalten oder ehrlich `Baukalkulation/<Version>` senden (sauberer, aber Risiko einer Sperre)?
4. **Manueller Wert:** Soll er dauerhaft gelten, bis ein Admin „automatisch abrufen“ klickt (Plan), oder nur bis zum Folgetag?
5. **Formel (außerhalb dieses AP):** Soll `basisNotierung` (heute gespeichert, aber nicht verrechnet; Frontend sendet 0, Server-Standard 150) künftig abgezogen werden? Das hängt davon ab, ob die Datanorm-Preise der Großhändler Kupferbasis 0 oder 150 enthalten.

**Freigabe G1:** ☑ durch Nutzer am 2026-09-29 – obere WM-Notiz bleibt DEL-Preis; LME- und finanzen.net-Ersatzquellen (Dollar) ersatzlos entfernen. Übrige Fragen: Vorschlag des Architekten.

## 3. Umsetzung

**Herkunft:** Backend-Entwurf (`src/Services/Kupferpreis.php`, Änderung `src/Handlers/CatalogActions.php`), Tests und Fixtures stammen aus einem **abgebrochenen Tester-Lauf**, der entgegen seiner Rolle Produktivcode angelegt hatte. Der Entwickler hat den Stand als Entwurf übernommen, gegen 2.7/2.9 geprüft und die Frontend-Schritte ergänzt.

**Prüfung des Entwurfs (Backend):**
- Schritt 1: Fixture `westmetall_wm_cu_high.html` ist Roh-HTML (Kopf + September/August 2026, oberste Zeile 29.09.2026 = 1.307,62). **`class="last"` kommt im Roh-HTML weiterhin vor**; der Parser hängt trotzdem nicht davon ab (Test `testParserHaengtNichtAnCssKlassen`). Nicht erneut live abgerufen.
- Schritt 3: `Kupferpreis` (Parser über Tabellenzeilen, dt. Monatsnamen, Plausibilität 100–5000, Drosselung 6 h/30 min, manuell nur mit `force`, Flag, `istVeraltet` > 5 Kalendertage bzw. bei `fetchError`, Abruf als injizierbare Closure) entspricht dem Plan – unverändert übernommen.
- Schritt 4: Handler – nur Westmetall, Timeout 5 s, `force` nur admin/master (403), Flag → `{delNotierung: 0, aktiv: false}` ohne Abruf, `set` validiert 100–5000 (400), `error_log()` statt `@file_put_contents`, Altdaten mit Defaults – entspricht dem Plan, unverändert übernommen. Kein `@` im geänderten Code.
- Tests: kein Live-Netzabruf (Unit: Fake-Closure; API: manueller/frischer Wert, 403 oder Flag verhindern den Abruf). Lauf ~14 s, kein Hänger.

**Ergänzt (Frontend, Schritt 6):**
- `public/script.js`: neue Hilfen `mzStandText()` („Stand der Notiz: TT.MM.JJJJ (manuell|automatisch)“, escaped) und `mzVeraltetHinweis()` (gelber Hinweis bei `veraltet`/`fetchError`, `fetchError` escaped) in `renderMzInfoBar` und `loadMetallzuschlagSettings`; `autoFetchDel()` ruft `&force=1` und meldet 403/Fehlschlag statt „erfolgreich“; `CABLE_SPEC_RE` `[x×]`; Spezifikation `×` statt `—`; Suffix `[MZ+…]` ohne `—`.
- `public/mobile.html`: Info-Leiste `esc(stand|datum)`, Herkunft „manuell“, Veraltet-Hinweis; Suffix wie Desktop.
- `public/index.html`: Modaltext (Quelle, manueller Wert bleibt bis zum nächsten manuellen Abruf); `script.min.js?v=218`.
- `public/sw.js`: `script.min.js?v=218`, `CACHE_VERSION` `bk-es-v217`; `public/script.min.js` per `npm.cmd run minify`.
- `CHANGELOG.md`: „Unveröffentlicht → Behoben“.

**Abweichungen vom Plan:**
- Suffix lautet `[MZ+0,27 €]` statt `[MZ+0,27€]` (AK 10): `fmt()` hängt bereits „ €“ an – mobil entstand dadurch bisher `€€`. Desktop und mobil jetzt identisch `' [MZ+' + fmt(x) + ']'`.
- Schritt 7 (Patch-Version in `VERSION`, `version:sync`) nicht ausgeführt – laut Auftrag nur CHANGELOG „Unveröffentlicht“; Versionssprung entscheidet der Leitstand.
- Schritt 8 (volle Suite SQLite/PostgreSQL) laut Auftrag beim Leitstand.
- Nebenbefunde (nicht geändert, Abgrenzung 2.3): `fmt(del) + ' €/100kg'` zeigt „€ €/100kg“ (Desktop und mobil); `mzNote` in `insertFromKatalog` enthält weiterhin `—` statt `×`.

**Neue Dateien:** `src/Services/Kupferpreis.php`, `tests/Unit/KupferpreisTest.php`, `tests/Api/MetallzuschlagTest.php`, `tests/fixtures/kupferpreis/westmetall_wm_cu_high.html`, `tests/fixtures/kupferpreis/westmetall_ohne_tabelle.html`, `tests/fixtures/kupferpreis/westmetall_umgebaut.html`.

**Prüfungen:** `phpunit --filter "KupferpreisTest|MetallzuschlagTest"` → OK (69 Tests, 301 Assertions); `node --check public/script.js` → OK; `check-versions --strict` → OK; PHPStan → siehe unten.

### Runde 2 (Review-Befunde Runde 1, Entscheidung Leitstand)

1. **Major – bedingtes Schreiben** (`CatalogActions::metallzuschlagAutoFetch`): Rohtext der Zeile wird vor dem Abruf gelesen (`loadMetallzuschlagRoh()`); `saveMetallzuschlag($data, $erwartet)` schreibt dann per `UPDATE metallzuschlag SET data = ? WHERE id = 1 AND data = ?` (Spalte `TEXT` auf SQLite und PG, neutral). Bei 0 betroffenen Zeilen wird neu geladen und der aktuelle Stand mit `cached: true` ohne Speichern geantwortet. Gilt – wie im Review vorgeschlagen – für Fehlschlag- **und** Erfolgspfad (ein während des Abrufs gespeicherter manueller Wert wird auch von einem erfolgreichen Abruf nicht überschrieben).
2. **`fetchError` ohne Exception-Text** (`Kupferpreis::aktualisieren`): feste Meldung „Westmetall-Abruf fehlgeschlagen.“; Ausnahmetext nur per `error_log('[Kupferpreis] …')`.
3. **`set`-Validierung:** `basisNotierung` wie `delNotierung` typgeprüft, `is_finite`, Bereich 0–5000 (0 sendet das Frontend), sonst 400. `saveMetallzuschlag` mit `JSON_THROW_ON_ERROR`.
4. **`force` nur per POST:** Reihenfolge `requireAuth` → bei `force` `requireRole('admin','master')` (403) → Flag aus → `{delNotierung: 0, aktiv: false}` → `force` ohne POST → 405. Die Flag-Prüfung liegt bewusst vor der 405-Prüfung (bei abgeschaltetem Modul ändert nichts den Zustand); dadurch bleiben die bestehenden Tests (`force` per GET: Nutzer 403, anonym 401, Flag aus 200) unverändert grün – kein Test gebrochen. `autoFetchDel()` sendet jetzt `POST` mit JSON `{force: 1}` (Muster `saveMetallzuschlag()`). mobile.html nutzt `force` nicht.
5. **Client-Zurücksetzen:** `fetchMetallzuschlag()` (script.js und mobile.html) setzt `_currentMetallzuschlag` bei jeder Antwort mit `delNotierung` auf `j` bzw. `null` (bei `aktiv: false`/`delNotierung <= 0`); Fehlerantworten ohne `delNotierung` lassen den Wert unverändert.
6. **Anzeige „€ €/100kg“:** in den von diesem AP geänderten Stellen `fmt(x) + '/100 kg'` – script.js `renderMzInfoBar`, `loadMetallzuschlagSettings`, `autoFetchDel`-Meldung; mobile.html Info-Leiste. Nicht geändert (außerhalb der geänderten Zeilen): script.js `mz-badge`-Titel (Z. ~6008) und Meldung in `saveMetallzuschlag()` (Z. ~20171).
7. **Entscheidung:** Nach fehlgeschlagenem `force` auf einen manuellen Wert bleibt `fetchError` gespeichert; der manuelle Wert wird bis zum nächsten `set` bzw. erfolgreichen Abruf als „veraltet – letzter Abruf fehlgeschlagen“ angezeigt. Bewusst so belassen (Admin hat den Abruf ausdrücklich angestoßen, der Hinweis ist zutreffend).

Cache-Busting: `script.min.js` neu (`npm.cmd run minify`), `?v=219` in index.html/sw.js, `CACHE_VERSION` `bk-es-v218`.

**Prüfungen Runde 2:** `phpunit --filter "KupferpreisTest|MetallzuschlagTest"` → OK (69 Tests, 301 Assertions; stderr zeigt erwartete `error_log`-Zeile aus dem Exception-Test); PHPStan → No errors; `node --check public/script.js` → OK; `check-versions --strict` → OK.

**Abweichung:** Punkt 1 zusätzlich auf den Erfolgspfad angewendet (siehe oben). Keine Tests geändert.

## 4. Tests

### Runde 0 (nachgetragen – abgebrochener Tester-Lauf)

Aus dem abgebrochenen Lauf stammen (Grenzverletzung: dort auch `src/Services/Kupferpreis.php` und Änderung `src/Handlers/CatalogActions.php` – vom Entwickler als Entwurf übernommen, siehe Abschnitt 3):
- `tests/Unit/KupferpreisTest.php` – Parser (Fixture, CSS-unabhängig, Zwischenüberschrift, Zeile ohne Zahl, Monatsnamen, Seiten ohne Tabelle, Plausibilität, Datumsformate), Abruf-Entscheidung (6 h, manuell, Flag), Aktualisieren mit Fake-Abruf (Erfolg, Fehlschlag, Ausnahme, 30-min-Drossel, `force`), Altdaten, `istVeraltet`.
- `tests/Api/MetallzuschlagTest.php` – AK 4–8 (manueller Wert > 6 h, Validierung `delNotierung`, 403/401, Flag aus, Altdaten, Veraltet, Sonderzeichen).
- `tests/fixtures/kupferpreis/westmetall_wm_cu_high.html`, `westmetall_ohne_tabelle.html`, `westmetall_umgebaut.html`.
- Stand damals: 69 Tests, 301 Assertions, grün (SQLite).

### Runde 2 (2026-09-29, Review-Befunde Runde 1)

**Ergebnis: GRÜN** – `phpunit --filter "KupferpreisTest|MetallzuschlagTest"`: OK (94 Tests, 493 Assertions, ~26 s, SQLite). stderr zeigt die erwartete `error_log`-Zeile aus `testAusnahmeImAbrufGiltAlsFehlschlag`. `php-cs-fixer check --diff tests/`: 0 von 28 Dateien. PostgreSQL und Gesamtsuite laut Auftrag nicht gelaufen (Leitstand).

Neue Tests (+25 Fälle), bestehende unverändert:

| Befund | Test | Prüft |
|---|---|---|
| a | `KupferpreisTest::testUnplausibleObersteZeileLiefertNullStattAelteremWert` (5 Fälle) | Nur oberste Zeile mit 14.544,50 / 99,99 / 5.000,01 / 0,00 / −1.307,62, darunter gültige Werte → `null`, kein Ausweichen |
| a | `KupferpreisTest::testUngueltigesDatumNurInObersterZeileLiefertPreisUndStandDerselbenZeile` (4 Fälle) | Nur oberstes Datum ungültig (KW, 31. September, Tippfehler, leer) → laut Plan 2.7 Schritt 3 („erste Zeile mit gültigem Datum + Zahl“) nächste Zeile, Preis **und** Stand aus derselben Zeile (1311.22 / 2026-09-28) |
| b | `KupferpreisTest::testFetchErrorEnthaeltKeineInternenDetails` | Ausnahme mit Pfad/IP/PHP-Meldung → `fetchError` ohne diese Details; Ausnahmetext landet im `error_log` |
| b | `MetallzuschlagTest::testFehlschlagInnerhalb30MinutenLiefertAltwertAlsVeraltetOhneAbruf` | Zustand mit `letzterVersuch` −10 min + `fetchError` → `auto_fetch` (Monteur, Admin) und `get`: Altwert, `veraltet: true`, `fetchError`; Rohtext in DB unverändert (ein Abruf hätte `letzterVersuch` gespeichert) |
| b | `MetallzuschlagTest::testFehlschlagOhneAltwertInnerhalb30MinutenOhneAbruf` | `delNotierung: 0` + `letzterVersuch` −5 min → kein Abruf, `fetchError`, `veraltet: false` |
| c | `MetallzuschlagTest::testForcePerGetWirdAbgelehnt` | Admin, GET `force=1` → 405, manueller Wert unverändert (Flag aus → 200 deckt bestehender Test ab) |
| c | `MetallzuschlagTest::testForcePerPostAlsMonteur403` | POST `force` im Body und in der Query als Monteur → 403, nichts gespeichert |
| d | `MetallzuschlagTest::testUngueltigeNotierungWirdAbgelehntUndGespeicherterWertBleibt` (9 Fälle) | Roh-JSON `1e999`, `-5`, `6000`, `"1e999"` für DEL; `1e999`, `-5`, `6000`, `"abc"`, `[150]` für Basis → 400, Rohtext und `get` unverändert (1250/150). Dafür `ApiClient::postRaw()` ergänzt (`json_encode` kann `INF` nicht erzeugen) |
| d | `MetallzuschlagTest::testBasisNotierungNullWirdAngenommen` | Untergrenze 0 (sendet das Frontend) wird gespeichert |
| e | `MetallzuschlagTest::testParallelerFehlschlagUeberschreibtManuellenWertNicht` | Lesestand vor Abruf → manuelles `set` 1300 → Speichern des Fehlschlags mit altem Rohtext liefert `false`, manueller Wert + kein `fetchError`; Gegenprobe mit aktuellem Rohtext schreibt |

**Zu e (Grenze der Testbarkeit):** Der echte Wettlauf ist über HTTP nicht herstellbar – der Abrufer ist im Handler fest verdrahtet (`fetchUrl`, nicht injizierbar, ohne Netz nicht erreichbar) und der PHP-Built-in-Server arbeitet unter Windows seriell (`PHP_CLI_SERVER_WORKERS` nicht unterstützt). Der Test ruft daher `CatalogActions::saveMetallzuschlag()` per Reflection direkt gegen die Test-DB des Servers auf (läuft so auch unter PostgreSQL). Nachteil: hängt am privaten Methodennamen/-vertrag; bei Umbau anpassen.

**Sonstiges:** `KupferpreisTest.php` und `MetallzuschlagTest.php` (aus Runde 0) waren komplett CRLF → `php-cs-fixer check` schlug an; mit `php-cs-fixer fix` auf LF umgestellt (nur Zeilenenden).

**Befunde (kein Fehler, Entscheidung Leitstand):**
- Oberste Zeile mit Zahl in fremdem Format (z. B. `1307.62`) wird wie `-` übersprungen → Parser liefert die ältere Zeile (Stand konsistent, `veraltet` greift nach 5 Tagen). Plan-konform, aber ein Formatwechsel nur in der obersten Zeile bliebe bis dahin unbemerkt. Nicht per Test festgelegt.
- AK 9/10 (Browser) weiterhin nicht belegt – nicht Teil dieses Auftrags.

## 5. Review

### Runde 1 (2026-09-29)

**Urteil: `CHANGES_REQUESTED`** (1 Major)

Geprüft: `git diff` (ungestaged) + neue Dateien laut Abschnitt 3; FREMD (`BackupArchive.php`, `BackupImportTest.php`, `tests/fixtures/aufmass/`) nicht bewertet. Keine Tests/PHPStan gestartet (Leitstand).

**In Ordnung:** Parser unabhängig von CSS-Klassen, dt. Zahlen-/Datumsformat, Entities/NBSP, `<th>`-Kopfzeilen; Fixture ist plausibles Roh-HTML (depage-cms, `charset=UTF-8`). Drosselung 6 h / 30 min, manueller Wert nur mit `force`, `force` → `requireRole('admin','master')` vor Flag-Prüfung, Flag serverseitig in `get` und `auto_fetch`. `set` validiert `delNotierung` (Typ, endlich, 100–5000, Array/Leerstring → 400). SQL unverändert neutral (`UPDATE … WHERE id = 1` mit Parameter), keine Schemaänderung. Kein `@` im neuen Code (E-063). Alle Server-Texte im Frontend über `esc()` (`mzStandText`, `mzVeraltetHinweis`, mobile Info-Leiste). Cache-Busting konsistent: `index.html`/`sw.js` `script.min.js?v=218`, `CACHE_VERSION` `bk-es-v217`, `mobile.html` ist vorgecacht und durch `CACHE_VERSION` abgedeckt; `script.min.js` enthält die neuen Zeichenketten (neu erzeugt). Suffix Desktop = mobil (`' [MZ+' + fmt(x) + ']'`). Abweichung „`[MZ+0,27 €]` statt `[MZ+0,27€]`“ ist begründet (Plan 2.2 Nr. 8 lag falsch: mobil erzeugte `€ €`) – akzeptiert. Kein Live-Netz in den Tests (alle API-Pfade enden vor dem Abruf).

**Tests „passend gemacht“?** Überwiegend nein – die Tests folgen den Abnahmekriterien, nicht der Implementierung. Schwächen siehe Befunde 5 und 6; `testZwischenueberschriftDatumWirdUebersprungen` prüft nur die `<th>`-Variante (wird schon vom `<td>`-Regex ignoriert, also trivial grün).

| Schwere | Datei:Zeile | Problem | Vorschlag |
|---|---|---|---|
| Major | [CatalogActions.php](../../../src/Handlers/CatalogActions.php#L342-L345), [Kupferpreis.php](../../../src/Services/Kupferpreis.php#L115-L122) | **Manueller Wert kann verloren gehen (neu).** Der Fehlschlag-Pfad schreibt jetzt den *vorher gelesenen* Stand + `fetchError` zurück (alter Code schrieb bei Fehlschlag nicht). Typischer Ablauf: Picker zeigt „Keine DEL-Notierung – hinterlegen“, Hintergrund-`auto_fetch` hängt (curl 5 s + `file_get_contents`-Fallback 5 s), Admin klickt den Link und speichert 1300 → der hängende Abruf überschreibt danach mit `{delNotierung: 0, fetchError}`. Verletzt Ziel/AK 4 unter realer Nebenläufigkeit. | Nur bedingt schreiben: `UPDATE metallzuschlag SET data = ? WHERE id = 1 AND data = ?` mit dem gelesenen Rohtext (SQLite/PG neutral); bei 0 betroffenen Zeilen neu laden und ohne Speichern antworten. Gilt für Erfolgs- und Fehlschlagpfad. |
| Minor | [Kupferpreis.php](../../../src/Services/Kupferpreis.php#L113) | `$e->getMessage()` landet in `fetchError` → gespeichert (auch in Sicherungen) und an **alle** Angemeldeten ausgeliefert. Wegen des globalen Error-Handlers ([api.php](../../../public/api.php#L19-L22)) wirft `@file_get_contents` in `fetchUrl()` eine `ErrorException` mit PHP-/Netzwerk-Interna. Alter Code hat nur geloggt. | Feste Meldung („Westmetall-Abruf fehlgeschlagen.“) speichern; Ausnahmetext nur per `error_log()`. |
| Minor | [CatalogActions.php](../../../src/Handlers/CatalogActions.php#L315) | `basisNotierung` nur `is_numeric`: JSON `1e999` → `INF` → `json_encode` liefert `false` → `saveMetallzuschlag` speichert `''` → gespeicherter DEL-Wert weg (Admin-only, aber stiller Datenverlust). | `is_finite` + Bereich prüfen (sonst 400); in `saveMetallzuschlag` `JSON_THROW_ON_ERROR`. |
| Minor | [CatalogActions.php](../../../src/Handlers/CatalogActions.php#L328) | `force=1` per **GET** ändert Zustand (überschreibt manuellen Wert). Session-Cookie `SameSite=Lax` lässt Top-Level-GET-Links durch; Präzedenz [DataActions.php](../../../src/Handlers/DataActions.php#L321-L322) verlangt POST für Zustandsänderungen (dort greift auch die Origin-Prüfung). | `force` nur bei `REQUEST_METHOD === 'POST'` akzeptieren; `autoFetchDel()` auf POST umstellen; Test „GET mit force als Admin → kein Erzwingen/405“. |
| Minor | [KupferpreisTest.php](../../../tests/Unit/KupferpreisTest.php#L117) | Plausibilitätstest ersetzt **alle** Werte → ein Parser, der eine unplausible oberste Zeile überspringt und still einen Vorwert liefert, bestünde ebenso. Genau „oberste Zeile unplausibel → `null` statt Altwert“ (Kern AK 2) ist nicht festgenagelt. Gleiches Muster in [Z. 131](../../../tests/Unit/KupferpreisTest.php#L131) (dort ist Überspringen bewusst nötig → Verhalten dokumentieren). | Zusätzlicher Fall `ersteZeile(wert: '14.544,50')` → `null`. |
| Minor | [MetallzuschlagTest.php](../../../tests/Api/MetallzuschlagTest.php#L13) | Handler-Fehlschlagpfad ungetestet (Speichern von `letzterVersuch`/`fetchError`, `unset veraltet`, Antwortform); AK 3 nur auf Service-Ebene. Würde der Handler den Fehlschlag nicht speichern, wäre die Drossel wirkungslos und kein Test rot. Zudem kein Netz-Sicherheitsnetz: regressiert der Handler zum Abruf, gehen API-Tests unbemerkt ins Live-Netz. | Mindestens: Stand `{delNotierung: 0, letzterVersuch: jetzt, fetchError: 'x'}` direkt schreiben → `auto_fetch` liefert `cached` + `fetchError` ohne Abruf. Optional Abrufer über Konstruktor/Container injizierbar machen. |
| Minor | [script.js](../../../public/script.js#L5359-L5361), [mobile.html](../../../public/mobile.html#L3287-L3290) | `_currentMetallzuschlag` wird nur bei `delNotierung > 0` gesetzt → Antwort `aktiv: false` (Modul abgeschaltet) setzt einen bereits geladenen Wert nicht zurück; offene Sitzungen schlagen bis zum Neuladen weiter auf (AK 7 serverseitig erfüllt, clientseitig verzögert). | `_currentMetallzuschlag = (j && j.delNotierung > 0) ? j : null;` in `get`- und `auto_fetch`-Zweig (Desktop + mobil). |
| Hinweis | Abschnitt 3 „Prüfungen“ | AK 9/10 (Browser: `<b>` als Text, Veraltet-Hinweis, Suffix Desktop/mobil, `3×1,5`) nicht belegt, nur `node --check`. | In Abschnitt 4 manuell prüfen und protokollieren. |
| Hinweis | [script.js](../../../public/script.js#L5384), [mobile.html](../../../public/mobile.html#L3413) | Nebenbefund „€ €/100kg“: `fmt()` hängt „ €“ an. Nur Anzeige, nicht in Belegen – **kein Muss**, aber genau in den geänderten und per AK 9 abzunehmenden Leisten; billig mitzuziehen (`€/100kg` → `/100 kg`, auch Z. 6008, 20116, 20143, 20167). `mzNote` mit `—` ([script.js](../../../public/script.js#L6152)) erscheint nur im Toast → außerhalb Abgrenzung 2.3, nicht in diesem AP. | Entscheidung Leitstand; sonst in Sammel-AP „Zeichensalat/Einheiten“. |
| Hinweis | [Kupferpreis.php](../../../src/Services/Kupferpreis.php#L115-L121) | Nach fehlgeschlagenem `force` auf manuellem Wert bleibt `fetchError` gespeichert → manueller Wert zeigt dauerhaft „veraltet – letzter Abruf fehlgeschlagen“ bis zum nächsten `set`. Nicht spezifiziert; vertretbar, aber bewusst entscheiden. | Im Plan/Changelog festhalten oder `fetchError` bei `quelle = manual` nicht setzen. |

**Lernpunkt-Kandidaten**

| Kategorie | Kandidat | E-Nr |
|---|---|---|
| SQL | Read-Modify-Write auf Einzeilen-JSON nach langsamem externen Aufruf nur bedingt schreiben (`WHERE … AND data = ?`) | keine |
| Sicherheit | Ausnahmetexte nie in gespeicherte/ausgelieferte Felder; globaler Error-Handler macht auch aus `@`-Warnungen Exceptions | verwandt E-063, neu |
| Sicherheit | Zustandsändernde Aktionen (auch Flags wie `force`) nur per POST | keine (nur Code-Kommentar DataActions) |
| Tests | Randfall-Fixtures nur in der betroffenen Zeile manipulieren, sonst besteht auch ein falscher Fallback | keine |
| Planung | Formatierungshelfer (`fmt()` mit Einheit) vor Aussagen „korrekt“ im Ist-Zustand prüfen | keine |

### Runde 2 (2026-09-29)

**Urteil: `APPROVE`** (keine Blocker/Major)

Geprüft: `git diff`/`git status` (Handler, Service, `ApiClient`, script.js, mobile.html, index.html, sw.js, CHANGELOG) + neue Tests laut Abschnitt 4. FREMD nicht bewertet. Keine Tests/PHPStan gestartet (laufen parallel).

**Befunde Runde 1 – Stand:**

| R1-Befund | Status | Beleg |
|---|---|---|
| Major bedingtes Schreiben | behoben | [CatalogActions.php](../../../src/Handlers/CatalogActions.php#L355) → `UPDATE … WHERE id = 1 AND data = ?` ([Z. 412](../../../src/Handlers/CatalogActions.php#L412)); Spalte `data TEXT` in beiden Treibern (`LegacySchema.php` Z. 645), `rowCount()` zählt bei SQLite und PG *getroffene* Zeilen → neutral. Erfolgs- und Fehlschlagpfad abgedeckt. Zeile fehlt/`NULL` → unbedingtes Schreiben wie bisher (unkritisch, Zeile wird im Schema angelegt). |
| Minor `fetchError` mit Interna | behoben | [Kupferpreis.php](../../../src/Services/Kupferpreis.php#L112-L113) feste Meldung, Text nur per `error_log`; Test mit Pfad/IP prüft beides. |
| Minor `basisNotierung` `INF` | behoben | Typ, `is_finite`, 0–5000; `JSON_THROW_ON_ERROR`; 9 Rohwert-Fälle über `postRaw`. |
| Minor `force` per GET | behoben | Reihenfolge `requireAuth` → `requireRole` (403) → Flag → 405 ([Z. 333-338](../../../src/Handlers/CatalogActions.php#L333-L338)) ist schlüssig (kein Zustandswechsel vor 405). `autoFetchDel()` POST + JSON wie `saveMetallzuschlag()`; CSRF-Muster identisch (Origin-Prüfung in `api.php` Z. 83 greift bei POST, JSON-Content-Type erzwingt zusätzlich Preflight). mobile.html nutzt `force` nicht (GET ohne `force` → kein 405). `script.min.js` enthält den POST-Aufruf. |
| Minor Plausibilitätstest | behoben | `testUnplausibleObersteZeileLiefertNullStattAelteremWert` manipuliert nur Zeile 1. |
| Minor Handler-Fehlschlag ungetestet | behoben | Zwei API-Tests mit `letzterVersuch` < 30 min, Rohtext-Vergleich; Wettlauf per Reflection auf `saveMetallzuschlag` (Grenze dokumentiert, akzeptiert). |
| Minor Client-Reset | behoben | `'delNotierung' in j` in `get`- und `auto_fetch`-Zweig, Desktop + mobil; Fehlerantworten ohne Feld lassen den Wert stehen. |
| Hinweise „€ €“, veraltet nach `force` | Entscheidung Leitstand | geänderte Leisten auf `/100 kg`; Rest (MZ-Badge, `saveMetallzuschlag()`) bewusst außerhalb. |

Cache-Busting konsistent: `index.html`/`sw.js` `script.min.js?v=219`, `CACHE_VERSION` `bk-es-v218`. `ApiClient::postRaw()` ist sinnvoll (einzige Möglichkeit, `1e999` zu senden), minimal und folgt `post()`.

| Schwere | Datei:Zeile | Problem | Vorschlag |
|---|---|---|---|
| Hinweis | [CatalogActions.php](../../../src/Handlers/CatalogActions.php#L355-L362), [script.js](../../../public/script.js#L20145) | Verliert ein **erzwungener** Abruf das bedingte Schreiben (Admin speichert parallel manuell), antwortet der Handler mit `cached: true` und dem manuellen Wert ohne `fetchError` → `autoFetchDel()` meldet „DEL-Notierung automatisch abgerufen: 1.300,00 €“, obwohl es der manuelle Wert ist. Selten, Wert selbst korrekt. | Optional: im Frontend `j.cached`/`j.quelle === 'manual'` berücksichtigen oder Konflikt kennzeichnen. Kein Muss. |
| Hinweis | [script.js](../../../public/script.js#L20109) | `loadMetallzuschlagSettings()` setzt `_currentMetallzuschlag` bei `delNotierung <= 0`/`aktiv: false` nicht zurück (anders als Fix 5). Folgenlos, da `fetchMetallzuschlag()` vor jeder Picker-Nutzung läuft. | Bei Gelegenheit angleichen. |
| Hinweis | [MetallzuschlagTest.php](../../../tests/Api/MetallzuschlagTest.php#L235-L255) | Der Gutfall „Admin + POST + `force` passiert die 405-Sperre“ ist automatisch nicht prüfbar (würde live abrufen). Eine vertauschte Methodenprüfung bliebe grün. AK 9/10 (Browser) weiterhin offen. | Beim Browser-Test (AK 9) einmal „automatisch abrufen“ im Admin-Modal klicken und Ergebnis in Abschnitt 4 protokollieren. |

**Lernpunkt-Kandidaten**

| Kategorie | Kandidat | E-Nr |
|---|---|---|
| SQL | Read-Modify-Write nach langsamem externen Aufruf nur bedingt schreiben (`WHERE … AND data = ?`, `rowCount()` prüfen) – hier sauber umgesetzt, als Regel festhalten | keine |
| Sicherheit | Zustandsändernde Flags (`force`) nur per POST; 405 erst nach Rollen-/Flag-Prüfung | keine |
| Tests | Parser-Randfälle nur in der betroffenen Zeile manipulieren; bewusst tolerierte Fälle (fremdes Zahlenformat oben → ältere Zeile, Leitstand) als Erkenntnis dokumentieren | keine |
| Tests | Nicht injizierbare externe Abrufe verhindern Gutfall-Tests (Admin-`force`) → Abrufer künftig injizierbar planen | keine |

## 6. Lernpunkte

**Gesamtlauf (Leitstand, nach Runde 2):** SQLite 242 Tests / 1648 Assertions OK, PHPStan ohne Fehler. PostgreSQL **ausstehend** –
Docker-Dienst nicht erreichbar; Testskript brach korrekt ab (E-064). Browser-Abnahme AK 9/10 durch Nutzer offen.

Neu in `erkenntnisse.md`: E-003, E-014, E-015, E-033, E-034, E-071. E-032 zum 2. Mal → Regel in `ki-leitstand.agent.md`.
Prozessabweichung: Tester legte in Runde 0 Produktivcode an (E-071, Hook-Kandidat Stufe 3); Entwickler hat den Entwurf geprüft und übernommen.
