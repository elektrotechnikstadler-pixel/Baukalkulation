// ============================================================
// vite.config.js – Baukalkulation ES (Phase 1 – Grundgerüst)
// ============================================================
// Phase 1: Konfiguration ohne aktive Entry-Points.
//          Nur Alias-Definitionen und Output-Struktur.
// Phase 2: src/frontend/core/init.js als core-Entry eintragen.
// Phase 3+: Je Modul einen separaten Entry für Code-Splitting.
//
// Build:   npm run build  →  dist/
// Dev-HMR: npm run dev    →  http://localhost:5173
// ============================================================

import { defineConfig } from 'vite';
import { resolve }      from 'path';

export default defineConfig({
    root: '.',
    base: '/',
    // public/ ist der Webroot der App, kein Vite-Asset-Ordner – sonst kopiert Vite ihn nach dist/.
    publicDir: false,

    build: {
        outDir:      'public/dist',
        emptyOutDir: true,
        // manifest.json erzeugen → PHP kann Hashes der Assets lesen
        manifest: true,
        rollupOptions: {
            input: {
                // ── Phase 2: Haupt-Entry (aktiv) ──────────────────
                core: resolve(__dirname, 'src/frontend/core/init.js'),

                // ── Phase 3: Legacy-Adapter-Entries (aktiv) ───────
                din1090:  resolve(__dirname, 'modules/din1090/frontend/index.js'),
                lager:    resolve(__dirname, 'modules/lager/frontend/index.js'),
                aufmass:  resolve(__dirname, 'modules/aufmass/frontend/index.js'),
                vde0100:  resolve(__dirname, 'modules/vde0100/frontend/index.js'),

                // ── Phase 4: Feature-Wrapper-Entries (aktiv) ──────
                rechnungen:      resolve(__dirname, 'src/frontend/modules/rechnungen/index.js'),
                kunden:          resolve(__dirname, 'src/frontend/modules/kunden/index.js'),
                zeiterfassung:   resolve(__dirname, 'src/frontend/modules/zeiterfassung/index.js'),
                wochenplanung:   resolve(__dirname, 'src/frontend/modules/wochenplanung/index.js'),
                stundenauswertung: resolve(__dirname, 'src/frontend/modules/stundenauswertung/index.js'),
                termine:         resolve(__dirname, 'src/frontend/modules/termine/index.js'),
                dashboard:       resolve(__dirname, 'src/frontend/modules/dashboard/index.js'),
                whatsapp:        resolve(__dirname, 'src/frontend/modules/whatsapp/index.js'),
                auswertung:      resolve(__dirname, 'src/frontend/modules/auswertung/index.js'),
                oci:             resolve(__dirname, 'src/frontend/modules/oci/index.js'),
                datanorm:        resolve(__dirname, 'src/frontend/modules/datanorm/index.js'),
            },
            output: {
                entryFileNames:  '[name].js',
                chunkFileNames:  'chunks/[name]-[hash].js',
                assetFileNames:  'assets/[name]-[hash][extname]',
            },
        },
    },

    resolve: {
        alias: {
            // Abkürzungen für Imports in Phase 2+:
            //   import { appData } from '@core/store.js'
            //   import MyWidget   from '@modules/rechnungen/Widget.vue'
            '@core':    resolve(__dirname, 'src/frontend/core'),
            '@modules': resolve(__dirname, 'src/frontend/modules'),
        },
    },
});
