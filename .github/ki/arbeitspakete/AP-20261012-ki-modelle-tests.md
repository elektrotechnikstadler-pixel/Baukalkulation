# AP-20261012-ki-modelle-tests: Modelle je Rolle und dauerhafte Regressionstests

| Feld | Wert |
|---|---|
| Status | abgeschlossen |
| Typ | Doku |
| Testläufe rot | 0 |
| Review-Runden | 1 |
| Commit | – |

## 1. Auftrag

Vorgabe des Nutzers (2026-10-12) für alle künftigen Änderungen:

- Alle Tests werden gespeichert und bei jeder neuen Änderung wieder durchlaufen; neue Tests kommen hinzu.
- Verschiedene KI-Modelle für die jeweiligen Aufgaben nutzen: Planer, Entwickler, Tester und Review.
- Alle Neuerungen nach `AGENTS.md`.

Dieses AP wird **vor** AP-20261012-datensicherung-nas umgesetzt, damit dieses bereits mit den neuen Regeln läuft.

## 2. Plan

### 2.1 Ist-Stand

| Thema | Stand | Lücke |
|---|---|---|
| Tests dauerhaft | 39 PHPUnit-Dateien (`tests/Unit`, `tests/Api`), 1 JS-Test (`tests/js`), CI führt `phpunit` (SQLite + PG-Matrix) und `npm run test:js` aus | Leitstand-Gesamtlauf vor Commit nennt nur PHPUnit (SQLite + PG), nicht `test:js`; Zuordnung Abnahmekriterium → Test fehlt im AP |
| Tests nicht abschwächen | Regel in `tests.instructions.md` | nicht in `AGENTS.md` (wird nur bei `tests/**` geladen) |
| Modelle je Rolle | Stufe 4 in `docs/ki-architektur.md` „geplant“; keine `model:`-Angabe in `.github/agents/*.agent.md` | alle Rollen laufen mit dem Sitzungsmodell → Reviewer hat dieselben blinden Flecken wie der Entwickler |

### 2.2 Entscheidung des Nutzers (2026-10-12)

| Rolle | Modell (1. Wahl) | Ersatz (Reihenfolge) | Begründung |
|---|---|---|---|
| KI-Leitstand | Claude Sonnet (aktuell) | GPT-5.x | Steuerung, viele Werkzeugaufrufe |
| Architekt / Planer | Claude Opus (aktuell) | GPT-5.x (hohe Denkstufe) | gründliche Analyse und Planung |
| Entwickler | Claude Sonnet (aktuell) | Claude Opus | Code-Umsetzung |
| Tester | GPT-5.x-Codex | GPT-5.x | andere Familie als Entwickler, testorientiert |
| Reviewer | GPT-5.x | GPT-5.x-Codex | **bewusst andere Modellfamilie als der Entwickler** |

Exakte Modellnamen werden bei der Umsetzung gegen den VS-Code-Modellwähler geprüft (Format z. B. `Claude Sonnet 5 (copilot)`); `model:` als Liste = Ersatzreihenfolge.

### 2.3 Änderungen

1. `.github/agents/*.agent.md`: Frontmatter `model:` (Liste) je Rolle gemäß 2.2.
2. `AGENTS.md` → „Unverhandelbar“, eine Zeile: *Tests sind dauerhaft: jedes AP ergänzt Tests zu seinen Abnahmekriterien; vor jedem Commit laufen PHPUnit (SQLite + PostgreSQL) und `npm.cmd run test:js` vollständig; Tests nur mit Begründung im AP ändern oder löschen.*
3. `ki-leitstand.agent.md`: Gesamtlauf um `npm.cmd run test:js` ergänzen; Hinweis, Subagenten mit ihrem Rollen-Agenten (nicht generisch) aufzurufen, damit `model:` greift – Rückfall nach E-066 im AP vermerken.
4. `tester.agent.md`: Testmatrix „Abnahmekriterium → Testdatei::Methode“ im AP Abschnitt 4 pflegen.
5. `_vorlage.md`: Abschnitt 4 um die Tabelle „Abnahmekriterium | Test | SQLite | PostgreSQL | JS“ ergänzen.
6. `docs/ki-architektur.md`: Stufe 4 „Modelle je Rolle“ → umgesetzt, Rollentabelle um Spalte „Modell“ ergänzen.
7. `.github/ki/erkenntnisse.md` (Planung / Zusammenarbeit): E-089 Modelle je Rolle (Reviewer ≠ Entwickler-Familie), E-090 Tests dauerhaft + Gesamtlauf inkl. `test:js` – beide „Vorgabe Nutzer 2026-10-12“, Umgesetzt in den o. g. Dateien.

### 2.4 Struktur / Budgets

Nur KI-Dateien und `docs/`; Budgets einhalten (AGENTS.md ≤ 50, Agents ≤ 80 Zeilen). Kein Code, keine Version, kein Release – eigener Commit `docs(ki): Modelle je Rolle und dauerhafte Regressionstests`.

### 2.5 Risiken

| Risiko | Gegenmaßnahme |
|---|---|
| Modellname nicht verfügbar → Agent fällt auf Sitzungsmodell zurück | Ersatzliste; Leitstand prüft beim Start im AP, welches Modell lief |
| Generischer Subagent (E-066) ignoriert `model:` | im AP vermerken; Review dann bewusst mit anderem Modell starten |
| `test:js` verlängert Gesamtlauf | < 5 s, vernachlässigbar |

### 2.6 Abnahmekriterien

1. Alle 5 Agenten zeigen im Agent-Wähler das zugeordnete Modell; YAML gültig.
2. `AGENTS.md` ≤ 50 Zeilen, Agents ≤ 80 Zeilen.
3. Leitstand-Ablauf nennt PHPUnit SQLite + PostgreSQL + `test:js` als Pflicht-Gesamtlauf.
4. E-089/E-090 in `erkenntnisse.md` mit „Umgesetzt in“.

**Freigabe G1:** ☑ durch Nutzer am 2026-10-12

## 3. Umsetzung

Runde 1 (Leitstand direkt, reine KI-/Doku-Dateien):
- `.github/agents/*.agent.md`: `model:` je Rolle. Format `'Name (copilot)'`, belegt durch das mitgelieferte `Explore.agent.md`. Leitstand und Architekt: Ersatz `Auto`. Entwickler, Tester und Reviewer **ohne** `Auto`, damit die Familientrennung nicht still unterlaufen wird (Abweichung zu 2.2 nach Review R1).
- `AGENTS.md`: zwei Zeilen (E-090 dauerhafte Tests, E-089 Modelle), jetzt 35 Zeilen.
- `ki-leitstand.agent.md`: Gesamtlauf inkl. `test:js` (für Subagenten ausgenommen, < 5 s); tatsächlich gelaufenes Modell je Aufruf im AP vermerken.
- `tester.agent.md`: `test:js`, Testmatrix im AP.
- `_vorlage.md`: Testmatrix in Abschnitt 4.
- `docs/ki-architektur.md`: Modelltabelle, Absatz „Dauerhafte Tests“, Stufe 4 umgesetzt, neue Stufe 5.
- `erkenntnisse.md`: E-089, E-090.
- Offen: Die Modellnamen konnten nicht gegen den Modellwähler geprüft werden. Nutzer bitte im Agent-Wähler kontrollieren. Ist ein Name falsch, greift der nächste Listeneintrag.

## 4. Tests

Keine Code-Änderung, daher kein PHPUnit-Lauf nötig. Geprüft:

| Abnahmekriterium | Prüfung | Ergebnis |
|---|---|---|
| 1 Frontmatter gültig | Skript: alle Frontmatter-Schlüssel wohlgeformt, `model:` als Liste | ok |
| 2 Budgets | AGENTS.md 35/50, Agents 32–43/80 | ok |
| 3 Gesamtlauf inkl. `test:js` | `ki-leitstand.agent.md` | ok |
| 4 E-089/E-090 | `erkenntnisse.md`, „Umgesetzt in“ gepflegt | ok |
| Kodierung (E-087) | kein `Ã`/`â€` in geänderten Dateien | ok |

## 5. Review

Runde 1 (Code-Review-Agent): 3 Befunde, Schwere Mittel, alle behoben:
- R1: `Auto` als Ersatz konnte E-089 unterlaufen → bei Entwickler, Tester und Reviewer entfernt; Modell je Aufruf vermerken.
- R2: Tester fehlte in AGENTS.md und im Leitstand → überall „Tester und Reviewer“.
- R3: `test:js` für den Tester widersprach E-032 → `test:js` ausdrücklich ausgenommen.

Bestehender Widerspruch (nicht Teil dieses AP): Schritt 3 von `tester.agent.md` nennt einen vollen `phpunit`-Lauf, E-032 verlangt für Subagenten `--filter`. Kandidat für die nächste Retro.

## 6. Lernpunkte

E-089, E-090 (Vorgabe Nutzer, auf Wunsch sofort übernommen).
