# DIN EN 1090 Modul – Installation

## Voraussetzungen
- Baukalkulation App bereits installiert und lauffähig
- PHP 8.1+, SQLite

## Installation

### 1. Modul-Dateien kopieren
Das gesamte Verzeichnis `modules/din1090/` in das Baukalkulation-Hauptverzeichnis kopieren.
Die Datei `din1090_api.php` ebenfalls ins Hauptverzeichnis kopieren.

### 2. In `index.html` einfügen

**CSS einbinden** – im `<head>` vor `</head>`:
```html
<link rel="stylesheet" href="modules/din1090/din1090.css">
```

**JS einbinden** – vor `</body>`:
```html
<script src="modules/din1090/din1090.js"></script>
```

**Sidebar-Navigation** – neuen Button in der Sidebar einfügen (im Bereich `sidebar-nav-section`):
```html
<button class="sidebar-overview-btn" onclick="showDin1090()">🏗️ DIN EN 1090</button>
```

**View-Container** – im `<main>` Bereich (bei den anderen hidden Views):
```html
<div id="din1090View" class="hidden"></div>
```

### 3. In `script.js` einfügen

**Funktion `showDin1090()`** – z.B. nach `showUebersicht()`:
```javascript
function showDin1090() {
  document.querySelectorAll('.sidebar-overview-btn').forEach(b => b.classList.remove('active'));
  const btn = [...document.querySelectorAll('.sidebar-overview-btn')].find(b => b.textContent.includes('DIN EN 1090'));
  if (btn) btn.classList.add('active');
  document.querySelectorAll('main > div').forEach(v => v.classList.add('hidden'));
  document.getElementById('din1090View').classList.remove('hidden');
  renderDin1090();
}
```

**In `hideAllViews()` ergänzen** (falls vorhanden):
```javascript
document.getElementById('din1090View')?.classList.add('hidden');
```

### 4. Datenbank
Die Datenbank-Tabellen werden automatisch beim ersten API-Aufruf erstellt. Es ist kein manuelles Migrationsskript nötig.

## Deinstallation
1. Die oben genannten Einträge aus `index.html` und `script.js` entfernen
2. Die Dateien `modules/din1090/` und `din1090_api.php` löschen
3. Optional: SQLite-Tabellen `din1090_*` manuell entfernen

## Enthaltene DIN EN 1090 Funktionen

| Bereich | Beschreibung |
|---------|-------------|
| **Projekte** | Projektverwaltung mit Ausführungsklassen EXC1–EXC4, Baustellen-Zuordnung |
| **Material** | Materialverfolgung mit Werkszeugnissen nach EN 10204 (2.1/3.1/3.2), Chargen & Schmelznummern |
| **Schweißer** | Schweißerqualifikationen nach EN ISO 9606, Gültigkeitsüberwachung |
| **WPS** | Schweißanweisungen nach EN ISO 15609, WPQR-Zuordnung |
| **Schweißprotokoll** | Nahtliste mit Schweißer-/WPS-Zuordnung, Positionsangabe, a-Maß, Prüfstatus |
| **Prüfungen** | ZfP-Dokumentation (VT/PT/MT/UT/RT) nach EN 1090-2 Tabelle 24 |
| **Oberfläche** | Korrosionsschutz nach EN ISO 12944, Schichtdickenmessungen |
| **Abweichungen** | NCR-Management (Non-Conformity Reports) mit Nachverfolgung |
| **Checklisten** | Automatisch generierte Prüflisten nach DIN EN 1090-2 Tabelle A.1 je EXC |
| **Audit-Protokoll** | Lückenlose Änderungsverfolgung, Aufbewahrung gemäß Normvorgabe (≥10 Jahre) |

## Referenzierte Normen
- DIN EN 1090-1: Konformitätsbewertung von Tragwerkskomponenten
- DIN EN 1090-2: Technische Regeln für Stahlkonstruktionen
- EN 10204: Metallische Erzeugnisse – Arten von Prüfbescheinigungen
- EN ISO 9606-1: Prüfung von Schweißern – Schmelzschweißen
- EN ISO 14731: Schweißaufsicht – Aufgaben und Verantwortung
- EN ISO 15609: Anforderung und Qualifizierung von Schweißverfahren
- EN ISO 15614: Schweißverfahrensprüfung
- EN ISO 5817: Bewertungsgruppen von Unregelmäßigkeiten
- EN ISO 12944: Korrosionsschutz von Stahlbauten
- EN ISO 8501: Vorbereitung von Stahloberflächen
- EN ISO 17637/3452/17638/17640/17636: ZfP-Prüfnormen
