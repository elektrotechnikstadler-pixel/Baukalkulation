import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const navScriptUrl = new URL('../../public/verwaltung-nav.js', import.meta.url);
const appScriptUrl = new URL('../../public/script.js', import.meta.url);
const indexUrl = new URL('../../public/index.html', import.meta.url);
const swUrl = new URL('../../public/sw.js', import.meta.url);

const navScript = await readFile(navScriptUrl, 'utf8');
const appScript = await readFile(appScriptUrl, 'utf8');
const indexHtml = await readFile(indexUrl, 'utf8');
const swScript = await readFile(swUrl, 'utf8');

const context = {};
context.window = context;
vm.createContext(context);
vm.runInContext(navScript, context, { filename: 'public/verwaltung-nav.js' });

const {
  BK_VERWALTUNG_BEREICHE,
  bkVerwaltungBereich,
  bkVerwaltungNormalisiere,
  bkVerwaltungTreffer,
} = context;

const vertragT1Bereiche = [
  'firma',
  'module',
  'anmeldung',
  'nummern',
  'baustellen',
  'zeiterfassung',
  'email',
  'schnittstellen',
  'datensicherung',
  'system',
];

function renderAllgemeinSettingsBlock(text) {
  const start = text.indexOf('function renderAllgemeinSettings(');
  assert.ok(start >= 0, 'renderAllgemeinSettings() muss vorhanden sein');

  const end = text.indexOf('\nfunction initVerwaltungNav(', start);
  assert.ok(end > start, 'initVerwaltungNav() muss nach renderAllgemeinSettings() folgen');

  return text.slice(start, end);
}

const renderBlock = renderAllgemeinSettingsBlock(appScript);

test('BK_VERWALTUNG_BEREICHE enthält Vertrags-Bereiche eindeutig und sortiert', () => {
  assert.ok(Array.isArray(BK_VERWALTUNG_BEREICHE), 'BK_VERWALTUNG_BEREICHE muss ein Array sein');

  const ids = BK_VERWALTUNG_BEREICHE.map(b => b.id);
  assert.equal(new Set(ids).size, ids.length, 'Bereichs-IDs müssen eindeutig sein');

  let vorherigerIndex = -1;
  for (const id of vertragT1Bereiche) {
    const index = ids.indexOf(id);
    assert.ok(index >= 0, `Vertrags-Bereich fehlt: ${id}`);
    assert.ok(index > vorherigerIndex, `Bereich ${id} steht nicht in der Vertrags-Reihenfolge`);
    vorherigerIndex = index;
  }

  for (const bereich of BK_VERWALTUNG_BEREICHE) {
    assert.equal(typeof bereich.titel, 'string', `Titel muss String sein (${bereich.id})`);
    assert.ok(bereich.titel.trim().length > 0, `Titel darf nicht leer sein (${bereich.id})`);
  }
});

test('bkVerwaltungBereich nutzt gespeicherte ID oder fällt robust auf sichtbare Bereiche zurück', () => {
  assert.equal(typeof bkVerwaltungBereich, 'function');

  const sichtbar = ['module', 'email', 'system'];
  assert.equal(bkVerwaltungBereich('email', sichtbar), 'email');

  for (const ungueltig of [null, undefined, '', 'unbekannt', 'firma']) {
    assert.equal(
      bkVerwaltungBereich(ungueltig, sichtbar),
      'module',
      `Fallback für gespeicherte ID "${ungueltig}"`,
    );
  }

  assert.doesNotThrow(() => bkVerwaltungBereich('email', []));
  assert.ok(bkVerwaltungBereich('email', []) == null, 'Leere Sichtbarkeit ergibt null/undefined');
});

test('bkVerwaltungNormalisiere normalisiert Groß-/Kleinschreibung, Umlaute und Leerzeichen', () => {
  assert.equal(typeof bkVerwaltungNormalisiere, 'function');

  assert.equal(
    bkVerwaltungNormalisiere('  ÄÖÜ ß   SMTP  Bereich  '),
    'aeoeue ss smtp bereich',
  );
  assert.equal(bkVerwaltungNormalisiere('AbMELDUNG'), 'abmeldung');
  assert.equal(bkVerwaltungNormalisiere('  mehr   fach   leerzeichen '), 'mehr fach leerzeichen');
});

test('bkVerwaltungTreffer erfüllt Suche nach Vertrag (SMTP, Personalnummer, Umlaut, Mehrwort)', () => {
  assert.equal(typeof bkVerwaltungTreffer, 'function');

  const kartenTexte = [
    'E-Mail / SMTP',
    'Kunden- & Personalnummern',
    'OCI Großhandel Schnittstelle',
    'Automatische Abmeldung',
    'Datensicherung und Wiederherstellung',
  ];

  const treffer = suchbegriff => Array.from(bkVerwaltungTreffer(suchbegriff, kartenTexte));

  assert.deepEqual(treffer('SMTP'), [0]);
  assert.deepEqual(treffer('personalnummer'), [1]);
  assert.deepEqual(treffer('grosshandel'), [2]);
  assert.deepEqual(treffer('abMELdung'), [3]);
  assert.deepEqual(treffer('datensicherung wiederherstellung'), [4]);
  assert.deepEqual(treffer(''), [0, 1, 2, 3, 4]);
  assert.deepEqual(treffer('   '), [0, 1, 2, 3, 4]);
  assert.deepEqual(treffer('smtp grosshandel'), []);
});

test('Wächter: renderAllgemeinSettings nutzt nur bekannte data-bereich-IDs und behält Pflicht-Element-IDs', () => {
  const erlaubteBereiche = new Set(BK_VERWALTUNG_BEREICHE.map(b => b.id));
  const bereicheInRender = Array.from(renderBlock.matchAll(/data-bereich="([^"]+)"/g), m => m[1]);

  assert.ok(bereicheInRender.length > 0, 'renderAllgemeinSettings() enthält data-bereich-Marker');
  for (const id of bereicheInRender) {
    assert.ok(erlaubteBereiche.has(id), `Unbekannter data-bereich in renderAllgemeinSettings(): ${id}`);
  }

  for (const id of vertragT1Bereiche) {
    assert.ok(
      bereicheInRender.includes(id),
      `Vertrags-Bereich ${id} muss mindestens einmal in renderAllgemeinSettings() vorkommen`,
    );
  }

  const pflichtIds = [
    'erBackupEmailAktiv',
    'erBackupEmailEmpfaenger',
    'erBackupEmailZyklus',
    'erBackupEmailUhrzeit',
    'erBackupEmailPasswort',
    'erBackupEmailMaxMb',
    'erSaEmailAktiv',
    'ociLieferantenSection',
    'feiertageSection',
    'settSaveStatus',
  ];
  for (const id of pflichtIds) {
    assert.match(renderBlock, new RegExp(`id="(?:${id})"`), `Pflicht-Element-ID fehlt: ${id}`);
  }
});

test('Statisch: index lädt verwaltung-nav.js vor script.min.js, sw.js precacht dieselbe Version', () => {
  const navMatch = /<script\s+src=["']verwaltung-nav\.js\?v=([^"']+)["']><\/script>/.exec(indexHtml);
  const minMatch = /<script\s+src=["']script\.min\.js\?v=([^"']+)["']><\/script>/.exec(indexHtml);
  assert.ok(navMatch, 'index.html muss verwaltung-nav.js laden');
  assert.ok(minMatch, 'index.html muss script.min.js laden');
  assert.ok(
    navMatch.index < minMatch.index,
    'verwaltung-nav.js muss vor script.min.js eingebunden sein',
  );

  const swMatch = /['"]\.\/verwaltung-nav\.js\?v=([^'"]+)['"]/.exec(swScript);
  assert.ok(swMatch, 'sw.js muss verwaltung-nav.js im Precache enthalten');
  assert.equal(swMatch[1], navMatch[1], 'Version von verwaltung-nav.js muss in index und sw identisch sein');
});
