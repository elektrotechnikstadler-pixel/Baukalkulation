// ============================================================
// Betriebsart „Rechnungen erzeugen in“ – reine Helferfunktionen
// (AP-20261126-lexoffice-schnittstelle, 2.2). PHP-Gegenstück
// src/Services/RechnungsModus.php, gemeinsame Fallliste
// tests/fixtures/lexoffice/rechnungen-modus.json.
// ============================================================
(function (global) {
  function bkRechnungenInLexware(s) {
    return !!s && s.modul_lexoffice === true && (s.rechnung_erzeugung || 'baukalkulation') === 'lexware';
  }

  // Neue Belege, Löschen und Versand in der Baukalkulation erlaubt?
  function bkRechnungenAktiv(s) {
    if (!s || s.modul_rechnungen === false) return false;
    return !bkRechnungenInLexware(s);
  }

  // Liste/Formular sichtbar? In Betriebsart Lexware nur noch für Admins (Archiv).
  function bkRechnungenLesbar(s, rolle) {
    if (!s || s.modul_rechnungen === false) return false;
    return !bkRechnungenInLexware(s) || rolle === 'admin';
  }

  global.bkRechnungenInLexware = bkRechnungenInLexware;
  global.bkRechnungenAktiv = bkRechnungenAktiv;
  global.bkRechnungenLesbar = bkRechnungenLesbar;
})(window);
