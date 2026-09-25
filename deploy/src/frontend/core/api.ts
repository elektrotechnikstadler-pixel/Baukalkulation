// ============================================================
// api.ts – Fetch-Wrapper für api.php (TypeScript)
// ============================================================
// Typisierter HTTP-Client. Generische Rückgabe-Typen ermöglichen
// typgeprüfte API-Antworten in Komponenten.
//
// Verwendung:
//   import { apiPost, apiGet } from '@core/api.ts';
//
//   const data = await apiGet<CheckResponse>('check');
//   const res  = await apiPost<{ ok: boolean }>('save_material', { id, name });
// ============================================================

/** Login-Redirect-Ziel für abgelaufene Sessions */
const LOGIN_URL = 'login.html?from=index.html';

/**
 * POST-Anfrage an api.php mit JSON-Body.
 *
 * @param action  api.php-Aktionsname (z.B. 'save', 'load')
 * @param body    Nutzlast – wird mit `{ action }` zusammengeführt
 * @returns       Geparste JSON-Antwort, typisiert als T
 * @throws        Bei HTTP-Fehler oder abgelaufener Session (→ Redirect)
 */
export async function apiPost<T = unknown>(
    action: string,
    body: Record<string, unknown> = {},
): Promise<T> {
    const res = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
        method:      'POST',
        headers:     { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body:        JSON.stringify(body),
    });

    if (res.status === 401) {
        window.location.replace(LOGIN_URL);
        throw new Error('Sitzung abgelaufen – Weiterleitung zum Login.');
    }
    if (!res.ok) {
        throw new Error(`API-Fehler: HTTP ${res.status} bei action="${action}"`);
    }
    return res.json() as Promise<T>;
}

/**
 * GET-Anfrage an api.php (Query-Parameter).
 *
 * @param action  api.php-Aktionsname
 * @param params  Zusätzliche Query-Parameter (werden als Strings serialisiert)
 * @returns       Geparste JSON-Antwort, typisiert als T
 * @throws        Bei HTTP-Fehler oder abgelaufener Session (→ Redirect)
 */
export async function apiGet<T = unknown>(
    action: string,
    params: Record<string, string | number | boolean> = {},
): Promise<T> {
    const url = new URL('api.php', location.href);
    url.searchParams.set('action', action);
    for (const [k, v] of Object.entries(params)) {
        url.searchParams.set(k, String(v));
    }

    const res = await fetch(url, { credentials: 'same-origin' });

    if (res.status === 401) {
        window.location.replace(LOGIN_URL);
        throw new Error('Sitzung abgelaufen – Weiterleitung zum Login.');
    }
    if (!res.ok) {
        throw new Error(`API-Fehler: HTTP ${res.status} bei action="${action}"`);
    }
    return res.json() as Promise<T>;
}
