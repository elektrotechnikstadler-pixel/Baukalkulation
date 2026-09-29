---
name: modul-anlegen
description: "Neues Fachmodul der Baukalkulation anlegen oder erweitern: module.json-Manifest, Backend-Klasse (AbstractModule), Rechte, Feature-Flag, Lizenz, Datenbanktabellen, Frontend-JS/CSS und Tests. Use when creating a new module under modules/ or adding endpoints to a module."
argument-hint: "Modulname und Zweck, z. B. 'fuhrpark – Fahrzeuge und Prüftermine'"
---
# Modul anlegen

Referenzmodul: `modules/lager/` (Backend) und `public/modules/lager/` (Frontend).
Lader: `src/Core/ModuleLoader.php`, Basisklasse: `src/Core/AbstractModule.php`.

## Ablauf

1. **Name festlegen:** nur `[a-z][a-z0-9_]*` (sonst verwirft der ModuleLoader das Modul).
2. **Manifest** `modules/<name>/module.json` nach dem Muster von `lager`:
   `name`, `title`, `version`, `icon`, `tier`, `module_file`, `module_class` (`App\\Modules\\<Name>\\Module`),
   `frontend.js/css` (Pfade relativ zu `public/`), `permissions` (Rollen), `feature_flag`, `depends_on`.
3. **Backend** `modules/<name>/backend/Module.php`, Klasse erbt von `AbstractModule`:
   - `dispatch(string $action)`: jede Aktion mit `$this->requirePerm('can…')`, unbekannte Aktion → Fehler.
   - Deaktivierung über Einstellung `modul_<name>` wie bei `lager` prüfen.
   - Schreibaktionen ins Audit-Log (`AuditService`).
4. **Rechte:** neue `can…`-Rechte in `src/Auth.php` bei den Rollen ergänzen und Beschriftung in `public/script.js` (Rechteliste) nachziehen.
5. **Einstellung/Schalter:** Default `modul_<name> => false` in `src/Auth.php`; Schalter in den Einstellungen von `public/script.js`.
6. **Datenbank:** Vor dem Anlegen prüfen, wie bestehende Module ihr Schema anlegen (`migrate()` im Modul vs. Phinx-Migration)
   und dem Nutzer die Variante im Plan zur Entscheidung vorlegen. SQL-Regeln aus `php-backend.instructions.md` gelten.
   Neue Tabellen müssen in Sicherungen enthalten und wieder importierbar sein.
7. **Lizenz:** `LicenseService::isModuleAllowed()` prüft den Modulnamen – `license.example.json` ergänzen.
8. **Frontend** `public/modules/<name>/<name>.js|.css`: Modul-Schalter und `canDo('can…')` vor jeder Anzeige prüfen;
   Cache-Busting laut `frontend.instructions.md`.
9. **Tests:** API-Test in `tests/Api/` – Aktion ohne Recht → abgelehnt, mit Recht → erwartetes Ergebnis,
   Modul deaktiviert → abgelehnt; auf SQLite und PostgreSQL.
10. **Doku:** Modul kurz in `CHANGELOG.md` aufführen.

## Checkliste für den Reviewer

- [ ] Jede Aktion prüft Rechte, Modul-Schalter und Eingaben
- [ ] Tabellen laufen auf SQLite und PostgreSQL, Backup/Restore enthält sie
- [ ] Manifest-Name = Ordnername, Klasse ladbar
- [ ] Lizenz- und Feature-Gating greifen
