#!/usr/bin/env node
// Tokenfreier Prüflauf (AP-20261126-lexoffice-schnittstelle B0, E-100).
// Ein Aufruf für Windows und Linux: Logs nach tests/tmp/pruefen/, Ausgabe ≤ 15 Zeilen, Exit-Code 0 = grün.
//
//   node scripts/pruefen.mjs [--php <pfad>] [--filter <muster>] [--suite unit|api]
//                            [--pgsql] [--stan] [--versionen] [--ohne-js] [--ohne-phpunit] [--voll]
//
// --voll = PHPUnit (SQLite + PostgreSQL) + test:js + PHPStan + Versionsprüfung.
// PHP: --php, sonst Umgebungsvariable PHP, sonst "php".
import { spawn, spawnSync } from 'node:child_process';
import { createWriteStream, mkdirSync, readdirSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:net';
import { join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('..', import.meta.url));
const LOG_DIR = join(ROOT, 'tests', 'tmp', 'pruefen');
const PG = { name: 'bk-pg-test', port: 55432, image: 'postgres:17-alpine' };
const MAX_DETAILS = 4;

export function parseArgs(argv) {
  const opt = { php: process.env.PHP || 'php', filter: '', suite: '', pgsql: false, stan: false,
    versionen: false, js: true, phpunit: true };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--php') opt.php = argv[++i] ?? opt.php;
    else if (a === '--filter') opt.filter = argv[++i] ?? '';
    else if (a === '--suite') opt.suite = argv[++i] ?? '';
    else if (a === '--pgsql') opt.pgsql = true;
    else if (a === '--stan') opt.stan = true;
    else if (a === '--versionen') opt.versionen = true;
    else if (a === '--ohne-js') opt.js = false;
    else if (a === '--ohne-phpunit') opt.phpunit = false;
    else if (a === '--voll') Object.assign(opt, { pgsql: true, stan: true, versionen: true, js: true, phpunit: true });
    else throw new Error(`Unbekannte Option: ${a}`);
  }
  return opt;
}

// ── Auswertung der Logs (rein, testbar) ───────────────────────────────────
export function auswertenPhpunit(log, code = 0) {
  const ok = log.match(/^OK \((\d+) tests?/m);
  const sum = log.match(/^Tests: (\d+),.*$/m);
  const details = [...log.matchAll(/^\d+\) (\S+)/gm)].map(m => m[1]);
  if (ok && code === 0) return { gruen: true, kurz: `${ok[1]} Tests`, details: [] };
  if (sum) return { gruen: code === 0, kurz: sum[0].replace(/^Tests: /, 'Tests ').slice(0, 90), details: details.length ? details : (code === 0 ? [] : letzteZeilen(log)) };
  return { gruen: false, kurz: ok ? `Exit-Code ${code}` : 'keine PHPUnit-Zusammenfassung', details: letzteZeilen(log) };
}

export function auswertenNodeTest(log, code = 0) {
  const pass = log.match(/^# pass (\d+)/m);
  const fail = log.match(/^# fail (\d+)/m);
  const details = [...log.matchAll(/^\s*not ok \d+ - (.+)$/gm)].map(m => m[1].trim());
  if (!pass || !fail) return { gruen: false, kurz: 'keine node:test-Zusammenfassung', details: letzteZeilen(log) };
  return { gruen: fail[1] === '0' && code === 0, kurz: `${pass[1]} bestanden, ${fail[1]} fehlgeschlagen`, details };
}

export function auswertenPhpstan(log, code) {
  const fehler = log.split(/\r?\n/).filter(z => /^.+:\d+:.+/.test(z));
  if (code === 0) return { gruen: true, kurz: 'keine Fehler', details: [] };
  return { gruen: false, kurz: `${fehler.length} Fehler`, details: fehler.length ? fehler : letzteZeilen(log) };
}

export function auswertenAllgemein(log, code) {
  return { gruen: code === 0, kurz: code === 0 ? 'ok' : `Exit-Code ${code}`, details: code === 0 ? [] : letzteZeilen(log) };
}

function letzteZeilen(log) {
  return log.split(/\r?\n/).map(z => z.trim()).filter(Boolean).slice(-2);
}

// ── Ausführung ────────────────────────────────────────────────────────────
function lauf(name, cmd, args, env = {}) {
  const logPfad = join(LOG_DIR, `${name}.log`);
  const start = Date.now();
  return new Promise(resolve => {
    const out = createWriteStream(logPfad);
    let text = '';
    const p = spawn(cmd, args, { cwd: ROOT, env: { ...process.env, ...env }, shell: false });
    const sammeln = d => { text += d; out.write(d); };
    p.stdout.on('data', sammeln);
    p.stderr.on('data', sammeln);
    p.on('error', e => { text += `\n${e.message}\n`; });
    p.on('close', code => {
      out.end();
      resolve({ name, code: code ?? 1, text, logPfad, sek: Math.round((Date.now() - start) / 1000) });
    });
  });
}

function portFrei(port) {
  return new Promise(resolve => {
    const s = createServer().once('error', () => resolve(false))
      .once('listening', () => s.close(() => resolve(true)))
      .listen(port, '127.0.0.1');
  });
}

function phpunitArgs(opt) {
  const a = ['vendor/bin/phpunit', '--no-progress', '--colors=never'];
  if (opt.suite) a.push('--testsuite', opt.suite);
  if (opt.filter) a.push('--filter', opt.filter);
  return a;
}

async function phpunitPgsql(opt) {
  // Nie gegen einen fremden Server testen: die Tests leeren das Schema (E-064).
  if (!(await portFrei(PG.port))) {
    return { name: 'phpunit-pgsql', code: 1, text: `Port ${PG.port} ist belegt – Abbruch.`, logPfad: '', sek: 0 };
  }
  spawnSync('docker', ['rm', '-f', PG.name], { stdio: 'ignore' });
  const run = spawnSync('docker', ['run', '-d', '--rm', '--name', PG.name, '-p', `127.0.0.1:${PG.port}:5432`,
    '-e', 'POSTGRES_USER=bk', '-e', 'POSTGRES_PASSWORD=bk-test-pw', '-e', 'POSTGRES_DB=bk_test', PG.image]);
  if (run.status !== 0) {
    return { name: 'phpunit-pgsql', code: 1, text: 'PostgreSQL-Container nicht gestartet (Docker Desktop läuft?)', logPfad: '', sek: 0 };
  }
  try {
    let bereit = false;
    for (let i = 0; i < 60 && !bereit; i++) {
      bereit = spawnSync('docker', ['exec', PG.name, 'pg_isready', '-U', 'bk', '-d', 'bk_test']).status === 0;
      if (!bereit) await new Promise(r => setTimeout(r, 1000));
    }
    if (!bereit) return { name: 'phpunit-pgsql', code: 1, text: 'PostgreSQL nicht rechtzeitig bereit.', logPfad: '', sek: 0 };
    const geladen = spawnSync(opt.php, ['-r', "exit(extension_loaded('pdo_pgsql')?0:1);"]).status === 0;
    const ext = geladen ? [] : ['-d', 'extension=pdo_pgsql'];
    return await lauf('phpunit-pgsql', opt.php, [...ext, ...phpunitArgs(opt)], {
      BK_DB_DRIVER: 'pgsql', BK_DB_HOST: '127.0.0.1', BK_DB_PORT: String(PG.port), BK_DB_NAME: 'bk_test',
      BK_DB_USER: 'bk', BK_DB_PASSWORD: 'bk-test-pw', BK_TEST_PHP_ARGS: ext.join(' '),
    });
  } finally {
    spawnSync('docker', ['rm', '-f', PG.name], { stdio: 'ignore' });
  }
}

function zeile(r, auswertung) {
  const status = auswertung.gruen ? 'GRÜN' : 'ROT ';
  const log = auswertung.gruen || !r.logPfad ? '' : `  → ${relative(ROOT, r.logPfad).replace(/\\/g, '/')}`;
  return [`${status} ${r.name.padEnd(14)} ${auswertung.kurz} (${r.sek}s)${log}`,
    ...auswertung.details.slice(0, MAX_DETAILS).map(d => `     ${d.slice(0, 150)}`)];
}

async function main() {
  const opt = parseArgs(process.argv.slice(2));
  mkdirSync(LOG_DIR, { recursive: true });
  writeFileSync(join(LOG_DIR, '.gitignore'), '*\n');

  const ergebnisse = [];
  if (opt.phpunit) {
    const r = await lauf('phpunit-sqlite', opt.php, phpunitArgs(opt));
    ergebnisse.push([r, auswertenPhpunit(r.text, r.code)]);
    if (opt.pgsql) {
      const p = await phpunitPgsql(opt);
      ergebnisse.push([p, p.logPfad ? auswertenPhpunit(p.text, p.code) : auswertenAllgemein(p.text, 1)]);
    }
  }
  if (opt.js) {
    const dateien = readdirSync(join(ROOT, 'tests', 'js')).filter(f => f.endsWith('.test.mjs')).map(f => `tests/js/${f}`);
    const r = await lauf('test-js', process.execPath, ['--test', '--test-reporter=tap', ...dateien]);
    ergebnisse.push([r, auswertenNodeTest(r.text, r.code)]);
  }
  if (opt.stan) {
    const r = await lauf('phpstan', opt.php, ['vendor/bin/phpstan', 'analyse', '--memory-limit=2G', '--no-progress', '--error-format=raw']);
    ergebnisse.push([r, auswertenPhpstan(r.text, r.code)]);
  }
  if (opt.versionen) {
    const r = await lauf('versionen', process.execPath, ['scripts/check-versions.mjs', '--strict']);
    ergebnisse.push([r, auswertenAllgemein(r.text, r.code)]);
  }

  const zeilen = ergebnisse.flatMap(([r, a]) => zeile(r, a));
  const gruen = ergebnisse.every(([, a]) => a.gruen);
  // Ergebniszeile immer als letzte Zeile, auch wenn Details gekürzt werden.
  console.log([...zeilen.slice(0, 14), gruen ? 'Ergebnis: GRÜN' : 'Ergebnis: ROT'].join('\n'));
  process.exitCode = gruen ? 0 : 1;
}

const gleichePfade = (a, b) => process.platform === 'win32' ? resolve(a).toLowerCase() === resolve(b).toLowerCase() : resolve(a) === resolve(b);
if (process.argv[1] && gleichePfade(fileURLToPath(import.meta.url), process.argv[1])) {
  main().catch(e => { console.error(`ROT  pruefen: ${e.message}`); process.exitCode = 2; });
}
