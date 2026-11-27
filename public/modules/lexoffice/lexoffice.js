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

  global.lexofficeKundeBadge = lexofficeKundeBadge;
  global.lexofficeKundenAbgleichOeffnen = lexofficeKundenAbgleichOeffnen;
})(window);
