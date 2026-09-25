#!/usr/bin/env node
// ============================================================
// check-versions.mjs – Cache-Busting-Versions-Abgleich
// ============================================================
// Prüft, ob die manuell gepflegten ?v=NN-Versionen über alle
// Dateien konsistent sind. Hintergrund: Drift zwischen
// Service-Worker-Precache und index.html (z.B. sw v119 vs
// index v149) führte zu veralteten Skripten und JS-Fehlern in
// diversen Browsern.
//
// Geprüft wird:
//   A) Asset-Versionen  : index.html  <-> sw.js (PRECACHE_URLS)
//        - script.js?v= / style.css?v= / dist/core.js?v=
//   B) Modul-Versionen  : script.js   <-> mobile.html
//        - modules/<mod>/<mod>.js?v= und .css?v=
//   C) Hinweis          : aktuelle sw.js CACHE_VERSION ausgeben
//
// Verhalten:
//   - Standard: nur WARNEN (Exit-Code 0) – blockiert nichts.
//   - --strict: bei Drift Exit-Code 1 (für CI/Build-Gates).
//
// Aufruf:  node scripts/check-versions.mjs [--strict]
//          npm run check-versions
// ============================================================

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');
const STRICT = process.argv.includes('--strict');

const problems = [];
const warn = (msg) => problems.push(msg);

function read(rel) {
  try {
    return readFileSync(resolve(ROOT, rel), 'utf8');
  } catch {
    warn(`Datei nicht lesbar: ${rel}`);
    return '';
  }
}

// Findet die erste ?v=NN-Version für einen Asset-Pfad in einem Text.
function findVersion(text, assetPath) {
  // z.B. assetPath = 'script.js' -> /script\.js\?v=(\d+)/
  const re = new RegExp(escapeRegex(assetPath) + '\\?v=(\\d+)', 'g');
  const versions = new Set();
  let m;
  while ((m = re.exec(text)) !== null) versions.add(m[1]);
  return [...versions];
}

function escapeRegex(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

const indexHtml = read('public/index.html');
const swJs = read('public/sw.js');
const scriptJs = read('public/script.js');
const mobileHtml = read('public/mobile.html');

// ── A) Asset-Versionen: index.html <-> sw.js ────────────────
// Seit v2.10.70 wird die minifizierte Bundle-Datei ausgeliefert, nicht script.js.
const ASSETS = ['style.css', 'script.min.js', 'dist/core.js'];
for (const asset of ASSETS) {
  const inIndex = findVersion(indexHtml, asset);
  const inSw = findVersion(swJs, asset);

  if (inIndex.length === 0) { warn(`A) ${asset}: keine ?v= in index.html gefunden.`); continue; }
  if (inSw.length === 0)    { warn(`A) ${asset}: keine ?v= in sw.js (PRECACHE_URLS) gefunden.`); continue; }
  if (inIndex.length > 1)   warn(`A) ${asset}: mehrere verschiedene Versionen in index.html: ${inIndex.join(', ')}`);
  if (inSw.length > 1)      warn(`A) ${asset}: mehrere verschiedene Versionen in sw.js: ${inSw.join(', ')}`);

  if (inIndex[0] && inSw[0] && inIndex[0] !== inSw[0]) {
    warn(`A) ${asset}: index.html=v${inIndex[0]} <-> sw.js=v${inSw[0]} (DRIFT! sw.js PRECACHE_URLS angleichen + CACHE_VERSION erhöhen)`);
  }
}

// ── B) Modul-Versionen: script.js <-> mobile.html ───────────
// Erfasst modules/<mod>/<mod>.(js|css)?v=NN je Datei.
function collectModuleVersions(text) {
  const re = /modules\/([a-z0-9_]+)\/\1\.(js|css)\?v=(\d+)/gi;
  const map = {}; // mod -> { js:Set, css:Set }
  let m;
  while ((m = re.exec(text)) !== null) {
    const [, mod, ext, ver] = m;
    map[mod] = map[mod] || { js: new Set(), css: new Set() };
    map[mod][ext.toLowerCase()].add(ver);
  }
  return map;
}

const modScript = collectModuleVersions(scriptJs);
const modMobile = collectModuleVersions(mobileHtml);
const allMods = new Set([...Object.keys(modScript), ...Object.keys(modMobile)]);

for (const mod of [...allMods].sort()) {
  const s = modScript[mod];
  const mo = modMobile[mod];

  // js/css innerhalb derselben Datei sollten paarweise gleich sein
  for (const [label, src] of [['script.js', s], ['mobile.html', mo]]) {
    if (!src) continue;
    const js = [...src.js], css = [...src.css];
    if (js[0] && css[0] && js[0] !== css[0]) {
      warn(`B) ${mod} (${label}): js=v${js[0]} <-> css=v${css[0]} (js/css-Version unterschiedlich)`);
    }
  }

  if (!s)  { warn(`B) ${mod}: nur in mobile.html referenziert, fehlt in script.js.`); continue; }
  if (!mo) { /* Modul evtl. nur im Desktop verfügbar – kein Fehler */ continue; }

  const sVer = [...s.js][0];
  const moVer = [...mo.js][0];
  if (sVer && moVer && sVer !== moVer) {
    warn(`B) ${mod}: script.js=v${sVer} <-> mobile.html=v${moVer} (DRIFT! Modul-Versionen angleichen)`);
  }
}

// ── C) Info: aktuelle CACHE_VERSION ─────────────────────────
const cacheVer = (swJs.match(/CACHE_VERSION\s*=\s*['"]([^'"]+)['"]/) || [])[1];

// ── D) App-Version: VERSION <-> manifest.json <-> package.json ──
const appVersion = read('VERSION').trim();
for (const [file, ver] of [
  ['public/manifest.json', JSON.parse(read('public/manifest.json') || '{}').version],
  ['package.json', JSON.parse(read('package.json') || '{}').version],
]) {
  if (ver !== appVersion) warn(`D) ${file}: version=${ver} <-> VERSION=${appVersion} (npm run version:sync)`);
}

// ── Ausgabe ─────────────────────────────────────────────────
const TAG = '[check-versions]';
if (cacheVer) console.log(`${TAG} sw.js CACHE_VERSION = ${cacheVer}`);

if (problems.length === 0) {
  console.log(`${TAG} OK – alle ?v=-Versionen sind konsistent.`);
  process.exit(0);
}

const head = STRICT ? 'FEHLER' : 'WARNUNG';
console.log(`${TAG} ${head}: ${problems.length} Versions-Problem(e) gefunden:`);
for (const p of problems) console.log(`  - ${p}`);
console.log(`${TAG} Bei einer Asset-Änderung: ?v= in index.html UND sw.js erhöhen und sw.js CACHE_VERSION bumpen.`);

process.exit(STRICT ? 1 : 0);
