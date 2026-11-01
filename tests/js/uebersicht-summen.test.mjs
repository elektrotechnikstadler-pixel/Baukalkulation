import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const summenUrl = new URL('../../public/uebersicht-summen.js', import.meta.url);

let script = null;
let ladefehler = null;
try {
  script = await readFile(summenUrl, 'utf8');
} catch (error) {
  ladefehler = error;
}

function ladeFunktion() {
  if (ladefehler) {
    return {
      fehler: `Datei public/uebersicht-summen.js fehlt oder ist nicht lesbar: ${ladefehler.message}`,
      fn: null,
    };
  }

  const context = {};
  context.window = context;
  context.globalThis = context;
  vm.createContext(context);

  try {
    vm.runInContext(script, context, { filename: 'public/uebersicht-summen.js' });
  } catch (error) {
    return { fehler: `Scriptfehler in public/uebersicht-summen.js: ${error.message}`, fn: null };
  }

  if (typeof context.bkUebersichtSummen !== 'function') {
    return { fehler: 'Globale Funktion bkUebersichtSummen(rows, sichtbareIds) fehlt.', fn: null };
  }

  return { fehler: null, fn: context.bkUebersichtSummen };
}

const vertrag = ladeFunktion();
const skipGrund = vertrag.fehler ? `Voraussetzung fehlt: ${vertrag.fehler}` : false;

const rows = [
  { id: 'P1', parentId: null, isSub: false, excluded: false, ms: 100, as: 50, ps: 10, abs: 20, g: 160, gewinn: 30 },
  { id: 'S1', parentId: 'P1', isSub: true, excluded: false, ms: 40, as: 10, ps: 5, abs: 3, g: 55, gewinn: 8 },
  { id: 'P2', parentId: null, isSub: false, excluded: true, ms: 200, as: 100, ps: 50, abs: 0, g: 350, gewinn: 20 },
  { id: 'P3', parentId: null, isSub: false, excluded: false, ms: 70, as: 20, ps: 10, abs: 5, g: 100, gewinn: 15 },
  { id: 'S3', parentId: 'P3', isSub: true, excluded: false, ms: 7, as: 2, ps: 1, abs: 1, g: 10, gewinn: 2 },
];

function assertSummen(actual, expected) {
  assert.equal(actual.anzahl, expected.anzahl, 'anzahl');
  for (const key of ['totalMat', 'totalAz', 'totalP', 'totalAbschlag', 'totalGewinn', 'grand', 'grandOffen']) {
    assert.equal(typeof actual[key], 'number', `${key} muss Zahl sein`);
    assert.ok(Math.abs(actual[key] - expected[key]) < 1e-9, `${key}: erwartet ${expected[key]}, erhalten ${actual[key]}`);
  }
}

test('bkUebersichtSummen Vertrag: Datei und Funktion vorhanden', () => {
  assert.equal(vertrag.fehler, null, vertrag.fehler ?? undefined);
  assert.equal(typeof vertrag.fn, 'function');
});

test('ohne Filter summiert nur Oberprojekte ohne excluded', { skip: skipGrund }, () => {
  const sum = vertrag.fn(rows);
  assertSummen(sum, {
    anzahl: 5, totalMat: 170, totalAz: 70, totalP: 20, totalAbschlag: 25, totalGewinn: 45, grand: 260, grandOffen: 235,
  });
});

test('Filter auf Teilmenge summiert nur sichtbare relevante Zeilen', { skip: skipGrund }, () => {
  const sum = vertrag.fn(rows, ['P1', 'S1', 'P2']);
  assertSummen(sum, {
    anzahl: 3, totalMat: 100, totalAz: 50, totalP: 10, totalAbschlag: 20, totalGewinn: 30, grand: 160, grandOffen: 140,
  });
});

test('excluded wird nicht aufsummiert, zaehlt aber in anzahl', { skip: skipGrund }, () => {
  const sum = vertrag.fn(rows, new Set(['P2']));
  assertSummen(sum, {
    anzahl: 1, totalMat: 0, totalAz: 0, totalP: 0, totalAbschlag: 0, totalGewinn: 0, grand: 0, grandOffen: 0,
  });
});

test('sichtbares Unterprojekt zaehlt nur wenn Parent unsichtbar ist', { skip: skipGrund }, () => {
  const sum = vertrag.fn(rows, new Set(['S1']));
  assertSummen(sum, {
    anzahl: 1, totalMat: 40, totalAz: 10, totalP: 5, totalAbschlag: 3, totalGewinn: 8, grand: 55, grandOffen: 52,
  });
});

test('Parent + Unterprojekt sichtbar vermeidet Doppelzaehlung', { skip: skipGrund }, () => {
  const sum = vertrag.fn(rows, new Set(['P1', 'S1']));
  assertSummen(sum, {
    anzahl: 2, totalMat: 100, totalAz: 50, totalP: 10, totalAbschlag: 20, totalGewinn: 30, grand: 160, grandOffen: 140,
  });
});

test('Reihenfolge der rows ist fuer Summen egal', { skip: skipGrund }, () => {
  const shuffled = [rows[3], rows[1], rows[4], rows[0], rows[2]];
  const a = vertrag.fn(rows);
  const b = vertrag.fn(shuffled);
  assertSummen(a, b);
});

test('leerer Filter liefert 0-Summen und anzahl 0', { skip: skipGrund }, () => {
  const sum = vertrag.fn(rows, new Set());
  assertSummen(sum, {
    anzahl: 0, totalMat: 0, totalAz: 0, totalP: 0, totalAbschlag: 0, totalGewinn: 0, grand: 0, grandOffen: 0,
  });
});

test('gemischte ID-Typen vermeiden Doppelzaehlung bei sichtbaren String-IDs', { skip: skipGrund }, () => {
  const mixedRows = [
    { id: 5, parentId: null, isSub: false, excluded: false, ms: 90, as: 30, ps: 10, abs: 5, g: 130, gewinn: 20 },
    { id: 6, parentId: '5', isSub: true, excluded: false, ms: 22, as: 8, ps: 3, abs: 2, g: 33, gewinn: 5 },
  ];
  const sum = vertrag.fn(mixedRows, ['5', '6']);
  assertSummen(sum, {
    anzahl: 2, totalMat: 90, totalAz: 30, totalP: 10, totalAbschlag: 5, totalGewinn: 20, grand: 130, grandOffen: 125,
  });
});

test('sichtbare Zahlen-IDs funktionieren auch bei String-Row-IDs', { skip: skipGrund }, () => {
  const mixedRows = [
    { id: '5', parentId: null, isSub: false, excluded: false, ms: 75, as: 25, ps: 5, abs: 4, g: 105, gewinn: 14 },
    { id: '6', parentId: 5, isSub: true, excluded: false, ms: 11, as: 4, ps: 1, abs: 1, g: 16, gewinn: 3 },
  ];
  const sum = vertrag.fn(mixedRows, [5, 6]);
  assertSummen(sum, {
    anzahl: 2, totalMat: 75, totalAz: 25, totalP: 5, totalAbschlag: 4, totalGewinn: 14, grand: 105, grandOffen: 101,
  });
});

