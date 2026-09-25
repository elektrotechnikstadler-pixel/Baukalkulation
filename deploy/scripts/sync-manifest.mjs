#!/usr/bin/env node
// ============================================================
// scripts/sync-manifest.mjs – Post-Build: Vite-Manifest → index.html + sw.js
// ============================================================
// Liest dist/.vite/manifest.json (erzeugt von `vite build`) und aktualisiert
// automatisch die Referenzen auf gehashte Vite-Entry-Bundles in:
//   - index.html    → <script type="module" src="dist/core.[hash].js">
//   - sw.js         → PRECACHE_URLS entry './dist/core.[hash].js'
//
// So entfällt das manuelle Bumpen von dist/core.js?v=NN bei jedem Vite-Build.
//
// Aufruf (automatisch via `npm run build`):
//   node scripts/sync-manifest.mjs
// ============================================================

import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, resolve }                        from 'node:path';
import { fileURLToPath }                           from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT      = resolve(__dirname, '..');

// ── Manifest lesen ────────────────────────────────────────────────────────────
const manifestPath = resolve(ROOT, 'dist/.vite/manifest.json');
if (!existsSync(manifestPath)) {
    console.error('[sync-manifest] dist/.vite/manifest.json nicht gefunden.');
    console.error('                → Zuerst `npm run build` (nur vite build) ausführen.');
    process.exit(1);
}

const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));

/** Sucht den gehashten Dateinamen für einen Entry-Src-Pfad */
function findEntry(srcPath) {
    const entry = manifest[srcPath];
    if (!entry) return null;
    return entry.file; // z.B. "core.abc12345.js"
}

// ── Relevante Entries ────────────────────────────────────────────────────────
// REVERT v2.10.69 (Option A): App läuft rein nativ. Weder Desktop (core) noch
// Mobil (mobile-init/-light) laden noch Vue → keine Manifest-Refs zu syncen.
// Der Vite-Build erzeugt die Bundles weiterhin (Backup), sie werden aber von
// keiner HTML-Datei mehr referenziert.
const ENTRIES = {};

let updated = false;

for (const [srcPath, name] of Object.entries(ENTRIES)) {
    const file = findEntry(srcPath);
    if (!file) {
        console.warn(`[sync-manifest] Entry "${srcPath}" nicht im Manifest – übersprungen.`);
        continue;
    }

    const distRef  = `dist/${file}`;          // z.B. "dist/core.abc12345.js"
    const oldPat   = new RegExp(`dist/${name}\\.[a-zA-Z0-9_-]+\\.js`, 'g');
    const oldLegacy= new RegExp(`dist/${name}\\.js\\?v=[0-9]+`, 'g');

    // ── index.html aktualisieren ──────────────────────────────────────────────
    const indexPath = resolve(ROOT, 'index.html');
    let   indexHtml = readFileSync(indexPath, 'utf8');
    const indexPrev = indexHtml;

    // Bestehenden Hash-Ref ersetzen (oder alten ?v= Legacy-Ref)
    indexHtml = indexHtml.replace(oldPat, distRef);
    indexHtml = indexHtml.replace(oldLegacy, distRef);

    if (indexHtml !== indexPrev) {
        writeFileSync(indexPath, indexHtml, 'utf8');
        console.log(`[sync-manifest] index.html → ${distRef}`);
        updated = true;
    } else if (name === 'core' && !indexHtml.includes(distRef)) {
        // Nur für core warnen (mobile-init ist ausschließlich in mobile.html)
        console.warn(`[sync-manifest] WARNUNG: Kein Vite-Entry-Ref für "${name}" in index.html gefunden.`);
    }

    // ── mobile.html aktualisieren (falls Entry mobile-init) ──────────────────
    if (name === 'mobile-init') {
        const mobilePath = resolve(ROOT, 'mobile.html');
        let   mobileHtml = readFileSync(mobilePath, 'utf8');
        const mobilePrev = mobileHtml;

        mobileHtml = mobileHtml.replace(oldPat, distRef);
        mobileHtml = mobileHtml.replace(oldLegacy, distRef);

        if (mobileHtml !== mobilePrev) {
            writeFileSync(mobilePath, mobileHtml, 'utf8');
            console.log(`[sync-manifest] mobile.html → ${distRef}`);
            updated = true;
        } else if (!mobileHtml.includes(distRef)) {
            console.warn(`[sync-manifest] WARNUNG: Kein Vite-Entry-Ref für "${name}" in mobile.html gefunden.`);
        }
    }

    if (name === 'mobile-light-init') {
        const lightPath = resolve(ROOT, 'mobile_light.html');
        let   lightHtml = readFileSync(lightPath, 'utf8');
        const lightPrev = lightHtml;

        lightHtml = lightHtml.replace(oldPat, distRef);
        lightHtml = lightHtml.replace(oldLegacy, distRef);

        if (lightHtml !== lightPrev) {
            writeFileSync(lightPath, lightHtml, 'utf8');
            console.log(`[sync-manifest] mobile_light.html → ${distRef}`);
            updated = true;
        } else if (!lightHtml.includes(distRef)) {
            console.warn(`[sync-manifest] WARNUNG: Kein Vite-Entry-Ref für "${name}" in mobile_light.html gefunden.`);
        }
    }

    // ── sw.js aktualisieren ───────────────────────────────────────────────────
    const swPath = resolve(ROOT, 'sw.js');
    let   swJs   = readFileSync(swPath, 'utf8');
    const swPrev = swJs;

    swJs = swJs.replace(oldPat, distRef);
    swJs = swJs.replace(oldLegacy, distRef);

    if (swJs !== swPrev) {
        writeFileSync(swPath, swJs, 'utf8');
        console.log(`[sync-manifest] sw.js   → ${distRef}`);
        updated = true;
    }
}

if (updated) {
    console.log('[sync-manifest] Refs aktualisiert. Bitte index.html + sw.js committen.');
} else {
    console.log('[sync-manifest] Alle Refs bereits aktuell – keine Änderungen.');
}
