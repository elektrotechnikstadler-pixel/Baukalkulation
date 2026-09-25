// ============================================================
// api.js – Fetch-Wrapper für api.php (Baukalkulation ES)
// ============================================================
// Zentraler HTTP-Client. Alle Aufrufe an api.php laufen hier
// durch, damit Fehlerbehandlung und Session-Abläufe einheitlich
// sind.
//
// Verwendung in Phase-3+-Modulen:
//   import { apiPost, apiGet } from '@core/api.js';
//   const data = await apiGet('check');
//   const res  = await apiPost('save_material', { id, name, ek });
// ============================================================

/**
 * POST-Anfrage an api.php mit JSON-Body.
 *
 * @param {string}              action  - api.php-Aktionsname
 * @param {Record<string,any>}  body    - Nutzlast (wird mit action zusammengeführt)
 * @returns {Promise<any>}              - Geparste JSON-Antwort
 * @throws {Error}  Bei HTTP-Fehler oder nicht-authentifizierter Session (→ Redirect)
 */
export async function apiPost(action, body = {}) {
    const res = await fetch('api.php', {
        method:      'POST',
        headers:     { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body:        JSON.stringify({ action, ...body }),
    });

    if (res.status === 401) {
        window.location.replace('login.html?from=index.html');
        throw new Error('Sitzung abgelaufen – Weiterleitung zum Login.');
    }
    if (!res.ok) {
        throw new Error(`API-Fehler: HTTP ${res.status} bei action="${action}"`);
    }
    return res.json();
}

/**
 * GET-Anfrage an api.php (Query-Parameter).
 *
 * @param {string}              action  - api.php-Aktionsname
 * @param {Record<string,any>}  params  - Zusätzliche Query-Parameter (optional)
 * @returns {Promise<any>}              - Geparste JSON-Antwort
 * @throws {Error}  Bei HTTP-Fehler oder nicht-authentifizierter Session (→ Redirect)
 */
export async function apiGet(action, params = {}) {
    const url = new URL('api.php', location.href);
    url.searchParams.set('action', action);
    for (const [k, v] of Object.entries(params)) {
        url.searchParams.set(k, String(v));
    }

    const res = await fetch(url, { credentials: 'same-origin' });

    if (res.status === 401) {
        window.location.replace('login.html?from=index.html');
        throw new Error('Sitzung abgelaufen – Weiterleitung zum Login.');
    }
    if (!res.ok) {
        throw new Error(`API-Fehler: HTTP ${res.status} bei action="${action}"`);
    }
    return res.json();
}
