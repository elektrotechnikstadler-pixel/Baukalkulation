// ============================================================
// fixtures.mjs – Klicktest-Infrastruktur (AP-20261119-testabdeckung-klicktests, B6)
// ============================================================
// Jeder Playwright-Worker bekommt einen eigenen PHP-Built-in-Server
// (freier Port auf 127.0.0.1, eigenes BK_DATA_DIR in einem Temp-Ordner):
// der Built-in-Server ist unter Windows einprozessig
// (PHP_CLI_SERVER_WORKERS wirkt nur unter Linux), daher braucht jeder
// Worker eine eigene, isolierte Datenbank statt sich einen Server zu teilen.
//
// Fixtures:
//   bkApp   (worker-scope) – Server + Admin-Ersteinrichtung.
//   baseURL (test-scope)   – überschreibt die eingebaute Playwright-Option
//                            dynamisch mit der Worker-URL.
//   seed    (test-scope)   – legt per API einen Monteur, einen Beispielkunden
//                            und ein Beispielprojekt mit eindeutigem Namen an.
//   page    (test-scope)   – Auto-Prüfung je Seite: kein pageerror, kein
//                            console.error, keine API-Antwort >= 500, kein
//                            horizontaler Überlauf; blockiert alle externen
//                            (nicht-localhost) Netzwerk-Anfragen.
// ============================================================

import { test as base, expect } from '@playwright/test';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import fs from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import crypto from 'node:crypto';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const APP_ROOT = path.resolve(__dirname, '..', '..');

const ADMIN_USER = 'admin';
const ADMIN_PASS = 'Admin-Test-123';
const MONTEUR_START_PASS = 'Start-Passwort-1';
const MONTEUR_PASS = 'Monteur-Test-123';

function freePort() {
    return new Promise((resolve, reject) => {
        const srv = createServer();
        srv.unref();
        srv.on('error', reject);
        srv.listen(0, '127.0.0.1', () => {
            const { port } = srv.address();
            srv.close(() => resolve(port));
        });
    });
}

async function waitUntilReachable(baseUrl, deadlineMs = 15000) {
    const deadline = Date.now() + deadlineMs;
    let lastError = null;
    while (Date.now() < deadline) {
        try {
            await fetch(`${baseUrl}/api.php?action=check`);
            return;
        } catch (err) {
            lastError = err;
            await new Promise((r) => setTimeout(r, 100));
        }
    }
    throw new Error(`Test-Server unter ${baseUrl} nicht erreichbar: ${lastError?.message ?? ''}`);
}

/** Minimaler Cookie-Jar für die Seed-Aufrufe (fetch kennt keine Sessions). */
class ApiSeeder {
    constructor(baseUrl) {
        this.baseUrl = baseUrl;
        this.cookie = '';
    }

    async post(action, body) {
        const res = await fetch(`${this.baseUrl}/api.php?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', ...(this.cookie ? { Cookie: this.cookie } : {}) },
            body: JSON.stringify(body ?? {}),
        });
        const setCookie = res.headers.get('set-cookie');
        if (setCookie) this.cookie = setCookie.split(';')[0];
        const json = await res.json().catch(() => ({}));
        if (!res.ok || json?.ok === false || json?.error) {
            throw new Error(`Seed-Aufruf '${action}' fehlgeschlagen (${res.status}): ${JSON.stringify(json)}`);
        }
        return json;
    }
}

async function startPhpServer(workerIndex) {
    const php = process.env.BK_PHP || 'php';
    const workDir = fs.mkdtempSync(path.join(tmpdir(), `bk-e2e-w${workerIndex}-`));
    const dataDir = path.join(workDir, 'data');
    const sessionDir = path.join(workDir, 'sessions');
    fs.mkdirSync(dataDir, { recursive: true });
    fs.mkdirSync(sessionDir, { recursive: true });

    // Nie gegen das echte data/-Verzeichnis des Repos testen (vgl. E-064).
    const repoDataDir = path.resolve(APP_ROOT, 'data');
    if (path.resolve(dataDir) === repoDataDir) {
        throw new Error('BK_DATA_DIR zeigt auf das Produktiv-Datenverzeichnis des Repos - Abbruch.');
    }

    const port = await freePort();
    const baseUrl = `http://127.0.0.1:${port}`;
    const errorLog = path.join(workDir, 'php_errors.log');
    const serverLogFd = fs.openSync(path.join(workDir, 'server.log'), 'a');

    const env = { ...process.env, BK_DATA_DIR: dataDir };
    delete env.BK_UPDATE_REPO;

    // Gleicher Name wie tests/Support/TestServer.php: z.B. "-d extension=pdo_pgsql"
    // für scripts/clicktest.ps1 -Db pgsql (Windows-PHP lädt pdo_pgsql nicht per Default).
    const extraArgs = (process.env.BK_TEST_PHP_ARGS ?? '').trim();
    const extraArgList = extraArgs === '' ? [] : extraArgs.split(/\s+/);

    const child = spawn(
        php,
        [
            ...extraArgList,
            '-d', `session.save_path=${sessionDir}`,
            '-d', 'display_errors=0',
            '-d', 'log_errors=1',
            '-d', `error_log=${errorLog}`,
            '-d', 'upload_max_filesize=50M',
            '-d', 'post_max_size=50M',
            '-S', `127.0.0.1:${port}`,
            '-t', path.join(APP_ROOT, 'public'),
        ],
        { cwd: APP_ROOT, env, stdio: ['ignore', serverLogFd, serverLogFd] },
    );

    await waitUntilReachable(baseUrl);

    return {
        baseUrl,
        dataDir,
        async stop() {
            child.kill();
            try { fs.closeSync(serverLogFd); } catch { /* bereits geschlossen */ }
            fs.rmSync(workDir, { recursive: true, force: true });
        },
    };
}

/** Füllt die Anmeldemaske aus und wartet auf die Umleitung (index.html/mobile.html). */
export async function loginUi(page, credentials) {
    await page.goto('/login.html');
    // #setupForm enthält ein gleich benanntes Feld (Ersteinrichtung) - auf das Login-Formular eingrenzen.
    const form = page.locator('#loginForm');
    await form.getByLabel('Benutzername').fill(credentials.username);
    await form.getByLabel('Passwort').fill(credentials.password);
    await form.getByRole('button', { name: 'Anmelden' }).click();
    await expect(page).toHaveURL(/(index|mobile)\.html/);
}

export const test = base.extend({
    bkApp: [
        async ({}, use, workerInfo) => {
            const server = await startPhpServer(workerInfo.workerIndex);
            const seeder = new ApiSeeder(server.baseUrl);
            await seeder.post('setup', { username: ADMIN_USER, password: ADMIN_PASS });
            await use({ ...server, admin: { username: ADMIN_USER, password: ADMIN_PASS } });
            await server.stop();
        },
        { scope: 'worker' },
    ],

    // Überschreibt die eingebaute Playwright-Option mit der je Worker
    // dynamisch vergebenen Server-URL (Begründung: je Worker ein Server/Port).
    baseURL: async ({ bkApp }, use) => {
        await use(bkApp.baseUrl);
    },

    seed: async ({ bkApp }, use) => {
        const suffix = `${Date.now().toString(36)}-${crypto.randomBytes(3).toString('hex')}`;

        const admin = new ApiSeeder(bkApp.baseUrl);
        await admin.post('login', bkApp.admin);

        const monteurUser = `monteur-${suffix}`;
        await admin.post('add_user', { username: monteurUser, password: MONTEUR_START_PASS, role: 'normal' });
        const monteurClient = new ApiSeeder(bkApp.baseUrl);
        await monteurClient.post('login', { username: monteurUser, password: MONTEUR_START_PASS });
        await monteurClient.post('change_password', { old: MONTEUR_START_PASS, new: MONTEUR_PASS });

        const kundeName = `E2E Kunde ${suffix}`;
        const kundeResp = await admin.post('save_kunde', { firma: kundeName });

        const projektName = `E2E Projekt ${suffix}`;
        await admin.post('save', { data: { baustellen: [{ id: 1, name: projektName, kundeId: kundeResp.kunde.id }] } });

        await use({
            admin: bkApp.admin,
            monteur: { username: monteurUser, password: MONTEUR_PASS },
            kunde: { id: kundeResp.kunde.id, firma: kundeName },
            projekt: { id: 1, name: projektName },
        });
    },

    page: async ({ page }, use) => {
        const violations = [];
        page.on('pageerror', (err) => violations.push(`pageerror: ${err.message}`));
        page.on('console', (msg) => {
            if (msg.type() !== 'error') return;
            // "Failed to load resource" ist eine vom Browser erzeugte Netzwerk-Diagnose
            // (kein echtes console.error() der App) – wird bereits über die
            // Status->=500-Prüfung unten abgedeckt, sonst doppelter Befund für jeden
            // erwarteten 404 (z.B. whatsapp_api.php ohne konfigurierte Bridge).
            if (msg.text().startsWith('Failed to load resource')) return;
            violations.push(`console.error: ${msg.text()}`);
        });
        page.on('response', (response) => {
            if (response.status() >= 500) violations.push(`Serverfehler ${response.status()}: ${response.url()}`);
        });
        page.on('load', () => {
            page
                .evaluate(() => document.documentElement.scrollWidth > window.innerWidth)
                .then((overflow) => {
                    if (overflow) violations.push(`horizontaler Überlauf auf ${page.url()}`);
                })
                .catch(() => { /* Seite bereits weiternavigiert - ignorieren */ });
        });

        // Alle externen (nicht-localhost) Netzwerk-Anfragen blockieren.
        await page.route('**/*', (route) => {
            const url = route.request().url();
            if (!/^https?:\/\//.test(url)) return route.continue();
            const { hostname } = new URL(url);
            if (hostname === '127.0.0.1' || hostname === 'localhost') return route.continue();
            return route.abort();
        });

        await use(page);

        expect(violations, violations.join('\n')).toEqual([]);
    },
});

export { expect };
