#!/usr/bin/env node
// ============================================================
// clicktest-befunde.mjs – Playwright-JSON-Report → test-results/befunde.md
// ============================================================
// AP-20261119-testabdeckung-klicktests, B6. Liest den json-Reporter-Report
// (test-results/results.json) und schreibt je Fehlschlag einen Befund:
// Kategorie (Bug/Anzeige/Anleitung – ohne Taggung Default "Bug", die
// Anleitungs-Verknüpfung folgt erst in B7), Browser × Viewport (aus dem
// Projektnamen/der Projekt-Konfiguration), erwartet/tatsächlich
// (Fehlermeldung) sowie Screenshot-/Trace-Pfad, falls vorhanden.
//
// Aufruf: node scripts/clicktest-befunde.mjs  (wird von scripts/clicktest.ps1
//         bzw. make clicktest nach dem Playwright-Lauf aufgerufen)
// ============================================================

import { readFileSync, existsSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve, relative } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, '..');
const RESULTS_FILE = resolve(ROOT, 'test-results', 'results.json');
const OUT_FILE = resolve(ROOT, 'test-results', 'befunde.md');

function readResults() {
    if (!existsSync(RESULTS_FILE)) {
        throw new Error(`Kein Playwright-Report gefunden: ${RESULTS_FILE} (zuerst Klicktests ausführen).`);
    }
    return JSON.parse(readFileSync(RESULTS_FILE, 'utf8'));
}

/** Projektname -> { ui, browser, viewport } aus der Report-Konfiguration. */
function projectInfo(report) {
    const map = new Map();
    const projects = report.config?.filteredProjects ?? report.config?.projects ?? [];
    for (const project of projects) {
        map.set(project.name, {
            ui: project.metadata?.ui ?? 'unbekannt',
            browser: project.use?.browserName ?? project.name,
            viewport: project.use?.viewport ?? null,
        });
    }
    return map;
}

function* walkSpecs(suite, path = []) {
    const nextPath = suite.title ? [...path, suite.title] : path;
    for (const spec of suite.specs ?? []) {
        yield { spec, path: nextPath };
    }
    for (const child of suite.suites ?? []) {
        yield* walkSpecs(child, nextPath);
    }
}

function relPath(p) {
    return p ? relative(ROOT, p).split('\\').join('/') : null;
}

/** B6: noch keine Taggung nach Anleitungsabgleich (folgt in B7) - Default "Bug". */
function kategorie() {
    return 'Bug';
}

function viewportLabel(viewport) {
    return viewport ? `${viewport.width}x${viewport.height}` : 'unbekannt';
}

function sammleBefunde(report) {
    const projects = projectInfo(report);
    const befunde = [];
    for (const suite of report.suites ?? []) {
        for (const { spec, path } of walkSpecs(suite)) {
            for (const test of spec.tests ?? []) {
                if (test.status === 'skipped' || test.status === 'expected') continue;

                const lastResult = test.results?.at(-1) ?? {};
                const info = projects.get(test.projectName) ?? {};
                const fehlermeldung = (lastResult.errors ?? [])
                    .map((e) => e.message ?? e.value ?? String(e))
                    .join('\n')
                    .trim() || '(keine Fehlermeldung im Report)';
                const attachments = lastResult.attachments ?? [];

                befunde.push({
                    titel: [...path, spec.title].join(' › '),
                    datei: spec.file,
                    status: test.status,
                    browser: info.browser ?? test.projectName,
                    viewport: viewportLabel(info.viewport),
                    ui: info.ui ?? 'unbekannt',
                    fehlermeldung,
                    screenshot: relPath(attachments.find((a) => a.name === 'screenshot')?.path),
                    trace: relPath(attachments.find((a) => a.name === 'trace')?.path),
                });
            }
        }
    }
    return befunde;
}

function schreibeBericht(befunde, report) {
    const lines = [];
    lines.push('# Klicktest-Befunde');
    lines.push('');
    lines.push(`Erzeugt: ${new Date().toISOString()}`);
    lines.push(`Quelle: test-results/results.json (Playwright ${report.config?.version ?? '?'})`);
    lines.push('');

    if (befunde.length === 0) {
        lines.push('Keine Befunde – alle Klicktests waren erfolgreich.');
    } else {
        lines.push(`${befunde.length} Fehlschlag/Fehlschläge:`);
        lines.push('');
        befunde.forEach((b, i) => {
            lines.push(`## ${i + 1}. ${b.titel}`);
            lines.push('');
            lines.push(`- Datei: \`${b.datei}\``);
            lines.push(`- Kategorie: ${kategorie(b)}`);
            lines.push(`- Browser × Viewport: ${b.browser} × ${b.viewport} (${b.ui})`);
            lines.push(`- Status: ${b.status === 'unexpected' ? 'fehlgeschlagen' : b.status}`);
            lines.push('- Erwartet: Test besteht (grün)');
            lines.push('- Tatsächlich:');
            lines.push('  ```');
            for (const zeile of b.fehlermeldung.split('\n')) lines.push(`  ${zeile}`);
            lines.push('  ```');
            if (b.screenshot) lines.push(`- Screenshot: \`${b.screenshot}\``);
            if (b.trace) lines.push(`- Trace: \`${b.trace}\` (ansehen: \`npx playwright show-trace ${b.trace}\`)`);
            lines.push('');
        });
    }

    writeFileSync(OUT_FILE, lines.join('\n') + '\n', 'utf8');
    return befunde.length;
}

const report = readResults();
const befunde = sammleBefunde(report);
const anzahl = schreibeBericht(befunde, report);
console.log(`[clicktest-befunde] ${anzahl} Befund(e) -> ${relPath(OUT_FILE)}`);
