// src/frontend/__tests__/setup.ts
// Globales Vitest-Setup: läuft vor jeder Test-Datei.
// Hier können globale Mocks, Vue-Plugin-Registrierungen etc. stehen.

import { config } from '@vue/test-utils';

// Globale Komponenten / Plugins können hier registriert werden:
// config.global.plugins = [...];

// Fehlende Browser-APIs in jsdom ggf. polyfill-en:
// window.matchMedia = window.matchMedia || function () { ... };
