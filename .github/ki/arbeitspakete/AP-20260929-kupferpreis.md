# AP-20260929-kupferpreis: Abruf der Kupferpreise prüfen

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Bugfix |
| Testläufe rot | 0 |
| Review-Runden | 0 |
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

**Freigabe G1:** ☐ durch Nutzer am …

## 3. Umsetzung

## 4. Tests

## 5. Review

## 6. Lernpunkte
