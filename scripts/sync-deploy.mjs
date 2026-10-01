#!/usr/bin/env node
// Spiegelt alle für Betrieb/Docker-Build nötigen Dateien nach deploy/ (Upload-Ordner).
// Quelle: versionierte + neue, nicht ignorierte Dateien; Dateien in deploy/, die es
// in der Quelle nicht mehr gibt, werden gelöscht. Aufruf: npm run deploy:sync

import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, readFileSync, rmdirSync, statSync, unlinkSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const DEST = join(ROOT, 'deploy');

// Nur Entwicklung/CI – auf dem Server nicht nötig.
const EXCLUDE = [
    /^deploy\//,
    /^tests\//,
    /^\.github\//,
    /^docs\/(?!installation\.md$)/,
    /^src\/frontend\/__tests__\//,
    /^(\.editorconfig|\.gitattributes|\.gitignore|\.gitleaksignore|\.php-cs-fixer\.dist\.php|\.pre-commit-config\.yaml)$/,
    /^(cliff\.toml|mkdocs\.yml|renovate\.json|Makefile|phpunit\.xml\.dist|phpstan[^/]*\.neon[^/]*|AGENTS\.md)$/,
    /^(Änderungshinweise|Nächste Änderungen)\.txt$/,
];

const listed = execFileSync('git', ['ls-files', '-z', '--cached', '--others', '--exclude-standard'], { cwd: ROOT })
    .toString('utf8').split('\0').filter(Boolean);
const files = [...new Set(listed)]
    .filter((f) => !EXCLUDE.some((re) => re.test(f)))
    .filter((f) => existsSync(join(ROOT, f)) && statSync(join(ROOT, f)).isFile());

let copied = 0;
for (const f of files) {
    const dst = join(DEST, f);
    let content = readFileSync(join(ROOT, f));
    // Wie .gitattributes (eol=lf): sonst bricht z. B. docker-entrypoint.sh mit CRLF im Container.
    if (!content.includes(0)) content = Buffer.from(content.toString('latin1').replace(/\r\n/g, '\n'), 'latin1');
    if (existsSync(dst) && readFileSync(dst).equals(content)) continue;
    mkdirSync(dirname(dst), { recursive: true });
    writeFileSync(dst, content);
    copied++;
}

const wanted = new Set(files.map((f) => join(DEST, f)));
let removed = 0;
function prune(dir) {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const p = join(dir, entry.name);
        if (entry.isDirectory()) {
            prune(p);
            if (readdirSync(p).length === 0) rmdirSync(p);
        } else if (!wanted.has(p)) {
            unlinkSync(p);
            removed++;
        }
    }
}
if (existsSync(DEST)) prune(DEST);

console.log(`[deploy] ${files.length} Dateien in ${relative(ROOT, DEST)}${sep}: ${copied} aktualisiert, ${removed} entfernt.`);
