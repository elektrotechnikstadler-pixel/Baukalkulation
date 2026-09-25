#!/usr/bin/env node
// Überträgt die Version aus der Datei VERSION (einzige Quelle) nach
// public/manifest.json und package.json.   Aufruf: npm run version:sync
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const version = readFileSync(resolve(ROOT, 'VERSION'), 'utf8').trim();
if (!/^\d+\.\d+\.\d+$/.test(version)) {
  console.error(`[sync-version] Ungültige Version in VERSION: "${version}"`);
  process.exit(1);
}

for (const rel of ['public/manifest.json', 'package.json']) {
  const path = resolve(ROOT, rel);
  const text = readFileSync(path, 'utf8');
  const next = text.replace(/("version"\s*:\s*")[^"]*(")/, `$1${version}$2`);
  if (next !== text) {
    writeFileSync(path, next);
    console.log(`[sync-version] ${rel} -> ${version}`);
  }
}
