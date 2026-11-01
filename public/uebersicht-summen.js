// ============================================================
// Gesamtübersicht - Summenberechnung: reine Helferfunktion
// (AP-20261101-gesamtuebersicht-filter). Kein DOM-Zugriff - testbar
// per node:test (Muster public/verwaltung-nav.js).
// ============================================================
(function (global) {
  // Liefert die Kennzahlen (Summen + Anzahl) für die aktuell sichtbaren
  // Zeilen der Gesamtübersicht. rows = Objekte {id, parentId, isSub,
  // excluded, ms, as, ps, abs, g, gewinn} (Oberprojekte bereits mit den
  // aggregierten Werten ihrer Unterprojekte). sichtbareIds = Set/Array
  // der sichtbaren Zeilen-IDs; null/undefined bedeutet: alle sichtbar.
  // Regel: sichtbare, nicht ausgeschlossene Oberprojekte/eigenständige
  // Baustellen zählen immer; sichtbare, nicht ausgeschlossene
  // Unterprojekte zählen nur, wenn ihr Oberprojekt NICHT sichtbar ist
  // (keine Doppelzählung). `anzahl` zählt alle sichtbaren Zeilen,
  // auch ausgeschlossene.
  function bkUebersichtSummen(rows, sichtbareIds) {
    const liste = Array.isArray(rows) ? rows : [];
    const alleSichtbar = sichtbareIds === null || sichtbareIds === undefined;

    // IDs einmal vorab als String-Set normalisieren: sichtbareIds kann
    // Zahlen (DOM-Reste) oder Strings (dataset.id) enthalten, row.parentId
    // kommt ungecastet aus appData. String(id)-Vergleich verhindert, dass
    // ein String-parentId als "nicht sichtbar" durchrutscht (Doppelzählung).
    let sichtbarSet = null;
    if (!alleSichtbar) {
      sichtbarSet = new Set();
      if (Array.isArray(sichtbareIds)) {
        sichtbareIds.forEach(id => sichtbarSet.add(String(id)));
      } else if (sichtbareIds && typeof sichtbareIds.forEach === 'function') {
        // Set-Erkennung per Duck-Typing statt `instanceof Set`: in Tests
        // (node:vm) laufen Set-Instanzen aus einem anderen Realm, dort
        // schlägt `instanceof` fehl, `.forEach()` funktioniert realm-sicher.
        sichtbareIds.forEach(id => sichtbarSet.add(String(id)));
      }
    }

    function istSichtbar(id) {
      if (alleSichtbar) return true;
      if (id === null || id === undefined) return false;
      return sichtbarSet.has(String(id));
    }

    let anzahl = 0;
    let totalMat = 0, totalAz = 0, totalP = 0, totalAbschlag = 0, totalGewinn = 0;

    liste.forEach(row => {
      if (!istSichtbar(row.id)) return;
      anzahl += 1;
      if (row.excluded) return;
      if (row.isSub && istSichtbar(row.parentId)) return;
      totalMat      += row.ms || 0;
      totalAz       += row.as || 0;
      totalP        += row.ps || 0;
      totalAbschlag += row.abs || 0;
      totalGewinn   += row.gewinn || 0;
    });

    const grand = totalMat + totalAz + totalP;
    const grandOffen = grand - totalAbschlag;

    return { anzahl, totalMat, totalAz, totalP, totalAbschlag, totalGewinn, grand, grandOffen };
  }

  global.bkUebersichtSummen = bkUebersichtSummen;
})(window);
