# AP-20261101-notizen-bearbeiten: Schnellnotizen nachträglich bearbeiten

| Feld | Wert |
|---|---|
| Status | geplant |
| Typ | Feature |
| Testläufe rot | 0 |
| Review-Runden | 0 |
| Commit | – |

## 1. Auftrag

> Schnellnotizen/ Notizen:
> - Bearbeitung soll im Nachhinein möglich sein

## 2. Plan

### Ziel & Abgrenzung
- **Ziel:** Bereits erstellte **Schnellnotizen** sind nachträglich bearbeitbar (mindestens Text, ggf. Zuweisung) – auf Desktop und Mobile.
- **Befund (belegt):** Es gibt zwei Notiz-Arten. **Dashboard-Notizen** (`dashboard_items`, `typ='notiz'`) sind bereits über `saveItem()` bearbeitbar. **Schnellnotizen** (Tabelle `schnellnotizen`, Handler `PlanActions`) haben nur `load/save/archive/delete` – **kein Update**. Daher betrifft der Auftrag die **Schnellnotizen**.
- **Nicht Teil:** Dashboard-Notizen (schon bearbeitbar), Kunden-/Rechnungs-Notizfelder (über normale Formulare bearbeitbar).

### Betroffene Dateien
| Datei | Begründung |
|---|---|
| `src/Handlers/PlanActions.php` | Neue Methode `updateSchnellnotiz()` (UPDATE `text`, ggf. `zugewiesen_an`, `baustelleId/Name`), mit Rechte-/Ersteller-Prüfung analog `saveSchnellnotiz`/`archiveSchnellnotiz` (≈460–517). |
| `public/api.php` | Routing `update_schnellnotiz` → `PlanActions::updateSchnellnotiz` (im Schnellnotizen-Block ≈333–337); nur POST. |
| `public/script.js` | `renderSchnellnotizCard()` (≈21800) „Bearbeiten“-Button; neue `editSchnellnotiz(id)`/`updateSchnellnotiz()` (Modal + POST). Auch Baustellen-Schnellnotiz-Karten. |
| `public/mobile.html` | Mobile Schnellnotiz-Karte (≈7630) „Bearbeiten“ + `editSchnellnotizMobile(id)` → `update_schnellnotiz`. |
| `public/bedienungsanleitung.html` | Sichtbare Funktion dokumentieren (E-081). |
| `public/script.min.js`, `public/index.html`, `public/sw.js` | Cache-Busting. |
| `tests/Api/` | Update-Endpunkt (Rechte, Feldwirkung, Nicht-Ersteller). |

### Struktur (Vorlage copier-astral)
- Neuer API-Endpunkt im bestehenden Handler/Routing; keine Migration (Spalten existieren). Keine neue Struktur (E-050).

### Berücksichtigte Erkenntnisse
- **E-081** Anleitung. **E-082/E-090** DB-Läufe/Tests. **E-015** Update nur per POST, 405 sonst (nach Rechteprüfung). **E-013/E-014** keine sensiblen/Internas-Ausgaben. **E-084** keine stillen Zusatzfilter – Rechtelogik wie `save/archive` dokumentieren. **E-086** alle Schreibpfade derselben Daten (Desktop, Baustellenansicht, Mobil) gemeinsam berücksichtigen. **E-087** `mobile.html`/Dateien nur per Edit/Create/Node.

### Schritte
1. **Backend `updateSchnellnotiz()`:** `UPDATE schnellnotizen SET text=?, zugewiesen_an=? WHERE id=?`; Rechte: Ersteller oder admin/master (an `archive`/`delete`-Logik orientieren, die nur admin/master erlaubt → **offene Frage**, ob Ersteller selbst editieren darf). Nicht gefunden → 404.
2. **Routing** `update_schnellnotiz` registrieren (POST).
3. **Frontend Desktop:** „Bearbeiten“ in `renderSchnellnotizCard()` (und Baustellen-Variante) + Modal/Inline-Edit; sendet `update_schnellnotiz`, danach `loadSchnellnotizenDesktop()`.
4. **Frontend Mobile:** „Bearbeiten“ in der Schnellnotiz-Karte + `editSchnellnotizMobile()` (Modal/`prompt`) → `update_schnellnotiz`, danach Reload.
5. **Archivierte Notizen:** Bearbeiten nur für nicht-archivierte (offene Frage).
6. **Anleitung** ergänzen.
7. **Cache-Busting** (Minify, `?v=`, `CACHE_VERSION`).
8. **Tests** (siehe Abnahme).

### Risiken
- **Rechte:** `archive`/`delete` sind heute nur admin/master. Beim Editieren Rechtemodell bewusst festlegen (Ersteller vs. nur admin/master) und konsistent mit `save` prüfen – sonst Rechteloch oder zu strenge Sperre.
- **SQLite/PostgreSQL:** Reines `UPDATE`, keine Dialekt-Besonderheit; beide Läufe grün (E-082).
- **Backup/Migration:** Keine Migration; keine Fixture-Neuerzeugung.
- **Alte Clients (E-088):** Neuer Endpunkt additiv; alte Clients funktionieren unverändert (nur ohne Bearbeiten).
- **Cache-Busting:** `script.js`/`mobile.html` → Versions-Bump.

### Abnahmekriterien
1. Eine bestehende Schnellnotiz lässt sich auf dem Desktop bearbeiten (Text ändern); nach Reload ist der neue Text gespeichert.
2. Gleiches auf Mobile.
3. Ein nicht berechtigter Nutzer erhält 403; nicht vorhandene ID → 404.
4. `update_schnellnotiz` nur per POST (GET → 405 nach Rechteprüfung).
5. Archivierte Notizen folgen der festgelegten Regel (editierbar oder gesperrt).
6. Dashboard-Notizen bleiben unverändert bearbeitbar (keine Regression).

### Offene Fragen
- Darf der **Ersteller** seine eigene Schnellnotiz bearbeiten, oder nur **admin/master** (wie bei Archivieren/Löschen)?
- Sollen **archivierte** Schnellnotizen bearbeitbar sein?
- Welche Felder editierbar – nur `text`, oder auch Zuweisung/Baustellenbezug?

**Entscheidung Nutzer (2026-11-01):** Bearbeiten dürfen alle Nutzer, die die Schnellnotiz sehen (gleiche Sichtbarkeitsprüfung wie beim Laden).

**Freigabe G1:** ☐ durch Nutzer am …

## 3. Umsetzung

## 4. Tests

| Abnahmekriterium | Test (Datei::Methode) | SQLite | PostgreSQL | JS |
|---|---|---|---|---|

## 5. Review

## 6. Lernpunkte
