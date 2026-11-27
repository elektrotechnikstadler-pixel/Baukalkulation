import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const faelle = JSON.parse(await readFile(new URL('../fixtures/lexoffice/rechnungen-modus.json', import.meta.url), 'utf8'));
const script = await readFile(new URL('../../public/rechnungen-modus.js', import.meta.url), 'utf8');
const context = {};
context.window = context;
vm.createContext(context);
vm.runInContext(script, context, { filename: 'public/rechnungen-modus.js' });
const { bkRechnungenAktiv, bkRechnungenLesbar, bkRechnungenInLexware } = context;

for (const fall of faelle) {
  test(`Rechnungsmodus: ${fall.name}`, () => {
    assert.equal(bkRechnungenAktiv(fall.settings), fall.aktiv);
    assert.equal(bkRechnungenLesbar(fall.settings, fall.rolle), fall.lesbar);
    assert.equal(bkRechnungenInLexware(fall.settings), fall.inLexware);
  });
}

test('Helfer-Skripte werden vor script.min.js geladen und vom Service Worker vorgehalten', async () => {
  const index = await readFile(new URL('../../public/index.html', import.meta.url), 'utf8');
  const sw = await readFile(new URL('../../public/sw.js', import.meta.url), 'utf8');
  for (const datei of ['rechnungen-modus.js', 'modules/lexoffice/lexoffice.js']) {
    const muster = datei.replace(/[./]/g, c => '\\' + c);
    const helfer = index.search(new RegExp(`<script src="${muster}\\?v=\\d+"></script>`));
    assert.ok(helfer > 0 && helfer < index.indexOf('<script src="script.min.js'), datei);
    assert.match(sw, new RegExp(`'\\./${muster}\\?v=\\d+'`), datei);
  }
});
