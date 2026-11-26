// ============================================================
// playwright.config.mjs – Baukalkulation ES (Klicktest-Infrastruktur)
// ============================================================
// AP-20261119-testabdeckung-klicktests, B6. Jeder Worker startet seinen
// eigenen PHP-Built-in-Server mit eigenem Datenverzeichnis (siehe
// tests/e2e/fixtures.mjs) – deshalb kein globaler webServer hier.
//
// 9 Projekte: {chromium,firefox,webkit} x {desktop,tablet,phone}.
// Desktop/Tablet zielen auf index.html (innerWidth > 768), Phone auf
// mobile.html (innerWidth <= 768, siehe public/login.html getDefaultPage()).
// Projekt-Metadaten `ui: 'desktop'|'mobile'` lassen Specs danach filtern.
//
// Aufruf: npx playwright test  (oder scripts/clicktest.ps1 / make clicktest)
// ============================================================

import { defineConfig } from '@playwright/test';

const VIEWPORTS = {
    desktop: { width: 1366, height: 768 },
    tablet:  { width: 834,  height: 1194 },
    phone:   { width: 390,  height: 844 },
};

const BROWSERS = ['chromium', 'firefox', 'webkit'];
const FORM_FACTORS = ['desktop', 'tablet', 'phone'];

function buildProject(browserName, formFactor) {
    const isPhone = formFactor === 'phone';
    const isTablet = formFactor === 'tablet';

    const use = {
        browserName,
        viewport: VIEWPORTS[formFactor],
    };
    if (isPhone || isTablet) use.hasTouch = true;
    // Firefox unterstützt isMobile nicht (Playwright-Einschränkung); die Seite
    // leitet trotzdem über window.innerWidth <= 768 auf mobile.html um.
    if (isPhone && browserName !== 'firefox') use.isMobile = true;

    return {
        name: `${browserName}-${formFactor}`,
        metadata: { ui: isPhone ? 'mobile' : 'desktop' },
        use,
    };
}

export default defineConfig({
    testDir: 'tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    reporter: [
        ['list'],
        ['html', { outputFolder: 'playwright-report', open: 'never' }],
        ['junit', { outputFile: 'test-results/junit.xml' }],
        ['json', { outputFile: 'test-results/results.json' }],
    ],
    use: {
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        serviceWorkers: 'block',
    },
    projects: BROWSERS.flatMap((browser) => FORM_FACTORS.map((formFactor) => buildProject(browser, formFactor))),
});
