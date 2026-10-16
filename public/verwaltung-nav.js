// ============================================================
// Verwaltung allgemein - Unternavigation: reine Helferfunktionen
// (AP-20261012-datensicherung-nas, T1). Kein DOM-Zugriff - testbar
// per node:test (Muster public/sollzeit.js).
// ============================================================
(function (global) {
  const BK_VERWALTUNG_BEREICHE = [
    { id: 'firma', titel: 'Firma & Branding', ico: 'building' },
    { id: 'module', titel: 'Module', ico: 'box' },
    { id: 'anmeldung', titel: 'Anmeldung & Startseiten', ico: 'lock' },
    { id: 'nummern', titel: 'Kunden- & Personalnummern', ico: 'clipboard' },
    { id: 'baustellen', titel: 'Baustellen & Bereiche', ico: 'folder' },
    { id: 'zeiterfassung', titel: 'Zeiterfassung', ico: 'clock' },
    { id: 'email', titel: 'E-Mail (SMTP)', ico: 'mail' },
    { id: 'schnittstellen', titel: 'Schnittstellen (KI, OCI)', ico: 'upload' },
    { id: 'datensicherung', titel: 'Datensicherung', ico: 'shield' },
    { id: 'system', titel: 'System & Update', ico: 'settings' },
  ];

  // Liefert eine gültige Bereichs-ID: die gespeicherte ID, wenn sie unter
  // den sichtbaren Bereichen ist, sonst den ersten sichtbaren Bereich
  // (Fallback bei unbekannter/leerer/fehlender gespeicherter ID).
  function bkVerwaltungBereich(gespeicherteId, sichtbareIds) {
    const sichtbar = Array.isArray(sichtbareIds) ? sichtbareIds : [];
    if (gespeicherteId && sichtbar.includes(gespeicherteId)) return gespeicherteId;
    return sichtbar.length ? sichtbar[0] : null;
  }

  // Normalisiert Text für die Suche: klein, Umlaute/ß transliteriert,
  // Leerraum zusammengefasst und getrimmt.
  function bkVerwaltungNormalisiere(text) {
    if (typeof text !== 'string') return '';
    return text
      .toLowerCase()
      .replace(/ä/g, 'ae')
      .replace(/ö/g, 'oe')
      .replace(/ü/g, 'ue')
      .replace(/ß/g, 'ss')
      .replace(/\s+/g, ' ')
      .trim();
  }

  // Liefert die Indizes der Kartentexte, die alle Wörter des Suchbegriffs
  // enthalten (normalisiert); ein leerer Suchbegriff liefert alle Indizes.
  function bkVerwaltungTreffer(suchbegriff, kartenTexte) {
    const texte = Array.isArray(kartenTexte) ? kartenTexte : [];
    const begriff = bkVerwaltungNormalisiere(suchbegriff);
    if (!begriff) return texte.map((_, index) => index);

    const woerter = begriff.split(' ').filter(Boolean);
    const treffer = [];
    texte.forEach((text, index) => {
      const normalisiert = bkVerwaltungNormalisiere(text);
      if (woerter.every(wort => normalisiert.includes(wort))) treffer.push(index);
    });
    return treffer;
  }

  global.BK_VERWALTUNG_BEREICHE = BK_VERWALTUNG_BEREICHE;
  global.bkVerwaltungBereich = bkVerwaltungBereich;
  global.bkVerwaltungNormalisiere = bkVerwaltungNormalisiere;
  global.bkVerwaltungTreffer = bkVerwaltungTreffer;
})(window);
