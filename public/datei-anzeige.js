// ============================================================
// Datei-/Foto-Anzeigename: reine Helferfunktion
// (AP-20261119-sammel-aenderungen, Bündel B1). Kein DOM-Zugriff -
// testbar per node:test (Muster public/uebersicht-summen.js).
// Bugfix: bisher wurde per `f.name.substring(20)` der Dateiname
// vorne abgeschnitten. Richtig ist: voller Name wird zurückgegeben,
// die Kürzung übernimmt ausschließlich CSS-Ellipsis am Ende
// (.file-card-name in style.css / .list-row-title in mobile.html).
// ============================================================
(function (global) {
  // Liefert den vollständigen Anzeigenamen einer Datei/eines Fotos.
  // f = { name, alias? }. Alias hat Vorrang vor dem Dateinamen; es
  // wird NIE gekürzt - das übernimmt ausschließlich CSS (Ellipsis).
  function bkDateiAnzeigeName(f) {
    if (!f) return '';
    const alias = typeof f.alias === 'string' ? f.alias.trim() : '';
    if (alias) return alias;
    if (f.name === null || f.name === undefined) return '';
    return String(f.name);
  }

  global.bkDateiAnzeigeName = bkDateiAnzeigeName;
})(window);
