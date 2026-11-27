// ============================================================
// Lexware Office – Kunden-Badge und Kunden-Abgleich (AP-20261126, B2)
// Backend: modules/lexoffice/backend/Module.php. Nutzerdaten nur
// escaped bzw. per textContent einfügen.
// ============================================================
(function (global) {
  const h = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const PERMALINK = 'https://app.lexware.de/permalink/contacts/view/';
  const BLOCK = 10;

  async function aufruf(sub, body, methode) {
    const opts = methode === 'GET' ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) };
    const r = await fetch('api.php?action=module&module=lexoffice&sub=' + encodeURIComponent(sub), opts);
    let j = {};
    try { j = await r.json(); } catch (e) { /* leere Antwort */ }
    return { ok: r.ok && j.ok === true, j };
  }

  function aktiv() {
    return typeof appSettings !== 'undefined' && appSettings.modul_lexoffice === true
      && typeof canDo === 'function' && canDo('canLexoffice');
  }

  // ── Kunden-Badge im Kundenformular ─────────────────────────────
  async function lexofficeKundeBadge(kundeId, el) {
    if (!el || !aktiv()) return;
    el.innerHTML = '<div class="lex-kunde">Lexware Office: <em>lädt…</em></div>';
    const { ok, j } = await aufruf('kunden_status', null, 'GET');
    if (!ok) { el.innerHTML = ''; return; }
    if (!j.verbunden) { el.innerHTML = '<div class="lex-kunde">Lexware Office: <em>Verbindung noch nicht getestet.</em></div>'; return; }
    const v = (j.kunden || {})[kundeId];
    const admin = typeof currentRole !== 'undefined' && currentRole === 'admin';
    if (v && v.status === 'uebertragen') {
      el.innerHTML = `<div class="lex-kunde">Lexware Office: <span class="lex-ok">✓ verknüpft</span>
        <a class="btn btn-ghost btn-sm" href="${PERMALINK + encodeURIComponent(v.lexId)}" target="_blank" rel="noopener">In Lexware öffnen</a>
        ${admin ? `<button class="btn btn-ghost btn-sm lex-warn" data-lex-loesen>Verknüpfung lösen</button>` : ''}</div>`;
      const btn = el.querySelector('[data-lex-loesen]');
      if (btn) btn.onclick = async () => {
        if (!confirm('Verknüpfung mit dem Lexware-Kontakt lösen? Der Kontakt in Lexware bleibt erhalten.')) return;
        const r = await aufruf('kunde_verknuepfung_loesen', { kundeId });
        if (!r.ok) alert(r.j.error || 'Fehlgeschlagen.');
        lexofficeKundeBadge(kundeId, el);
      };
      return;
    }
    const darfSenden = typeof canDo === 'function' && canDo('canWriteKunden');
    el.innerHTML = `<div class="lex-kunde">Lexware Office: ${v && v.status === 'fehler' ? '<span class="lex-warn">⚠ letzte Übertragung unklar</span>' : '<span>nicht verknüpft</span>'}
      ${darfSenden ? '<button class="btn btn-ghost btn-sm" data-lex-senden>An Lexware übertragen</button>' : ''}</div>`;
    const senden = el.querySelector('[data-lex-senden]');
    if (senden) senden.onclick = async (ev) => {
      ev.target.disabled = true;
      const r = await aufruf('kunde_uebertragen', { kundeId });
      if (!r.ok) { alert(r.j.error || 'Übertragung fehlgeschlagen.'); ev.target.disabled = false; return; }
      lexofficeKundeBadge(kundeId, el);
    };
  }

  // ── Kunden-Abgleich (Vorschau → bestätigen) ────────────────────
  async function lexofficeKundenAbgleichOeffnen() {
    const overlay = document.createElement('div');
    overlay.className = 'kunde-form-overlay';
    overlay.id = 'lexAbgleichOverlay';
    overlay.innerHTML = '<div class="kunde-form-card lex-abgleich"><h3>Kunden mit Lexware Office abgleichen</h3><p><em>Lade Kontakte aus Lexware Office…</em></p></div>';
    document.body.appendChild(overlay);
    const karte = overlay.firstElementChild;
    const { ok, j } = await aufruf('kunden_abgleich_vorschau');
    if (!ok) {
      karte.innerHTML = `<h3>Kunden abgleichen</h3><p class="lex-warn">${h(j.error || 'Vorschau fehlgeschlagen.')}</p>
        <div class="kunde-form-actions"><button class="btn btn-ghost btn-sm" data-lex-zu>Schließen</button></div>`;
      karte.querySelector('[data-lex-zu]').onclick = () => overlay.remove();
      return;
    }
    const kunden = j.kunden || [];
    const zeile = k => {
      const v = k.vorschlag || {};
      const optionen = [
        v.status === 'treffer' ? `<option value="verknuepfen" selected>Verknüpfen mit „${h(v.name)}“ (${v.grund === 'email' ? 'E-Mail' : 'Name'})</option>` : '',
        `<option value="anlegen" ${v.status === 'keiner' ? 'selected' : ''}>Neu in Lexware anlegen</option>`,
        `<option value="" ${v.status === 'mehrdeutig' ? 'selected' : ''}>Überspringen</option>`,
      ].join('');
      return `<tr data-kunde="${k.kundeId}" data-lexid="${h(v.lexId || '')}">
        <td>${h(k.kundennummer)}</td><td>${h(k.name)}</td><td>${h(k.ort)}</td>
        <td>${v.status === 'mehrdeutig' ? `<span class="lex-warn">${v.anzahl} gleichnamige Kontakte – bitte in Lexware bereinigen</span><br>` : ''}<select class="form-control">${optionen}</select></td>
        <td class="lex-ergebnis"></td></tr>`;
    };
    karte.innerHTML = `<h3>Kunden mit Lexware Office abgleichen</h3>
      <p style="font-size:.82rem;color:#555">${kunden.length} Kunden sind noch nicht verknüpft. Vorschläge prüfen, dann „Ausführen“.</p>
      <div style="max-height:55vh;overflow:auto"><table class="data-table"><thead><tr><th>Nr.</th><th>Kunde</th><th>Ort</th><th>Aktion</th><th></th></tr></thead>
      <tbody>${kunden.map(zeile).join('') || '<tr><td colspan="5"><em>Alle Kunden sind verknüpft.</em></td></tr>'}</tbody></table></div>
      <p class="lex-fortschritt" style="font-size:.85rem"></p>
      <div class="kunde-form-actions"><button class="btn btn-ghost btn-sm" data-lex-zu>Schließen</button>
        ${kunden.length ? '<button class="btn btn-primary btn-sm" data-lex-los>Ausführen</button>' : ''}</div>`;
    karte.querySelector('[data-lex-zu]').onclick = () => overlay.remove();
    const los = karte.querySelector('[data-lex-los]');
    if (los) los.onclick = () => ausfuehren(karte, los);
  }

  async function ausfuehren(karte, los) {
    const zeilen = [...karte.querySelectorAll('tr[data-kunde]')];
    const auftraege = zeilen.map(tr => ({ tr, aktion: tr.querySelector('select').value, kundeId: Number(tr.dataset.kunde), lexId: tr.dataset.lexid }))
      .filter(a => a.aktion !== '');
    if (!auftraege.length) return;
    if (!confirm(`${auftraege.length} Kunden jetzt zuordnen bzw. in Lexware anlegen?`)) return;
    los.disabled = true;
    const fortschritt = karte.querySelector('.lex-fortschritt');
    let fertig = 0, fehler = 0;
    // Kleine Blöcke: Lexware erlaubt 2 Anfragen/s, jeder Aufruf bleibt so deutlich unter dem Server-Zeitlimit.
    for (let i = 0; i < auftraege.length; i += BLOCK) {
      const block = auftraege.slice(i, i + BLOCK);
      fortschritt.textContent = `Bearbeite ${i + 1}–${i + block.length} von ${auftraege.length}…`;
      const { ok, j } = await aufruf('kunden_abgleich_ausfuehren', {
        zuordnungen: block.map(a => a.aktion === 'verknuepfen' ? { kundeId: a.kundeId, aktion: a.aktion, lexId: a.lexId } : { kundeId: a.kundeId, aktion: a.aktion })
      });
      if (!ok) { fortschritt.textContent = 'Abgebrochen: ' + (j.error || 'Fehler'); los.disabled = false; return; }
      for (const e of j.ergebnisse || []) {
        const a = block.find(x => x.kundeId === e.kundeId);
        if (!a) continue;
        const zelle = a.tr.querySelector('.lex-ergebnis');
        zelle.textContent = e.ok ? '✓' : '⚠ ' + (e.error || 'Fehler');
        zelle.className = 'lex-ergebnis ' + (e.ok ? 'lex-ok' : 'lex-warn');
        e.ok ? fertig++ : fehler++;
      }
    }
    fortschritt.textContent = `Fertig: ${fertig} zugeordnet${fehler ? `, ${fehler} mit Hinweis` : ''}.`;
  }

  // ── Projekt übertragen: Auswahl → Vorschau → Übertragen (B4) ─────
  const DARSTELLUNG = {
    material:    [['einzeln', 'Einzeln'], ['kategorie', 'Summe je Kategorie'], ['summe', 'Eine Zeile „lt. Aufstellung“'], ['aus', 'Nicht übertragen']],
    arbeitszeit: [['kategorie', 'Je Kategorie und Stundensatz'], ['tag', 'Je Tag'], ['tag_kuerzel', 'Je Tag und Kürzel'], ['summe', 'Eine Zeile „lt. Aufstellung“'], ['aus', 'Nicht übertragen']],
    pauschale:   [['einzeln', 'Einzeln'], ['summe', 'Eine Zeile'], ['aus', 'Nicht übertragen']],
  };
  const ART_TITEL = { material: 'Material', arbeitszeit: 'Arbeitszeit', pauschale: 'Pauschalen' };
  const euro = v => (Number(v) || 0).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const zahl = v => (Number(v) || 0).toLocaleString('de-DE', { maximumFractionDigits: 4 });
  const speicherKey = id => 'bk_lex_auswahl_' + id;

  function gemerkt(baustelleId) {
    try { return JSON.parse(localStorage.getItem(speicherKey(baustelleId)) || '{}') || {}; } catch (e) { return {}; }
  }

  async function lexofficeProjektUebertragen(baustelleId) {
    if (!baustelleId) return;
    const overlay = document.createElement('div');
    overlay.className = 'kunde-form-overlay';
    overlay.id = 'lexProjektOverlay';
    overlay.innerHTML = '<div class="kunde-form-card lex-abgleich"><h3>An Lexware Office übertragen</h3><p><em>Lade Projektpositionen…</em></p></div>';
    document.body.appendChild(overlay);
    const karte = overlay.firstElementChild;
    const start = Object.assign({}, gemerkt(baustelleId));
    delete start.ausgewaehlt; delete start.von; delete start.bis; delete start.reverseCharge13b;
    const { ok, j } = await aufruf('projekt_vorschau', { baustelleId, auswahl: start });
    if (!ok) return fehlerKarte(karte, overlay, j.error || 'Vorschau fehlgeschlagen.');
    const belege = await aufruf('projekt_belege', { baustelleId });
    auswahlZeigen(karte, overlay, baustelleId, j, belege.ok ? (belege.j.belege || []) : []);
  }

  function fehlerKarte(karte, overlay, text) {
    karte.innerHTML = `<h3>An Lexware Office übertragen</h3><p class="lex-warn">${h(text)}</p>
      <div class="kunde-form-actions"><button class="btn btn-ghost btn-sm" data-lex-zu>Schließen</button></div>`;
    karte.querySelector('[data-lex-zu]').onclick = () => overlay.remove();
  }

  function auswahlZeigen(karte, overlay, baustelleId, v, belege) {
    const a = v.auswahl;
    const sel = art => `<label>${ART_TITEL[art]}<select class="form-control" data-art="${art}">${DARSTELLUNG[art]
      .map(([w, t]) => `<option value="${w}" ${a[art] === w ? 'selected' : ''}>${h(t)}</option>`).join('')}</select></label>`;
    const zeile = p => `<tr><td><input type="checkbox" data-pos="${h(p.art)}:${h(p.id)}" ${p.abgerechnet && a.nurOffene ? '' : 'checked'}></td>
      <td>${ART_TITEL[p.art]}</td><td>${h(p.bezeichnung)}</td><td style="text-align:right">${zahl(p.menge)} ${h(p.einheit)}</td>
      <td style="text-align:right">${euro(p.betrag)} €</td><td>${p.abgerechnet ? '<span class="lex-warn">abgerechnet</span>' : ''}</td></tr>`;
    karte.innerHTML = `<h3>An Lexware Office übertragen – ${h(v.projekt.name)}</h3>
      <p style="font-size:.82rem;color:#555">Kunde: <strong>${h(v.projekt.kunde || '–')}</strong> · Übertragen wird der <em>gespeicherte</em> Projektstand als Entwurf; Nummer und PDF vergibt Lexware Office beim Fertigstellen.</p>
      ${(v.hinweise || []).map(t => `<p class="lex-warn" style="font-size:.82rem">${h(t)}</p>`).join('')}
      ${belege.length ? `<p style="font-size:.8rem">Bereits übertragen: ${belege.map(b => `<a href="${h(b.link)}" target="_blank" rel="noopener">${b.belegart === 'quotation' ? 'Angebot' : 'Rechnung'} ${h(b.nummer || 'Entwurf')} (${h(b.datum)})</a>`).join(', ')}</p>` : ''}
      <div class="lex-maske">
        <fieldset><legend>Belegart</legend>
          <label><input type="radio" name="lexBelegart" value="angebot" ${a.belegart === 'angebot' ? 'checked' : ''}> Angebot</label>
          <label><input type="radio" name="lexBelegart" value="rechnung" ${a.belegart === 'rechnung' ? 'checked' : ''}> Rechnungsentwurf</label>
        </fieldset>
        <fieldset><legend>Darstellung</legend>${sel('material')}${sel('arbeitszeit')}${sel('pauschale')}</fieldset>
        <fieldset><legend>Optionen</legend>
          <label><input type="checkbox" data-opt="ueberschriften" ${a.ueberschriften ? 'checked' : ''}> Gruppenüberschriften</label>
          <label><input type="checkbox" data-opt="nurOffene" ${a.nurOffene ? 'checked' : ''}> Nur noch nicht abgerechnete Positionen</label>
          <label><input type="checkbox" data-opt="reverseCharge13b" ${a.reverseCharge13b ? 'checked' : ''}> Bauleistung nach § 13b UStG (Steuerschuldnerschaft des Leistungsempfängers)</label>
          <label>Leistungszeitraum <input type="date" class="form-control" data-opt="von" value="${h(v.von)}"> – <input type="date" class="form-control" data-opt="bis" value="${h(v.bis)}"></label>
        </fieldset>
        <label>Einleitung<textarea class="form-control" data-opt="einleitung" rows="2">${h(a.einleitung)}</textarea></label>
        <label>Schlusstext<textarea class="form-control" data-opt="schluss" rows="2">${h(a.schluss)}</textarea></label>
      </div>
      <div style="max-height:32vh;overflow:auto;margin-top:8px"><table class="data-table"><thead><tr><th></th><th>Art</th><th>Position</th><th>Menge</th><th>Betrag</th><th></th></tr></thead>
        <tbody>${v.positionen.map(zeile).join('') || '<tr><td colspan="6"><em>Keine Positionen im Projekt.</em></td></tr>'}</tbody></table></div>
      <div class="kunde-form-actions"><button class="btn btn-ghost btn-sm" data-lex-zu>Abbrechen</button>
        <button class="btn btn-primary btn-sm" data-lex-weiter>Vorschau</button></div>`;
    karte.querySelector('[data-lex-zu]').onclick = () => overlay.remove();
    karte.querySelector('[data-lex-weiter]').onclick = async (ev) => {
      ev.target.disabled = true;
      const auswahl = auswahlLesen(karte);
      const r = await aufruf('projekt_vorschau', { baustelleId, auswahl });
      ev.target.disabled = false;
      if (!r.ok) { alert(r.j.error || 'Vorschau fehlgeschlagen.'); return; }
      const merken = Object.assign({}, auswahl); delete merken.ausgewaehlt; delete merken.reverseCharge13b;
      try { localStorage.setItem(speicherKey(baustelleId), JSON.stringify(merken)); } catch (e) { /* privat */ }
      // § 13b gehört zum Projekt (für alle Nutzer/Geräte) – über den normalen Projekt-Speicherweg.
      const b = ((typeof appData !== 'undefined' && appData.baustellen) || []).find(x => String(x.id) === String(baustelleId));
      if (b && (b.reverseCharge13b === true) !== auswahl.reverseCharge13b && typeof saveData === 'function') {
        b.reverseCharge13b = auswahl.reverseCharge13b;
        saveData();
      }
      vorschauZeigen(karte, overlay, baustelleId, auswahl, r.j, () => auswahlZeigen(karte, overlay, baustelleId, Object.assign({}, v, { auswahl: r.j.auswahl }), belege));
    };
  }

  function auswahlLesen(karte) {
    const opt = k => karte.querySelector(`[data-opt="${k}"]`);
    const auswahl = {
      belegart: (karte.querySelector('input[name="lexBelegart"]:checked') || {}).value || 'angebot',
      ueberschriften: opt('ueberschriften').checked, nurOffene: opt('nurOffene').checked, reverseCharge13b: opt('reverseCharge13b').checked,
      von: opt('von').value, bis: opt('bis').value, einleitung: opt('einleitung').value, schluss: opt('schluss').value,
      ausgewaehlt: { material: [], arbeitszeit: [], pauschale: [] },
    };
    karte.querySelectorAll('select[data-art]').forEach(s => { auswahl[s.dataset.art] = s.value; });
    karte.querySelectorAll('input[data-pos]:checked').forEach(c => {
      const i = c.dataset.pos.indexOf(':');
      auswahl.ausgewaehlt[c.dataset.pos.slice(0, i)].push(c.dataset.pos.slice(i + 1));
    });
    return auswahl;
  }

  function vorschauZeigen(karte, overlay, baustelleId, auswahl, v, zurueck) {
    const steuer = { net: `zzgl. ${zahl((v.lineItems.find(l => l.unitPrice) || { unitPrice: {} }).unitPrice.taxRatePercentage)} % USt`, vatfree: 'steuerfrei (Kleinunternehmer)', constructionService13b: 'Steuerschuldnerschaft des Leistungsempfängers (§ 13b UStG)' }[v.taxType] || v.taxType;
    const zeilen = v.lineItems.map(li => li.type === 'text'
      ? `<tr><td colspan="5"><strong>${h(li.name)}</strong></td></tr>`
      : `<tr><td>${h(li.name)}${li.description ? `<br><small style="white-space:pre-line;color:#666">${h(li.description)}</small>` : ''}</td>
          <td style="text-align:right">${zahl(li.quantity)}</td><td>${h(li.unitName)}</td>
          <td style="text-align:right">${zahl(li.unitPrice.netAmount)} €</td><td style="text-align:right">${euro(li.betrag)} €</td></tr>`).join('');
    karte.innerHTML = `<h3>Vorschau: ${auswahl.belegart === 'angebot' ? 'Angebot' : 'Rechnungsentwurf'} für ${h(v.projekt.kunde || '–')}</h3>
      ${(v.hinweise || []).map(t => `<p class="lex-warn" style="font-size:.82rem">${h(t)}</p>`).join('')}
      <p style="font-size:.82rem;color:#555">${auswahl.belegart === 'rechnung' && v.von ? `Leistungszeitraum ${h(v.von)}${v.bis && v.bis !== v.von ? ' – ' + h(v.bis) : ''} · ` : ''}${h(steuer)}</p>
      <div style="max-height:45vh;overflow:auto"><table class="data-table"><thead><tr><th>Position</th><th>Menge</th><th>Einheit</th><th>Einzelpreis</th><th>Betrag</th></tr></thead>
        <tbody>${zeilen || '<tr><td colspan="5"><em>Keine Positionen ausgewählt.</em></td></tr>'}</tbody></table></div>
      <p style="text-align:right;font-weight:600">Netto ${euro(v.summen.netto)} € · USt ${euro(v.summen.steuer)} € · Brutto ${euro(v.summen.brutto)} €</p>
      <p class="lex-fortschritt" style="font-size:.85rem"></p>
      <div class="kunde-form-actions"><button class="btn btn-ghost btn-sm" data-lex-zurueck>Zurück</button>
        ${zeilen ? '<button class="btn btn-primary btn-sm" data-lex-senden>Übertragen</button>' : ''}</div>`;
    karte.querySelector('[data-lex-zurueck]').onclick = zurueck;
    const senden = karte.querySelector('[data-lex-senden]');
    if (senden) senden.onclick = () => uebertragen(karte, overlay, baustelleId, auswahl, senden, false);
  }

  async function uebertragen(karte, overlay, baustelleId, auswahl, knopf, trotzdem) {
    knopf.disabled = true;
    const status = karte.querySelector('.lex-fortschritt');
    status.textContent = 'Übertrage nach Lexware Office…';
    const r = await aufruf('projekt_senden', { baustelleId, auswahl, trotzdem });
    if (!r.ok) {
      status.textContent = '';
      if (r.j.fehlerCode === 'unklar_beleg' && confirm(r.j.error + '\n\nTrotzdem jetzt senden?')) return uebertragen(karte, overlay, baustelleId, auswahl, knopf, true);
      alert(r.j.error || 'Übertragung fehlgeschlagen.');
      knopf.disabled = false;
      return;
    }
    karte.innerHTML = `<h3>Übertragen ✓</h3>
      <p>${r.j.belegart === 'angebot' ? 'Das Angebot' : 'Der Rechnungsentwurf'} wurde in Lexware Office angelegt (Netto ${euro(r.j.summen.netto)} €).
      Bitte dort prüfen und fertigstellen.</p>
      <div class="kunde-form-actions"><button class="btn btn-ghost btn-sm" data-lex-zu>Schließen</button>
        <a class="btn btn-primary btn-sm" href="${h(r.j.link)}" target="_blank" rel="noopener">In Lexware öffnen</a></div>`;
    karte.querySelector('[data-lex-zu]').onclick = () => overlay.remove();
    // Abschlagsliste/Summen des Projekts mit dem neuen Beleg neu laden
    delete belegeCache[baustelleId];
    lexofficeSummenLaden(true);
    neuZeichnen();
  }

  // ── Projekt: gespiegelte Lexware-Belege in der Abschlagsliste (B5) ───
  const belegeCache = {};
  const BELEG_ART = { quotation: 'Angebot', invoice: 'Rechnung', downpaymentinvoice: 'Abschlagsrechnung' };
  const BELEG_STATUS = { draft: ['Entwurf', '#ECEFF1', '#455A64'], open: ['offen', '#FFF3E0', '#E65100'], paid: ['bezahlt', '#E8F5E9', '#2E7D32'],
    paidoff: ['bezahlt', '#E8F5E9', '#2E7D32'], voided: ['storniert', '#FFEBEE', '#C62828'], geloescht: ['in Lexware gelöscht', '#FFEBEE', '#C62828'],
    accepted: ['angenommen', '#E8F5E9', '#2E7D32'], rejected: ['abgelehnt', '#FFEBEE', '#C62828'], '': ['noch nicht abgeglichen', '#ECEFF1', '#455A64'] };
  const datumDe = d => (d && d.length >= 10) ? d.slice(8, 10) + '.' + d.slice(5, 7) + '.' + d.slice(0, 4) : '';

  function projektAktiv() {
    return typeof bkRechnungenInLexware === 'function' && typeof appSettings !== 'undefined' && bkRechnungenInLexware(appSettings)
      && typeof canDo === 'function' && canDo('canLexoffice') && canDo('canSeePrices');
  }

  function neuZeichnen() {
    if (typeof renderAbschlagRows === 'function') renderAbschlagRows();
    if (typeof recalc === 'function') recalc();
  }

  async function belegeLaden(baustelleId, sub) {
    const alt = belegeCache[baustelleId] || { belege: [] };
    belegeCache[baustelleId] = { belege: alt.belege, laedt: true, fehler: '' };
    let r = { ok: false, j: {} };
    try {
      r = await aufruf(sub, { baustelleId });
    } catch (e) {
      r = { ok: false, j: { error: 'Netzwerkfehler.' } };
    } finally {
      // Bei Fehlern den letzten bekannten Stand behalten – Summen sollen nicht „verschwinden“.
      belegeCache[baustelleId] = { belege: r.ok ? (r.j.belege || []) : alt.belege, laedt: false, fehler: r.ok ? '' : (r.j.error || 'Fehler') };
    }
    return r;
  }

  // ── Bezahlte Lexware-Beträge aller Projekte (eine Quelle für alle Summen, B5) ─────
  let summen = {};
  let summenGeladen = false;

  async function lexofficeSummenLaden(neu) {
    if (summenGeladen && !neu) return;
    summenGeladen = true;
    try {
      const r = await aufruf('bezahlt_summen', null, 'GET');
      if (r.ok) {
        summen = r.j.summen || {};
        neuZeichnen();
        // Sichtbare Übersicht/Auswertung ebenfalls mit den Lexware-Beträgen neu aufbauen
        const sichtbar = (id) => { const el = document.getElementById(id); return !!el && el.offsetParent !== null; };
        if (sichtbar('uebersichtView') && typeof renderUebersicht === 'function') renderUebersicht();
        if (sichtbar('auswertungView') && typeof renderAuswertung === 'function') renderAuswertung();
      }
    } catch (e) { summenGeladen = false; }
  }

  function lexofficeBezahlt(projektId) {
    return Number(summen[String(projektId)]) || 0;
  }

  function lexofficeAbschlagZeilen(b, tbody) {
    if (!b || !tbody || !projektAktiv()) return;
    const id = b.id;
    const cache = belegeCache[id];
    if (!cache) { belegeLaden(id, 'projekt_belege').then(neuZeichnen); return; }
    const kopf = document.createElement('tr');
    kopf.innerHTML = `<td colspan="4" class="lex-kopf">🧾 <strong>Lexware Office</strong>
      <button class="btn btn-secondary btn-sm" data-lex-abgleich>Mit Lexware abgleichen</button>
      <button class="btn btn-secondary btn-sm" data-lex-abschlag>Abschlag in Lexware erstellen</button>
      ${cache.laedt ? '<em>lädt…</em>' : ''}${cache.fehler ? `<span class="lex-warn">${h(cache.fehler)}</span>` : ''}</td>`;
    tbody.appendChild(kopf);
    kopf.querySelector('[data-lex-abgleich]').onclick = async (ev) => {
      ev.target.disabled = true;
      const r = await belegeLaden(id, 'projekt_abgleichen');
      if (r.ok && typeof showNotification === 'function') showNotification(`Lexware-Abgleich: ${r.j.geprueft} Belege geprüft, ${r.j.neu} neu gefunden${r.j.vollstaendig === false ? ' – noch nicht vollständig, bitte erneut abgleichen' : ''}.`);
      lexofficeSummenLaden(true);
      neuZeichnen();
    };
    kopf.querySelector('[data-lex-abschlag]').onclick = () => {
      const angebot = [...cache.belege].reverse().find(x => x.belegart === 'quotation' && ['open', 'accepted'].includes(x.status));
      if (!angebot) { alert('Zuerst ein Angebot übertragen, in Lexware Office fertigstellen und hier „Mit Lexware abgleichen“. Abschlagsrechnungen erzeugt Lexware aus dem fertiggestellten Angebot.'); return; }
      window.open(angebot.link, '_blank', 'noopener');
    };
    for (const x of cache.belege) {
      const art = x.belegart === 'invoice' && x.schlussrechnung ? 'Schlussrechnung' : (x.belegart === 'invoice' && x.status === 'draft' ? 'Rechnungsentwurf' : (BELEG_ART[x.belegart] || x.belegart));
      const [text, bg, fg] = BELEG_STATUS[x.status] || [x.status, '#ECEFF1', '#455A64'];
      const zaehlt = ['invoice', 'downpaymentinvoice'].includes(x.belegart) && ['paid', 'paidoff'].includes(x.status);
      const tr = document.createElement('tr');
      tr.style.background = zaehlt ? '#E8F5E9' : '#F5F7FA';
      tr.innerHTML = `<td><span style="font-weight:600">${h(x.nummer || 'Entwurf')}</span>
          <span class="lex-badge" style="background:${bg};color:${fg}">${h(text)}${x.bezahltAm ? ' am ' + h(datumDe(x.bezahltAm)) : ''}</span>
          <span style="font-size:.72rem;color:#888">Lexware${x.datum ? ' · ' + h(datumDe(x.datum)) : ''}</span></td>
        <td style="font-size:.75rem;color:var(--text-muted)">${h(art)}</td>
        <td class="price-sensitive text-right" style="${zaehlt ? 'font-weight:600' : 'color:#888'}">${typeof fmt === 'function' ? fmt(x.brutto) : euro(x.brutto)}</td>
        <td><a class="btn btn-secondary btn-sm" style="font-size:.7rem;padding:2px 6px" href="${h(x.link)}" target="_blank" rel="noopener">öffnen</a></td>`;
      tbody.appendChild(tr);
    }
  }

  global.lexofficeKundeBadge = lexofficeKundeBadge;
  global.lexofficeKundenAbgleichOeffnen = lexofficeKundenAbgleichOeffnen;
  global.lexofficeProjektUebertragen = lexofficeProjektUebertragen;
  global.lexofficeAbschlagZeilen = lexofficeAbschlagZeilen;
  global.lexofficeSummenLaden = lexofficeSummenLaden;
  global.lexofficeBezahlt = lexofficeBezahlt;
})(window);
