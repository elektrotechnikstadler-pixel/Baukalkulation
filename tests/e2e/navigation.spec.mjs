// ============================================================
// navigation.spec.mjs – Hauptbereiche öffnen ohne Fehler (B6 Smoke-Spec)
// ============================================================
// Stellt sicher, dass sich die zentralen Navigationspunkte je Oberfläche
// öffnen lassen, ohne dass die Auto-Prüfung aus fixtures.mjs anschlägt
// (kein JS-Fehler, kein console.error, keine API-Antwort >= 500, kein
// horizontaler Überlauf). Die vollständige, anleitungsbasierte Abdeckung
// jedes Bereichs folgt in B7 (tests/e2e/anleitung.spec.mjs).
// ============================================================

import { test, expect } from './fixtures.mjs';
import { loginUi } from './fixtures.mjs';

/**
 * Tablet-Breite (<=1024px) klappt die Sidebar nach dem Laden automatisch ein
 * (checkSidebarResponsive() in script.js) – vor jedem Sidebar-Klick sicherstellen,
 * dass sie wieder offen ist, sonst ist der Button außerhalb des Viewports.
 */
async function ensureSidebarOpen(page) {
    const eingeklappt = await page.evaluate(() => document.body.classList.contains('sidebar-collapsed'));
    if (eingeklappt) {
        await page.getByRole('button', { name: 'Seitenleiste ein-/ausblenden' }).click();
    }
}

test.describe('Navigation', () => {
    test('Hauptbereiche öffnen ohne Fehler', async ({ page, seed }, testInfo) => {
        const ui = testInfo.project.metadata.ui;
        await loginUi(page, seed.admin);

        if (ui === 'mobile') {
            await expect(page).toHaveURL(/mobile\.html/);
            await page.getByRole('button', { name: 'Stunden', exact: false }).first().click();
            await page.getByRole('button', { name: '‹ Zurück' }).click();
            await page.getByRole('button', { name: 'Schnell', exact: false }).first().click();
        } else {
            await expect(page).toHaveURL(/index\.html/);
            for (const name of ['Gesamtübersicht', 'Kundenstamm', 'Material-Katalog', 'Stunden-Katalog']) {
                await ensureSidebarOpen(page);
                await page.getByRole('button', { name }).click();
            }
        }
    });
});
