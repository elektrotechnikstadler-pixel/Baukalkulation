import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const fixtureUrl = new URL('../fixtures/sollzeit/faelle.json', import.meta.url);
const scriptUrl = new URL('../../public/sollzeit.js', import.meta.url);

const faelle = JSON.parse(await readFile(fixtureUrl, 'utf8'));
const script = await readFile(scriptUrl, 'utf8');
const context = {};
context.window = context;
vm.createContext(context);
vm.runInContext(script, context, { filename: 'public/sollzeit.js' });

const {
  bkTagesSoll,
  bkIstStundenEintrag,
  bkFeiertage,
  bkVirtuelleFeiertage,
  bkGleitzeitSaldo,
  bkUrlaubsanspruch,
  bkSollBeschreibung,
} = context;

test('bkTagesSoll erfüllt alle gemeinsamen Fälle', () => {
  assert.equal(typeof bkTagesSoll, 'function');

  for (const fall of faelle.tagesSoll) {
    assert.equal(bkTagesSoll(fall.mitarbeiter, fall.datum), fall.expected, fall.name);
  }
});

test('bkTagesSoll behandelt Sonntag als ISO-Wochentag 7', () => {
  assert.equal(typeof bkTagesSoll, 'function');

  assert.equal(
    bkTagesSoll(
      {
        sollzeitJeWochentag: true,
        sollstundenMo: 0,
        sollstundenDi: 0,
        sollstundenMi: 0,
        sollstundenDo: 0,
        sollstundenFr: 0,
        sollstundenSa: 0,
        sollstundenSo: 3,
      },
      '2025-03-16',
    ),
    3,
  );
});

test('bkIstStundenEintrag erfüllt alle gemeinsamen Fälle', () => {
  assert.equal(typeof bkIstStundenEintrag, 'function');

  for (const fall of faelle.istStundenEintrag) {
    assert.equal(
      bkIstStundenEintrag(fall.mitarbeiter, fall.eintrag, fall.customTypen),
      fall.expected,
      fall.name,
    );
  }
});

test('bkFeiertage erfüllt alle gemeinsamen Fälle', () => {
  assert.equal(typeof bkFeiertage, 'function');

  for (const fall of faelle.feiertage) {
    const feiertage = bkFeiertage(fall.jahr, fall.customFeiertage);

    assert.equal(typeof feiertage?.keys, 'function', `${fall.name}: Ergebnis ist eine Map`);
    assert.deepEqual(Array.from(feiertage.keys()), fall.expectedDates, fall.name);
    for (const [datum, name] of Object.entries(fall.expectedNames)) {
      assert.equal(feiertage.get(datum), name, `${fall.name} ${datum}`);
    }
  }
});

test('bkUrlaubsanspruch erfüllt alle gemeinsamen Fälle', () => {
  assert.equal(typeof bkUrlaubsanspruch, 'function');

  for (const fall of faelle.urlaubsanspruch) {
    assert.equal(bkUrlaubsanspruch(fall.mitarbeiter, fall.jahr), fall.expected, fall.name);
  }
});

test('bkGleitzeitSaldo erfüllt alle gemeinsamen Fälle', () => {
  assert.equal(typeof bkGleitzeitSaldo, 'function');

  for (const fall of faelle.saldo) {
    assert.equal(
      bkGleitzeitSaldo(
        fall.eintraege,
        { username: fall.username, ...fall.mitarbeiter },
        fall.jahr,
        fall.buchungen,
        {
          startdatum: fall.startdatum,
          customTypen: fall.customTypen,
          customFeiertage: fall.customFeiertage,
          heute: fall.heute,
        },
      ),
      fall.expected,
      fall.name,
    );
  }
});

test('bkVirtuelleFeiertage liefert nur Soll-Feiertage ohne Eintrag', () => {
  assert.equal(typeof bkVirtuelleFeiertage, 'function');

  const virtuelle = bkVirtuelleFeiertage(
    [{ datum: '2025-03-17', typ: 'arbeit', stunden: 1 }],
    { sollstundenTag: 8, arbeitstage: '1,2,3,4,5' },
    2025,
    '2025-03',
    [
      { datum: '2025-03-17', name: 'Betriebsfeier mit Eintrag' },
      { datum: '2025-03-18', name: 'Betriebsfeier' },
      { datum: '2025-03-22', name: 'Samstagsfeier' },
    ],
  );

  assert.deepEqual(
    virtuelle.map(entry => ({
      datum: entry.datum,
      typ: entry.typ,
      stunden: entry.stunden,
      bemerkung: entry.bemerkung,
      virtual: entry._virtual,
    })),
    [
      {
        datum: '2025-03-18',
        typ: 'feiertag',
        stunden: 8,
        bemerkung: 'Betriebsfeier',
        virtual: true,
      },
    ],
  );
});

test('bkVirtuelleFeiertage filtert auf den Monat', () => {
  assert.equal(typeof bkVirtuelleFeiertage, 'function');

  const virtuelle = bkVirtuelleFeiertage(
    [],
    { sollstundenTag: 8, arbeitstage: '1,2,3,4,5' },
    2025,
    '2025-04',
    [],
  );

  assert.deepEqual(
    virtuelle.map(entry => entry.datum),
    ['2025-04-18', '2025-04-21'],
  );
});

test('bkSollBeschreibung beschreibt Altmodus', () => {
  assert.equal(typeof bkSollBeschreibung, 'function');

  assert.equal(
    bkSollBeschreibung({ sollstundenTag: 8, arbeitstage: '1,2,3,4,5' }),
    '8 h/Tag (Mo–Fr)',
  );
});

test('bkSollBeschreibung beschreibt Wochentagsmodus gruppiert', () => {
  assert.equal(typeof bkSollBeschreibung, 'function');

  const beschreibung = bkSollBeschreibung({
    sollzeitJeWochentag: true,
    sollstundenMo: 8,
    sollstundenDi: 8,
    sollstundenMi: 8,
    sollstundenDo: 8,
    sollstundenFr: 6,
    sollstundenSa: 0,
    sollstundenSo: 0,
  });

  assert.match(beschreibung, /Mo–Do 8 h/);
  assert.match(beschreibung, /Fr 6 h/);
});

test('bkSollBeschreibung meldet fehlendes Soll', () => {
  assert.equal(typeof bkSollBeschreibung, 'function');

  assert.equal(
    bkSollBeschreibung({
      sollzeitJeWochentag: true,
      sollstundenMo: 0,
      sollstundenDi: 0,
      sollstundenMi: 0,
      sollstundenDo: 0,
      sollstundenFr: 0,
      sollstundenSa: 0,
      sollstundenSo: 0,
    }),
    'kein Soll',
  );
});

test('bkSollBeschreibung formatiert Dezimalwerte mit deutschem Komma', () => {
  assert.equal(typeof bkSollBeschreibung, 'function');

  assert.match(
    bkSollBeschreibung({
      sollzeitJeWochentag: true,
      sollstundenMo: 7.5,
      sollstundenDi: 0,
      sollstundenMi: 0,
      sollstundenDo: 0,
      sollstundenFr: 0,
      sollstundenSa: 0,
      sollstundenSo: 0,
    }),
    /7,5 h/,
  );
});
