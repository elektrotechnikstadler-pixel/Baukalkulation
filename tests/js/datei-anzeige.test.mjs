import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const dateiAnzeigeUrl = new URL('../../public/datei-anzeige.js', import.meta.url);

let script = null;
let ladefehler = null;
try {
  script = await readFile(dateiAnzeigeUrl, 'utf8');
} catch (error) {
  ladefehler = error;
}

function ladeFunktion() {
  if (ladefehler) {
    return {
      fehler: `Datei public/datei-anzeige.js fehlt oder ist nicht lesbar: ${ladefehler.message}`,
      fn: null,
    };
  }

  const context = {};
  context.window = context;
  context.globalThis = context;
  vm.createContext(context);

  try {
    vm.runInContext(script, context, { filename: 'public/datei-anzeige.js' });
  } catch (error) {
    return { fehler: `Scriptfehler in public/datei-anzeige.js: ${error.message}`, fn: null };
  }

  if (typeof context.bkDateiAnzeigeName !== 'function') {
    return { fehler: 'Globale Funktion bkDateiAnzeigeName(f) fehlt.', fn: null };
  }

  return { fehler: null, fn: context.bkDateiAnzeigeName };
}

const vertrag = ladeFunktion();
const skipGrund = vertrag.fehler ? `Voraussetzung fehlt: ${vertrag.fehler}` : false;

test('bkDateiAnzeigeName Vertrag: Datei und Funktion vorhanden', () => {
  assert.equal(vertrag.fehler, null, vertrag.fehler ?? undefined);
  assert.equal(typeof vertrag.fn, 'function');
});

test('langer Dateiname wird vollständig zurückgegeben (Bugfix: kein Abschneiden vorne)', { skip: skipGrund }, () => {
  const name = 'Langer_Dateiname_für_Test_2026.pdf';
  const ergebnis = vertrag.fn({ name });
  // Ohne den Fix hätte `name.substring(20)` die ersten 20 Zeichen
  // abgeschnitten - das Ergebnis muss den VOLLEN Namen enthalten.
  assert.equal(ergebnis, name);
  assert.equal(ergebnis.startsWith('Langer_Dateiname'), true);
});

test('Alias hat Vorrang vor dem Dateinamen', { skip: skipGrund }, () => {
  const ergebnis = vertrag.fn({ name: 'ein_sehr_langer_original_dateiname.jpg', alias: 'Rechnung Mai' });
  assert.equal(ergebnis, 'Rechnung Mai');
});

test('leerer oder Whitespace-Alias blendet den Dateinamen nicht aus', { skip: skipGrund }, () => {
  assert.equal(vertrag.fn({ name: 'rechnung.pdf', alias: '' }), 'rechnung.pdf');
  assert.equal(vertrag.fn({ name: 'rechnung.pdf', alias: '   ' }), 'rechnung.pdf');
  assert.equal(vertrag.fn({ name: 'rechnung.pdf', alias: '\t' }), 'rechnung.pdf');
});

test('Dateiname ohne Alias wird auch bei exakt 20 Zeichen unverändert zurückgegeben', { skip: skipGrund }, () => {
  const name = '12345678901234567890';
  assert.equal(name.length, 20);
  assert.equal(vertrag.fn({ name }), name);
});

test('Umlaute im Dateinamen bleiben erhalten', { skip: skipGrund }, () => {
  const name = 'ÄÖÜ_ß_Rechnung_2026.pdf';
  assert.equal(vertrag.fn({ name }), name);
});

test('kurzer Dateiname ohne Alias wird unverändert zurückgegeben', { skip: skipGrund }, () => {
  assert.equal(vertrag.fn({ name: 'kurz.pdf' }), 'kurz.pdf');
});

test('fehlendes Objekt/Name liefert leeren String statt Fehler', { skip: skipGrund }, () => {
  assert.equal(vertrag.fn(null), '');
  assert.equal(vertrag.fn({}), '');
  assert.equal(vertrag.fn({ name: null }), '');
});