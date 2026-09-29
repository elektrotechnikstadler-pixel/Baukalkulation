---
description: "Use when editing browser code: public/script.js, index.html, service worker, module JS/CSS, cache busting, app version."
applyTo: "**/public/**/*.js, **/public/**/*.html, **/public/**/*.css"
---
# Frontend

- Nach Änderung an `public/script.js`: `npm.cmd run minify`, `?v=` in `index.html` und `sw.js`
  sowie `CACHE_VERSION` in `sw.js` erhöhen; `node scripts/check-versions.mjs --strict` muss grün sein.
- Version nur in `VERSION` ändern, dann `npm.cmd run version:sync`.
- Modul-JS/CSS liegen in `public/modules/<name>/`, Backend in `modules/<name>/`.
- `script.min.js` ist generiert – nie direkt bearbeiten.
- Das Vue/TS-Frontend unter `src/frontend/` ist inaktiv; dort nichts Neues anlegen.
- Nutzerdaten per `textContent` bzw. escaped einfügen, nie ungeprüft per `innerHTML`.
