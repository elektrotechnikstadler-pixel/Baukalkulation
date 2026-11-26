import assert from 'node:assert/strict';
import test from 'node:test';
import { auswertenNodeTest, auswertenPhpstan, auswertenPhpunit, parseArgs } from '../../scripts/pruefen.mjs';

test('PHPUnit grün und rot werden aus Zusammenfassung und Exit-Code erkannt', () => {
  assert.deepEqual(auswertenPhpunit('...\nOK (12 tests, 30 assertions)\n', 0), { gruen: true, kurz: '12 Tests', details: [] });
  const rot = auswertenPhpunit('There were 2 failures:\n\n1) Tests\\Api\\ATest::testA\nfoo\n\n2) Tests\\Unit\\BTest::testB\n\nFAILURES!\nTests: 12, Assertions: 30, Failures: 2.\n', 1);
  assert.equal(rot.gruen, false);
  assert.deepEqual(rot.details, ['Tests\\Api\\ATest::testA', 'Tests\\Unit\\BTest::testB']);
  assert.equal(auswertenPhpunit('Fatal error: x', 255).gruen, false);
  // failOnWarning: „OK, but there were issues!“ mit Exit-Code 1 ist rot
  assert.equal(auswertenPhpunit('OK, but there were issues!\nTests: 3, Assertions: 3, Warnings: 1.\n', 1).gruen, false);
});

test('node:test (TAP) und PHPStan (raw) werden ausgewertet', () => {
  assert.equal(auswertenNodeTest('ok 1 - a\n# pass 5\n# fail 0\n', 0).gruen, true);
  const rot = auswertenNodeTest('not ok 2 - Summen stimmen\n# pass 4\n# fail 1\n', 1);
  assert.deepEqual([rot.gruen, rot.details], [false, ['Summen stimmen']]);
  assert.equal(auswertenPhpstan('', 0).gruen, true);
  assert.deepEqual(auswertenPhpstan('/app/src/A.php:12:Undefined variable $x\n', 1).details, ['/app/src/A.php:12:Undefined variable $x']);
});

test('--voll schaltet alle Schritte ein, unbekannte Optionen brechen ab', () => {
  const o = parseArgs(['--voll', '--filter', 'Lexoffice']);
  assert.deepEqual([o.pgsql, o.stan, o.versionen, o.js, o.phpunit, o.filter], [true, true, true, true, true, 'Lexoffice']);
  assert.throws(() => parseArgs(['--gibtsnicht']));
});
