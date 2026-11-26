// ============================================================
// anmeldung.spec.mjs – Login/Logout + Mobil-Umleitung (B6 Smoke-Spec)
// ============================================================
// Prüft, dass der Admin sich anmelden kann, je nach Viewport auf
// index.html (ui: 'desktop') bzw. mobile.html (ui: 'mobile',
// window.innerWidth <= 768, siehe public/login.html getDefaultPage())
// landet, und sich wieder abmelden kann.
// ============================================================

import { test, expect } from './fixtures.mjs';

test.describe('Anmeldung', () => {
    test('Login führt je nach Viewport auf die passende Oberfläche, Logout zurück zur Anmeldung', async ({ page, seed }, testInfo) => {
        const ui = testInfo.project.metadata.ui;

        await page.goto('/login.html');
        // #setupForm enthält ein gleich benanntes Feld (Ersteinrichtung) - auf das Login-Formular eingrenzen.
        const form = page.locator('#loginForm');
        await form.getByLabel('Benutzername').fill(seed.admin.username);
        await form.getByLabel('Passwort').fill(seed.admin.password);
        await form.getByRole('button', { name: 'Anmelden' }).click();

        if (ui === 'mobile') {
            await expect(page).toHaveURL(/mobile\.html/);
            await page.getByRole('button', { name: 'Abmelden' }).click();
        } else {
            await expect(page).toHaveURL(/index\.html/);
            // exact:true, da 'Verwaltung' sonst auch im Dropdown 'Zeitverwaltung' matcht.
            await page.getByRole('button', { name: 'Verwaltung ▾', exact: true }).click();
            await page.getByRole('button', { name: 'Abmelden' }).click();
        }

        await expect(page).toHaveURL(/login\.html/);
    });
});
