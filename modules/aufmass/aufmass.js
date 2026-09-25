// MODULE_VERSION: 53
// ============================================================
// Aufmaß-Modul – Frontend (v2.1, Phase 3B)
// ============================================================
// Liste aller Aufmaße + Editor mit Abschnitten und Positionen.
// Formelfeld parst sicher (+ - * / ( ), Komma als Dezimal).
// Übernahme nach Projektmaterial (mit optionaler Lager-Buchung
// für Positionen mit ref_typ='lager').
// ============================================================

(function () {
  'use strict';

  const state = {
    list: [],
    current: null, // { aufmass, abschnitte, positionen }
    dirty: false,
    saveTimer: null,
    filterBId: 0,
    filterStatus: '',
    q: '',
    ocrAvailable: null, // null = noch nicht geprüft, true/false nach Feature-Check
  };

  // ── Helpers ───────────────────────────────────────────
  function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  }
  function fmtNum(n, dec) {
    if (n === null || n === undefined || n === '') return '';
    const v = Number(n); if (!isFinite(v)) return '';
    if (dec === undefined) dec = (v % 1 === 0) ? 0 : 2;
    return v.toLocaleString('de-DE', { minimumFractionDigits: dec, maximumFractionDigits: dec });
  }
  function fmtEur(n) { if (n === null || n === undefined || n === '') return ''; const v = Number(n); return isFinite(v) ? v.toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' €' : ''; }
  function notify(m) { if (typeof window.showNotification === 'function') window.showNotification(m); else console.log('[Aufmaß]', m); }

  // ── Sicherer Formel-Parser ────────────────────────────
  // Erlaubt: Ziffern, Komma (Dezimal), Punkt, + - * / ( ), Whitespace.
  // Wertet via Function-Konstruktor aus, NACHDEM der String streng
  // sanitisiert wurde. Kein eval. Komma → Punkt.
  function parseFormel(input) {
    if (input === null || input === undefined) return { ok: false, error: 'Leer' };
    const raw = String(input).trim();
    if (raw === '') return { ok: false, error: 'Leer' };
    // Komma → Punkt
    let s = raw.replace(/,/g, '.');
    // Whitelist: nur erlaubte Zeichen
    if (!/^[\d.+\-*/() \t]+$/.test(s)) return { ok: false, error: 'Ungültige Zeichen' };
    // Klammer-Balance
    let bal = 0;
    for (const c of s) { if (c === '(') bal++; else if (c === ')') bal--; if (bal < 0) return { ok:false, error:'Klammern' }; }
    if (bal !== 0) return { ok: false, error: 'Klammern' };
    try {
      // Function-Konstruktor mit "use strict" und ausschließlich whitelisted Input
      // eslint-disable-next-line no-new-func
      const v = (new Function('"use strict"; return (' + s + ');'))();
      if (typeof v !== 'number' || !isFinite(v)) return { ok: false, error: 'Kein Zahlenergebnis' };
      return { ok: true, value: v };
    } catch (e) {
      return { ok: false, error: 'Syntax' };
    }
  }

  function hideAllOtherViews() {
    const ids = ['emptyState','baustelleDetail','materialKatalogView','stundenKatalogView',
      'offenesMaterialView','whatsappView','schnellnotizenView','kundenstammView',
      'dienstleisterView','allgemeinSettingsView','rechnungenView','auswertungView',
      'uebersichtView','din1090View','lagerView','vde0100View','dashboardView'];
    ids.forEach(id => document.getElementById(id)?.classList.add('hidden'));
  }

  // ── API ───────────────────────────────────────────────
  async function api(sub, body, method) {
    method = method || 'POST';
    const url = 'api.php?action=module&module=aufmass&sub=' + encodeURIComponent(sub) + (method === 'GET' && body ? '&' + new URLSearchParams(body).toString() : '');
    const opt = { method };
    if (method === 'POST') {
      opt.headers = { 'Content-Type': 'application/json' };
      opt.body = JSON.stringify(body || {});
    }
    const r = await fetch(url, opt);
    let j; try { j = await r.json(); } catch (e) { j = { error: 'Antwort nicht lesbar' }; }
    if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status));
    return j;
  }

  // ── Show/Hide ─────────────────────────────────────────
  function showAufmass() {
    const view = document.getElementById('aufmassView');
    if (!view) return;
    document.querySelectorAll('.sidebar-overview-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('btnAufmass')?.classList.add('active');
    if (typeof window.selectedId !== 'undefined') window.selectedId = null;
    if (typeof window.renderSidebar === 'function') window.renderSidebar();
    hideAllOtherViews();
    view.classList.remove('hidden');

    if (typeof appSettings !== 'undefined' && appSettings.modul_aufmass === false) {
      view.innerHTML = '<div style="padding:20px"><em>Modul Aufmaß ist deaktiviert.</em></div>';
      return;
    }
    if (typeof canDo === 'function' && !canDo('canReadAufmass')) {
      view.innerHTML = '<div style="padding:20px"><em>Keine Berechtigung.</em></div>';
      return;
    }
    state.current = null;
    renderList();
  }

  function hideAufmass() {
    document.getElementById('aufmassView')?.classList.add('hidden');
    document.getElementById('btnAufmass')?.classList.remove('active');
  }

  // ── Liste ─────────────────────────────────────────────
  async function renderList() {
    const view = document.getElementById('aufmassView');
    if (!view) return;
    const canWrite = typeof canDo !== 'function' || canDo('canWriteAufmass');
    view.innerHTML = `
      <div class="aufmass-wrap">
        <div class="aufmass-header">
          <h2 style="margin:0;display:flex;align-items:center;gap:8px">📏 Aufmaß</h2>
          <div class="aufmass-actions">
            ${canWrite ? '<button class="btn btn-primary" onclick="window.aufmassModul.openEditor()">+ Neues Aufmaß</button>' : ''}
            <button class="btn btn-ghost" onclick="window.aufmassModul.refresh()">⟳ Aktualisieren</button>
          </div>
        </div>
        <div class="aufmass-search">
          <input type="text" id="afSearch" class="form-control" placeholder="Suchen (Titel, Baustelle) …" autocomplete="off">
          <select id="afStatus" class="form-control">
            <option value="">Alle Status</option>
            <option value="entwurf">Entwurf</option>
            <option value="geprueft">Geprüft</option>
            <option value="uebernommen">Übernommen</option>
          </select>
          <select id="afBaustelle" class="form-control"><option value="0">Alle Baustellen</option></select>
        </div>
        <div id="aufmassListContainer"></div>
      </div>
    `;
    // Baustellen-Filter befüllen
    const sel = document.getElementById('afBaustelle');
    (appData?.baustellen || []).slice().sort((a,b)=>a.name.localeCompare(b.name,'de')).forEach(b => {
      const o = document.createElement('option'); o.value = b.id; o.textContent = b.name; sel.appendChild(o);
    });
    document.getElementById('afSearch').addEventListener('input', e => { state.q = e.target.value.trim().toLowerCase(); renderListRows(); });
    document.getElementById('afStatus').addEventListener('change', e => { state.filterStatus = e.target.value; renderListRows(); });
    document.getElementById('afBaustelle').addEventListener('change', e => { state.filterBId = parseInt(e.target.value,10) || 0; renderListRows(); });

    await refresh();
  }

  async function refresh() {
    try {
      const j = await api('list', null, 'GET');
      state.list = j.aufmasse || [];
    } catch (e) { notify('Liste konnte nicht geladen werden: ' + e.message); state.list = []; }
    renderListRows();
  }

  function renderListRows() {
    const c = document.getElementById('aufmassListContainer');
    if (!c) return;
    let rows = state.list.slice();
    if (state.filterStatus) rows = rows.filter(r => r.status === state.filterStatus);
    if (state.filterBId) rows = rows.filter(r => r.baustelle_id === state.filterBId);
    if (state.q) {
      const t = state.q;
      const bMap = {};
      (appData?.baustellen || []).forEach(b => { bMap[b.id] = (b.name || '').toLowerCase(); });
      rows = rows.filter(r => (r.titel || '').toLowerCase().includes(t) || (bMap[r.baustelle_id] || '').includes(t));
    }
    if (!rows.length) { c.innerHTML = '<div class="aufmass-empty"><em>Keine Aufmaße vorhanden.</em></div>'; return; }

    const bMap = {};
    (appData?.baustellen || []).forEach(b => { bMap[b.id] = b.name; });

    c.innerHTML = `<div class="aufmass-cards">${rows.map(r => {
      const statusClass = 'aufmass-status-' + r.status;
      const statusLabel = r.status === 'entwurf' ? 'Entwurf' : r.status === 'geprueft' ? 'Geprüft' : 'Übernommen';
      const bName = r.baustelle_id ? (bMap[r.baustelle_id] || '–') : '–';
      return `<div class="aufmass-card" onclick="window.aufmassModul.openEditor(${r.id})">
        <div class="aufmass-card-head">
          <strong>${esc(r.titel || '(ohne Titel)')}</strong>
          <span class="aufmass-pill ${statusClass}">${statusLabel}</span>
        </div>
        <div class="aufmass-card-meta">
          🏗 ${esc(bName)} · ${r.anzahl_positionen} Position(en)
          ${r.summe > 0 ? ' · Σ ' + fmtEur(r.summe) : ''}
        </div>
        <div class="aufmass-card-meta" style="font-size:.72rem;color:#999">
          erstellt ${esc(r.erstellt_am || '')}${r.erstellt_von ? ' von ' + esc(r.erstellt_von) : ''}
          ${r.uebernommen_am ? ' · übernommen ' + esc(r.uebernommen_am) : ''}
        </div>
      </div>`;
    }).join('')}</div>`;
  }

  // ── Editor ────────────────────────────────────────────
  async function openEditor(id) {
    const view = document.getElementById('aufmassView');
    if (!view) return;
    if (id) {
      try {
        const j = await api('load', { id }, 'GET');
        state.current = { aufmass: j.aufmass, abschnitte: j.abschnitte || [], positionen: j.positionen || [] };
      } catch (e) { notify('Laden fehlgeschlagen: ' + e.message); return; }
    } else {
      state.current = {
        aufmass: { id: 0, titel: '', baustelle_id: null, status: 'entwurf', notiz: '' },
        abschnitte: [],
        positionen: [],
      };
    }
    state.dirty = false;
    state._tmpId = -1;
    renderEditor();
  }

  function _nextTmpId() { return state._tmpId--; }

  function renderEditor() {
    const view = document.getElementById('aufmassView');
    if (!view) return;
    const a = state.current.aufmass;
    const locked = a.status === 'uebernommen';
    const canWrite = (typeof canDo !== 'function' || canDo('canWriteAufmass')) && !locked;
    const canApprove = typeof canDo !== 'function' || canDo('canApproveAufmass');
    const baustellenOpt = '<option value="">– keine Zuordnung –</option>' +
      (appData?.baustellen || []).slice().sort((x,y)=>x.name.localeCompare(y.name,'de'))
        .map(b => `<option value="${b.id}" ${a.baustelle_id === b.id ? 'selected' : ''}>${esc(b.name)}</option>`).join('');

    const statusLabel = a.status === 'entwurf' ? 'Entwurf' : a.status === 'geprueft' ? 'Geprüft' : 'Übernommen';

    view.innerHTML = `
      <div class="aufmass-wrap">
        <div class="aufmass-header">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <button class="btn btn-ghost" onclick="window.aufmassModul.backToList()">← Zurück</button>
            <h2 style="margin:0">📏 ${a.id ? 'Aufmaß bearbeiten' : 'Neues Aufmaß'}</h2>
            <span class="aufmass-pill aufmass-status-${a.status}">${statusLabel}</span>
          </div>
          <div class="aufmass-actions">
            ${canWrite ? '<button class="btn btn-primary" onclick="window.aufmassModul.saveCurrent()">💾 Speichern</button>' : ''}
            ${a.id && a.status === 'entwurf' && canApprove ? '<button class="btn btn-secondary" onclick="window.aufmassModul.setStatus(\'geprueft\')">✓ Prüfen</button>' : ''}
            ${a.id && a.status === 'geprueft' && canWrite ? '<button class="btn btn-ghost" onclick="window.aufmassModul.setStatus(\'entwurf\')">↺ Zurück auf Entwurf</button>' : ''}
            ${a.id && a.status !== 'uebernommen' && canApprove ? '<button class="btn btn-primary" style="background:#2E7D32" onclick="window.aufmassModul.uebernehmen()">→ Ins Projekt übernehmen</button>' : ''}
            ${a.id && !locked && canWrite ? '<button class="btn btn-ghost" style="color:#C0392B" onclick="window.aufmassModul.deleteCurrent()">🗑 Löschen</button>' : ''}
          </div>
        </div>

        ${locked ? `<div class="aufmass-banner">
          🔒 Dieses Aufmaß wurde am ${esc(a.uebernommen_am || '')} ins Projekt übernommen und ist gesperrt.
        </div>` : ''}

        <div class="aufmass-form-grid">
          <label class="full"><span>Titel *</span><input type="text" id="afTitel" class="form-control" value="${esc(a.titel || '')}" maxlength="200" ${locked ? 'disabled' : ''} oninput="window.aufmassModul._dirty()"></label>
          <label><span>Baustelle</span><select id="afBId" class="form-control" ${locked ? 'disabled' : ''} onchange="window.aufmassModul._dirty()">${baustellenOpt}</select></label>
          <label class="full"><span>Notiz</span><textarea id="afNotiz" class="form-control" rows="2" ${locked ? 'disabled' : ''} oninput="window.aufmassModul._dirty()">${esc(a.notiz || '')}</textarea></label>
        </div>

        <div class="aufmass-section">
          <div class="aufmass-section-head">
            <h3 style="margin:0">Abschnitte</h3>
            ${canWrite ? '<button class="btn btn-secondary btn-sm" onclick="window.aufmassModul.addAbschnitt()">+ Abschnitt</button>' : ''}
          </div>
          <div id="afAbschnitteList"></div>
        </div>

        <div class="aufmass-section">
          <div class="aufmass-section-head">
            <h3 style="margin:0">Positionen</h3>
            ${canWrite ? `<div class="aufmass-pos-actions">
              <button class="btn btn-ghost btn-sm" onclick="window.aufmassModul._openKatalogPickerAm()">&#x1F50D; Aus Katalog</button>
              <button class="btn btn-ghost btn-sm aufmass-ocr-btn" ${state.ocrAvailable === false ? 'disabled title="OCR nicht verf\u00fcgbar"' : ''} onclick="window.aufmassModul._openFotoScanAm()">&#x1F4F7; Foto</button>
              <button class="btn btn-primary btn-sm" onclick="window.aufmassModul.addPosition()">+ Position</button>
            </div>` : ''}
          </div>
          <div id="afPositionenList"></div>
        </div>
      </div>
    `;

    renderAbschnitte();
    renderPositionen();
    // OCR-Verfügbarkeit beim ersten Editor-Aufruf asynchron prüfen
    if (state.ocrAvailable === null) _checkOcrAvailableAm();
  }

  function renderAbschnitte() {
    const c = document.getElementById('afAbschnitteList');
    if (!c) return;
    const cur = state.current;
    const locked = cur.aufmass.status === 'uebernommen';
    if (!cur.abschnitte.length) { c.innerHTML = '<div style="color:#888;font-size:.85rem;padding:6px 4px"><em>Optional. Positionen können auch ohne Abschnitt erfasst werden.</em></div>'; return; }
    c.innerHTML = cur.abschnitte.map((a, idx) => `
      <div class="aufmass-abschnitt-row">
        <span class="aufmass-abs-handle">≡</span>
        <input type="text" class="form-control" value="${esc(a.name || '')}" placeholder="Abschnittsname" ${locked ? 'disabled' : ''} oninput="window.aufmassModul._setAbsName(${idx}, this.value)">
        <span style="font-size:.78rem;color:#888">${cur.positionen.filter(p => p.abschnitt_id === a.id).length} Pos.</span>
        ${!locked ? `<button class="btn-icon btn-icon-danger" onclick="window.aufmassModul.delAbschnitt(${idx})">🗑</button>` : ''}
      </div>
    `).join('');
  }

  function renderPositionen() {
    const c = document.getElementById('afPositionenList');
    if (!c) return;
    const cur = state.current;
    const locked = cur.aufmass.status === 'uebernommen';
    const canSeeEk = typeof canDo !== 'function' || canDo('canSeePrices');

    if (!cur.positionen.length) {
      c.innerHTML = '<div class="aufmass-empty"><em>Noch keine Positionen.</em></div>';
      return;
    }

    // ── Mobile: Card-Layout ──────────────────────────────────
    if (window.innerWidth <= 720) {
      _renderPositionenCards(c, cur, locked, canSeeEk);
      return;
    }

    const abschnittOpts = (selId) => '<option value="">– kein Abschnitt –</option>' +
      cur.abschnitte.map(a => `<option value="${a.id}" ${selId === a.id ? 'selected' : ''}>${esc(a.name || '(unbenannt)')}</option>`).join('');

    c.innerHTML = `<table class="aufmass-pos-table">
      <thead><tr>
        <th style="width:32px"></th>
        <th>Bezeichnung / Notiz</th>
        <th style="width:160px">Formel → Menge</th>
        <th style="width:80px">Einheit</th>
        ${canSeeEk ? '<th style="width:100px">Einzel €</th>' : ''}
        <th style="width:160px">Abschnitt</th>
        <th style="width:90px">Referenz</th>
        ${!locked ? '<th style="width:80px"></th>' : ''}
      </tr></thead>
      <tbody>${cur.positionen.map((p, idx) => `
        <tr>
          <td><input type="checkbox" ${p.ausgewaehlt ? 'checked' : ''} ${locked ? 'disabled' : ''} onchange="window.aufmassModul._setPos(${idx}, 'ausgewaehlt', this.checked ? 1 : 0)"></td>
          <td>
            <input type="text" class="form-control" value="${esc(p.bezeichnung || '')}" placeholder="Bezeichnung" ${locked || p.ref_typ ? '' : ''} ${locked ? 'disabled' : ''} oninput="window.aufmassModul._setPos(${idx},'bezeichnung',this.value)">
            ${p.ref_typ ? `<div class="aufmass-ref-pill">${esc(p.ref_typ)}${p.ref_id ? ' #' + esc(p.ref_id) : ''}${!locked ? ' <a onclick="event.stopPropagation();window.aufmassModul._clearRef(' + idx + ')" style=\"cursor:pointer;color:#C0392B;margin-left:6px\">×</a>' : ''}</div>` : ''}
            ${p.notiz ? `<div style=\"font-size:.72rem;color:#888;margin-top:2px\">${esc(p.notiz)}</div>` : ''}
          </td>
          <td>
            <input type="text" class="form-control aufmass-formel" value="${esc(p.formel || '')}" placeholder="z. B. 2,5*1,2*3" ${locked ? 'disabled' : ''} oninput="window.aufmassModul._setFormel(${idx}, this.value)">
            <div class="aufmass-menge-out" id="afMenge_${idx}">= ${fmtNum(p.menge)}</div>
          </td>
          <td><input type="text" class="form-control" value="${esc(p.einheit || '')}" placeholder="Stk., m, m²" ${locked ? 'disabled' : ''} oninput="window.aufmassModul._setPos(${idx},'einheit',this.value)"></td>
          ${canSeeEk ? `<td><input type="text" class="form-control text-right" value="${p.einzelpreis !== null && p.einzelpreis !== undefined ? fmtNum(p.einzelpreis,2) : ''}" inputmode="decimal" ${locked ? 'disabled' : ''} oninput="window.aufmassModul._setPos(${idx},'einzelpreis',this.value === '' ? null : parseFloat(this.value.replace(',','.')) || 0)"></td>` : ''}
          <td><select class="form-control" ${locked ? 'disabled' : ''} onchange="window.aufmassModul._setPos(${idx},'abschnitt_id',this.value === '' ? null : parseInt(this.value,10))">${abschnittOpts(p.abschnitt_id)}</select></td>
          <td>
            ${!locked ? `<button class="btn-icon" title="Aus Lager zuweisen" onclick="window.aufmassModul.assignFromLager(${idx})">📦</button>` : ''}
          </td>
          ${!locked ? `<td><button class="btn-icon btn-icon-danger" onclick="window.aufmassModul.delPosition(${idx})">🗑</button></td>` : ''}
        </tr>
      `).join('')}</tbody>
      <tfoot>
        <tr><td colspan="${canSeeEk ? 8 : 7}" style="text-align:right;font-weight:600;padding-top:8px">
          Summe ausgewählter Positionen: ${fmtEur(_sum())}
        </td></tr>
      </tfoot>
    </table>`;
  }

  // ── Mobile Positions-Cards ────────────────────────────
  function _renderPositionenCards(c, cur, locked, canSeeEk) {
    const abschnittOpts = (selId) => '<option value="">– kein Abschnitt –</option>' +
      cur.abschnitte.map(a => `<option value="${a.id}" ${selId === a.id ? 'selected' : ''}>${esc(a.name || '(unbenannt)')}</option>`).join('');

    const cards = cur.positionen.map((p, idx) => {
      const refBadge = p.ref_typ
        ? `<div class="aufmass-ref-pill">${esc(p.ref_typ)}${p.ref_id ? ' #' + esc(p.ref_id) : ''}${!locked ? ` <a onclick="event.stopPropagation();window.aufmassModul._clearRef(${idx})" style="cursor:pointer;color:#C0392B;margin-left:6px">×</a>` : ''}</div>`
        : '';
      return `<div class="aufmass-pos-card${p.ausgewaehlt ? ' aufmass-pos-card-sel' : ''}">
        <div class="aufmass-pos-card-head">
          <label class="aufmass-pos-chk-label">
            <input type="checkbox" ${p.ausgewaehlt ? 'checked' : ''} ${locked ? 'disabled' : ''} onchange="window.aufmassModul._setPos(${idx},'ausgewaehlt',this.checked?1:0)">
            <input type="text" class="form-control" value="${esc(p.bezeichnung || '')}" placeholder="Bezeichnung" ${locked ? 'disabled' : ''} oninput="window.aufmassModul._setPos(${idx},'bezeichnung',this.value)">
          </label>
          ${!locked ? `<button class="btn-icon btn-icon-danger aufmass-pos-del" onclick="window.aufmassModul.delPosition(${idx})">🗑</button>` : ''}
        </div>
        ${refBadge}
        <div class="aufmass-pos-card-fields">
          <label class="aufmass-pos-field">
            <span>Formel</span>
            <input type="text" class="form-control aufmass-formel" value="${esc(p.formel || '')}" placeholder="z. B. 2,5 * 1,2" inputmode="decimal" ${locked ? 'disabled' : ''} oninput="window.aufmassModul._setFormel(${idx},this.value)">
            <div class="aufmass-menge-out" id="afMenge_${idx}">= ${fmtNum(p.menge)}</div>
          </label>
          <label class="aufmass-pos-field aufmass-pos-field-sm">
            <span>Einheit</span>
            <input type="text" class="form-control" value="${esc(p.einheit || '')}" placeholder="Stk." ${locked ? 'disabled' : ''} oninput="window.aufmassModul._setPos(${idx},'einheit',this.value)">
          </label>
          ${canSeeEk ? `<label class="aufmass-pos-field aufmass-pos-field-sm">
            <span>Einzel €</span>
            <input type="text" class="form-control" value="${p.einzelpreis !== null && p.einzelpreis !== undefined ? fmtNum(p.einzelpreis,2) : ''}" inputmode="decimal" ${locked ? 'disabled' : ''} oninput="window.aufmassModul._setPos(${idx},'einzelpreis',this.value===''?null:parseFloat(this.value.replace(',','.'))||0)">
          </label>` : ''}
        </div>
        <div class="aufmass-pos-card-footer">
          ${cur.abschnitte.length ? `<select class="form-control" ${locked ? 'disabled' : ''} onchange="window.aufmassModul._setPos(${idx},'abschnitt_id',this.value===''?null:parseInt(this.value,10))">${abschnittOpts(p.abschnitt_id)}</select>` : ''}
          ${!locked ? `<button class="btn-icon aufmass-pos-lager-btn" title="Aus Lager zuweisen" onclick="window.aufmassModul.assignFromLager(${idx})">📦 Lager</button>` : ''}
        </div>
      </div>`;
    }).join('');

    const summe = canSeeEk ? `<div class="aufmass-pos-cards-sum">Σ ausgewählt: <strong>${fmtEur(_sum())}</strong></div>` : '';
    c.innerHTML = `<div class="aufmass-pos-cards">${cards}${summe}</div>`;
  }

  function _sum() {
    return state.current.positionen
      .filter(p => p.ausgewaehlt)
      .reduce((s,p) => s + (Number(p.menge) || 0) * (Number(p.einzelpreis) || 0), 0);
  }

  // ── Mutations ─────────────────────────────────────────
  function _dirty() { state.dirty = true; }

  function backToList() {
    if (state.dirty && !confirm('Ungespeicherte Änderungen verwerfen?')) return;
    state.dirty = false;
    state.current = null;
    showAufmass();
  }

  function addAbschnitt() {
    state.current.abschnitte.push({ id: _nextTmpId(), name: '', sortier: state.current.abschnitte.length });
    state.dirty = true;
    renderAbschnitte();
    renderPositionen();
  }
  function delAbschnitt(idx) {
    const a = state.current.abschnitte[idx];
    if (!a) return;
    // Positionen zuvor lösen
    state.current.positionen.forEach(p => { if (p.abschnitt_id === a.id) p.abschnitt_id = null; });
    state.current.abschnitte.splice(idx, 1);
    state.dirty = true;
    renderAbschnitte(); renderPositionen();
  }
  function _setAbsName(idx, val) {
    if (state.current.abschnitte[idx]) { state.current.abschnitte[idx].name = val; state.dirty = true; }
  }

  function addPosition() {
    state.current.positionen.push({
      id: _nextTmpId(), abschnitt_id: null, bezeichnung: '', formel: '',
      menge: 0, einheit: 'Stk.', einzelpreis: null, ek: null,
      ref_typ: null, ref_id: null, ausgewaehlt: 1,
      sortier: state.current.positionen.length, notiz: '',
    });
    state.dirty = true;
    renderPositionen();
  }
  function delPosition(idx) {
    state.current.positionen.splice(idx, 1);
    state.dirty = true;
    renderPositionen();
  }
  function _setPos(idx, field, val) {
    const p = state.current.positionen[idx]; if (!p) return;
    p[field] = val; state.dirty = true;
    if (field === 'ausgewaehlt') renderPositionen();
  }
  function _setFormel(idx, val) {
    const p = state.current.positionen[idx]; if (!p) return;
    p.formel = val;
    const out = document.getElementById('afMenge_' + idx);
    if (val.trim() === '') {
      // leer: keine Auswertung, Menge unverändert lassen? → 0 setzen
      p.menge = 0;
      if (out) { out.textContent = '= 0'; out.classList.remove('aufmass-menge-err'); }
    } else {
      const r = parseFormel(val);
      if (r.ok) {
        p.menge = Math.round(r.value * 1000) / 1000;
        if (out) { out.textContent = '= ' + fmtNum(p.menge); out.classList.remove('aufmass-menge-err'); }
      } else {
        if (out) { out.textContent = '⚠ ' + r.error; out.classList.add('aufmass-menge-err'); }
      }
    }
    state.dirty = true;
    // Summe live aktualisieren
    const tf = document.querySelector('.aufmass-pos-table tfoot td');
    if (tf) tf.innerHTML = 'Summe ausgewählter Positionen: ' + fmtEur(_sum());
  }
  function _clearRef(idx) {
    const p = state.current.positionen[idx]; if (!p) return;
    p.ref_typ = null; p.ref_id = null; state.dirty = true;
    renderPositionen();
  }

  async function assignFromLager(idx) {
    if (!window.lagerModul) { notify('Lager-Modul nicht aktiv.'); return; }
    const r = await window.lagerModul.pickAndConsumeForProject._noConsume
      ? window.lagerModul.pickAndConsumeForProject._noConsume()
      : await _pickFromLagerNoConsume();
    if (!r) return;
    const p = state.current.positionen[idx]; if (!p) return;
    p.ref_typ = 'lager';
    p.ref_id = String(r.artikelId);
    p.bezeichnung = r.bezeichnung + (r.artikelnr ? ' (Art. ' + r.artikelnr + ')' : '');
    p.einheit = r.einheit || p.einheit;
    if (r.ek_preis !== null && r.ek_preis !== undefined && p.einzelpreis === null) p.einzelpreis = r.ek_preis;
    state.dirty = true;
    renderPositionen();
  }

  // Picker ohne Buchung (nutzt Lager-Modul-Daten direkt)
  async function _pickFromLagerNoConsume() {
    if (!window.lagerModul) return null;
    return new Promise((resolve) => {
      // Lager-Daten frisch laden
      window.lagerModul.refreshAll().then(() => {
        const articles = window.lagerModul._state.artikel || [];
        const ov = document.createElement('div');
        ov.className = 'lager-modal-overlay';
        ov.innerHTML = `
          <div class="lager-modal lager-modal-wide">
            <h3 style="margin-top:0">Aus Lager zuweisen</h3>
            <div class="lager-search" style="margin-bottom:8px">
              <input type="text" id="afpSearch" class="form-control" placeholder="Suchen …" autofocus>
            </div>
            <div id="afpResults" class="lager-pick-results"></div>
            <div class="lager-modal-actions">
              <button class="btn btn-ghost" id="afpCancel">Abbrechen</button>
            </div>
          </div>
        `;
        ov.addEventListener('click', e => { if (e.target === ov) cleanup(null); });
        document.body.appendChild(ov);
        const cleanup = (val) => { ov.remove(); resolve(val); };
        ov.querySelector('#afpCancel').onclick = () => cleanup(null);
        const ren = () => {
          const q = ov.querySelector('#afpSearch').value.trim().toLowerCase();
          const list = articles.filter(a => {
            if (!q) return true;
            const tokens = q.split(/\s+/);
            return tokens.every(t => (a.bezeichnung||'').toLowerCase().includes(t) || (a.artikelnr||'').toLowerCase().includes(t) || (a.kategorie||'').toLowerCase().includes(t));
          });
          const c = ov.querySelector('#afpResults');
          if (!list.length) { c.innerHTML = '<em>Keine Treffer.</em>'; return; }
          c.innerHTML = list.map(a => `
            <div class="lager-pick-row">
              <div>
                <div class="lager-bez">${esc(a.bezeichnung)}</div>
                <div class="lager-kat">${esc(a.artikelnr || '')}${a.lagerort_name ? ' · ' + esc(a.lagerort_name) : ''}</div>
              </div>
              <div class="num">${fmtNum(a.menge)} ${esc(a.einheit || '')}</div>
              <button class="btn btn-primary" data-id="${a.id}">Wählen</button>
            </div>
          `).join('');
          c.querySelectorAll('button[data-id]').forEach(btn => {
            btn.addEventListener('click', () => {
              const id = parseInt(btn.dataset.id, 10);
              const art = articles.find(x => x.id === id);
              if (art) cleanup({ artikelId: art.id, bezeichnung: art.bezeichnung, artikelnr: art.artikelnr, einheit: art.einheit, ek_preis: art.ek_preis });
            });
          });
        };
        ov.querySelector('#afpSearch').addEventListener('input', ren);
        ren();
      });
    });
  }

  // ── Save / Status / Delete / Übernahme ────────────────
  async function saveCurrent() {
    const a = state.current.aufmass;
    a.titel = document.getElementById('afTitel')?.value.trim() || a.titel;
    const bId = document.getElementById('afBId')?.value;
    a.baustelle_id = bId === '' ? null : parseInt(bId, 10);
    a.notiz = document.getElementById('afNotiz')?.value || '';
    if (!a.titel) { notify('Titel erforderlich.'); return; }

    const body = {
      id: a.id,
      titel: a.titel,
      baustelle_id: a.baustelle_id,
      notiz: a.notiz,
      abschnitte: state.current.abschnitte.map((x,i) => ({ id: x.id, name: x.name, sortier: i })),
      positionen: state.current.positionen.map((x,i) => ({
        id: x.id > 0 ? x.id : 0,
        abschnitt_id: x.abschnitt_id,
        bezeichnung: x.bezeichnung || '',
        formel: x.formel || '',
        menge: x.menge || 0,
        einheit: x.einheit || '',
        einzelpreis: x.einzelpreis,
        ek: x.ek,
        ref_typ: x.ref_typ,
        ref_id: x.ref_id,
        ausgewaehlt: x.ausgewaehlt ? 1 : 0,
        sortier: i,
        notiz: x.notiz || '',
      })),
    };
    try {
      const r = await api('save', body);
      state.current.aufmass.id = r.id;
      state.dirty = false;
      notify('Aufmaß gespeichert.');
      // Reload um IDs zu synchronisieren
      const j = await api('load', { id: r.id }, 'GET');
      state.current = { aufmass: j.aufmass, abschnitte: j.abschnitte || [], positionen: j.positionen || [] };
      renderEditor();
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  async function setStatus(newStatus) {
    if (state.dirty && !confirm('Ungespeicherte Änderungen vor Statuswechsel speichern? OK = Speichern, Abbrechen = abbrechen.')) return;
    if (state.dirty) await saveCurrent();
    try {
      await api('status', { id: state.current.aufmass.id, status: newStatus });
      notify('Status: ' + newStatus);
      const j = await api('load', { id: state.current.aufmass.id }, 'GET');
      state.current = { aufmass: j.aufmass, abschnitte: j.abschnitte || [], positionen: j.positionen || [] };
      renderEditor();
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  async function deleteCurrent() {
    if (!state.current.aufmass.id) { backToList(); return; }
    if (!confirm('Aufmaß wirklich löschen?')) return;
    try {
      await api('delete', { id: state.current.aufmass.id });
      notify('Gelöscht.');
      backToList();
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  async function uebernehmen() {
    if (state.dirty) await saveCurrent();
    const a = state.current.aufmass;
    if (!a.id) { notify('Erst speichern.'); return; }
    if (!a.baustelle_id) { notify('Aufmaß muss einer Baustelle zugeordnet sein.'); return; }
    const sel = state.current.positionen.filter(p => p.ausgewaehlt && p.menge > 0);
    if (!sel.length) { notify('Keine ausgewählten Positionen mit Menge.'); return; }
    if (!confirm(`${sel.length} Position(en) ins Projekt übernehmen?\nDas Aufmaß wird danach gesperrt.`)) return;

    try {
      const r = await api('uebernehmen', { id: a.id, positionIds: sel.filter(p => p.id > 0).map(p => p.id) });
      // Material in Baustelle einfügen
      const b = (appData?.baustellen || []).find(x => x.id === r.baustelle_id);
      if (b) {
        (r.positionen || []).forEach(p => {
          b.material.unshift({
            id: b.nextMatId++,
            bezeichnung: p.bezeichnung || '(Aufmaß)',
            anzahl: Number(p.menge) || 0,
            einheit: p.einheit || 'Stk.',
            ek: Number(p.ek) || Number(p.einzelpreis) || 0,
            aufschlag: 0,
            kategorieId: null,
            erstelltVon: window.currentKuerzel || '',
            datum: new Date().toISOString().split('T')[0],
            quelleAufmassId: a.id,
            quelleAufmassPosId: p.id,
          });
        });
        if (typeof window.saveData === 'function') window.saveData();
      }
      if (r.lager_warnungen && r.lager_warnungen.length) {
        notify('Übernommen. Hinweise:\n' + r.lager_warnungen.join('\n'));
      } else {
        notify('Aufmaß übernommen. ' + (r.positionen || []).length + ' Position(en) ins Projekt eingefügt.');
      }
      // reload + zurück
      const j = await api('load', { id: a.id }, 'GET');
      state.current = { aufmass: j.aufmass, abschnitte: j.abschnitte || [], positionen: j.positionen || [] };
      renderEditor();
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  // ── Katalog-Picker & Foto-OCR (Aufmaß) ──────────────────────

  /** Feature-Probe: Ist Tesseract verfügbar? */
  async function _checkOcrAvailableAm() {
    try {
      const fd = new FormData();
      const r = await fetch('api.php?action=module&module=aufmass&sub=foto_ocr', { method: 'POST', body: fd });
      if (r.status === 400) {
        state.ocrAvailable = true; // 400 = kein Bild, OCR-Feature aber aktiv
      } else if (r.status === 200) {
        const j = await r.json().catch(() => ({}));
        state.ocrAvailable = !j.hint;
      } else {
        state.ocrAvailable = false;
      }
    } catch (_) {
      state.ocrAvailable = false;
    }
    // OCR-Button ggf. aktivieren ohne Re-Render
    const btn = document.querySelector('.aufmass-ocr-btn[disabled]');
    if (btn && state.ocrAvailable) { btn.removeAttribute('disabled'); btn.title = ''; }
  }

  /** Fügt eine neue Position mit Katalog-Daten ein. */
  function _addPositionFromKatalog(item) {
    if (!state.current) return;
    state.current.positionen.push({
      id: _nextTmpId(),
      abschnitt_id: null,
      bezeichnung: item.bezeichnung || '',
      formel: '',
      menge: 0,
      einheit: item.einheit || 'Stk.',
      einzelpreis: item.ek != null ? item.ek : null,
      ek: item.ek != null ? item.ek : null,
      ref_typ: null,
      ref_id: null,
      ausgewaehlt: 1,
      sortier: state.current.positionen.length,
      notiz: item.artNr ? 'Art.-Nr.: ' + item.artNr : '',
    });
    state.dirty = true;
    renderPositionen();
  }

  /**
   * Katalog-Picker: öffnet Overlay mit Suche über Datanorm + eigene DB.
   * Bei Auswahl wird eine neue Position eingefügt.
   */
  function _openKatalogPickerAm() {
    const ov = document.createElement('div');
    ov.className = 'lager-modal-overlay';
    ov.innerHTML = `
      <div class="lager-modal lager-modal-wide">
        <h3 style="margin-top:0">🔍 Aus Katalog suchen</h3>
        <div class="lager-katalog-search-row" style="margin-bottom:8px">
          <input type="text" id="afKatQ" class="form-control" placeholder="Artikelnummer oder Bezeichnung …" autofocus>
          <button class="btn btn-primary" onclick="window.aufmassModul._lkpAmSearch()">Suchen</button>
        </div>
        <div id="afKatResults" class="lager-pick-results" style="min-height:60px"></div>
        <div class="lager-modal-actions" style="margin-top:8px">
          <button class="btn btn-ghost" id="afKatClose">Schließen</button>
        </div>
      </div>
    `;
    ov.addEventListener('click', e => { if (e.target === ov) ov.remove(); });
    document.body.appendChild(ov);
    ov.querySelector('#afKatClose').onclick = () => ov.remove();
    const inp = ov.querySelector('#afKatQ');
    if (inp) {
      inp.focus();
      inp.addEventListener('keydown', e => { if (e.key === 'Enter') window.aufmassModul._lkpAmSearch(); if (e.key === 'Escape') ov.remove(); });
      inp.addEventListener('input', () => { clearTimeout(state._amKatTimer); state._amKatTimer = setTimeout(() => window.aufmassModul._lkpAmSearch(), 380); });
    }
    state._amKatOv = ov;
  }

  /** Datanorm + eigene DB suchen, Ergebnisse rendern. */
  async function _lkpAmSearch() {
    const inp = document.getElementById('afKatQ');
    const q = inp ? inp.value.trim() : '';
    if (!q) return;
    const box = document.getElementById('afKatResults');
    if (box) box.innerHTML = '<span style="color:var(--text-muted)">Suche …</span>';

    let items = [];
    try {
      const dn = await fetch(`api.php?action=datanorm_search&q=${encodeURIComponent(q)}`).then(r => r.json());
      if (dn.results) items = dn.results.map(x => ({ quelle: 'Datanorm', artNr: x.artNr, bezeichnung: x.bezeichnung, einheit: x.einheit, ek: x.ek, lp: x.lp }));
      if (typeof appData !== 'undefined' && Array.isArray(appData.materialKatalog)) {
        const ql = q.toLowerCase();
        const eigene = appData.materialKatalog
          .filter(m => (m.bezeichnung || '').toLowerCase().includes(ql) || (m.artikelnr || '').toLowerCase().includes(ql))
          .slice(0, 20)
          .map(m => ({ quelle: 'Eigene DB', artNr: m.artikelnr || '', bezeichnung: m.bezeichnung, einheit: m.einheit || '', ek: m.ek_preis, lp: m.vk_preis }));
        items = [...eigene, ...items];
      }
    } catch (_) {
      if (box) box.innerHTML = '<span style="color:var(--danger)">Fehler bei der Suche.</span>';
      return;
    }
    if (!box) return;
    if (!items.length) { box.innerHTML = '<span style="color:var(--text-muted)">Keine Treffer.</span>'; return; }
    state._amKatItems = items;
    box.innerHTML = items.map((it, i) => `
      <div class="lager-pick-row" onclick="window.aufmassModul._lkpAmPick(${i})" title="${esc(it.bezeichnung)}">
        <span class="lager-katalog-quelle">${esc(it.quelle)}</span>
        <span class="lager-katalog-nr">${esc(it.artNr)}</span>
        <span class="lager-katalog-bez">${esc(it.bezeichnung)}</span>
        <span class="lager-katalog-einh">${esc(it.einheit)}</span>
        <span class="lager-katalog-preis">${it.ek != null ? fmtEur(it.ek) : '–'}</span>
      </div>
    `).join('');
  }

  /** Katalogeintrag auswählen → neue Position einfügen. */
  function _lkpAmPick(i) {
    const it = state._amKatItems?.[i];
    if (!it) return;
    state._amKatOv?.remove();
    state._amKatOv = null;
    _addPositionFromKatalog(it);
  }

  // ── Foto-OCR (Aufmaß) ──────────────────────────

  function _openFotoScanAm() {
    if (state.ocrAvailable === null) _checkOcrAvailableAm();
    let inp = document.getElementById('_aufmassOcrInput');
    if (!inp) {
      inp = document.createElement('input');
      inp.type = 'file'; inp.id = '_aufmassOcrInput'; inp.accept = 'image/*'; inp.capture = 'environment';
      inp.style.cssText = 'position:absolute;left:-9999px;top:-9999px;opacity:0';
      document.body.appendChild(inp);
    }
    inp.onchange = async () => {
      const file = inp.files?.[0];
      inp.value = '';
      if (!file) return;
      await _sendFotoOcrAm(file);
    };
    inp.click();
  }

  async function _sendFotoOcrAm(file) {
    // Lade-Overlay
    const ov = document.createElement('div');
    ov.className = 'lager-modal-overlay';
    ov.innerHTML = `<div class="lager-modal" style="text-align:center;padding:32px 16px">
      <div class="lager-ocr-spinner"></div>
      <p style="margin-top:16px;color:var(--text-muted)">Bild wird analysiert …</p>
    </div>`;
    document.body.appendChild(ov);

    let json;
    try {
      const fd = new FormData();
      fd.append('foto', file);
      const r = await fetch('api.php?action=module&module=aufmass&sub=foto_ocr', { method: 'POST', body: fd });
      json = await r.json();
    } catch (_) {
      ov.remove();
      notify('Netzwerkfehler beim Übermitteln des Fotos.');
      return;
    }
    ov.remove();

    if (!json.ok) { notify(json.error || json.hint || 'Unbekannter Fehler.'); return; }
    if (json.hint) { notify(json.hint); return; }

    const items = (json.datanorm_treffer || []).map(x => ({ quelle: 'Datanorm', artNr: x.artNr, bezeichnung: x.bezeichnung, einheit: x.einheit, ek: x.ek, lp: x.lp }));
    state._amKatItems = items;

    const textPreview = json.text
      ? `<details class="lager-ocr-text-details"><summary>Erkannter Text</summary><pre class="lager-ocr-text">${esc(json.text)}</pre></details>`
      : '';

    const ov2 = document.createElement('div');
    ov2.className = 'lager-modal-overlay';
    if (!items.length) {
      ov2.innerHTML = `<div class="lager-modal"><h3 style="margin-top:0">📷 Keine Treffer</h3>${textPreview}<div class="lager-modal-actions"><button class="btn btn-ghost" id="am_ocr_close">Schließen</button></div></div>`;
      document.body.appendChild(ov2);
      ov2.querySelector('#am_ocr_close').onclick = () => ov2.remove();
      return;
    }
    ov2.innerHTML = `<div class="lager-modal lager-modal-wide">
      <h3 style="margin-top:0">📷 Foto-Treffer</h3>
      ${textPreview}
      <div class="lager-pick-results" style="margin-top:8px">
        ${items.map((it, i) => `
          <div class="lager-pick-row" onclick="window.aufmassModul._amOcrPick(${i})" title="${esc(it.bezeichnung)}">
            <span class="lager-katalog-quelle">${esc(it.quelle)}</span>
            <span class="lager-katalog-nr">${esc(it.artNr)}</span>
            <span class="lager-katalog-bez">${esc(it.bezeichnung)}</span>
            <span class="lager-katalog-einh">${esc(it.einheit)}</span>
            <span class="lager-katalog-preis">${it.ek != null ? fmtEur(it.ek) : '–'}</span>
          </div>`).join('')}
      </div>
      <div class="lager-modal-actions" style="margin-top:8px">
        <button class="btn btn-ghost" id="am_ocr_close">Schließen</button>
      </div>
    </div>`;
    ov2.addEventListener('click', e => { if (e.target === ov2) ov2.remove(); });
    document.body.appendChild(ov2);
    ov2.querySelector('#am_ocr_close').onclick = () => ov2.remove();
    state._amOcrOv = ov2;
  }

  function _amOcrPick(i) {
    const it = state._amKatItems?.[i];
    if (!it) return;
    state._amOcrOv?.remove();
    state._amOcrOv = null;
    _addPositionFromKatalog(it);
  }

  // ── Public API ─────────────────────────────────────────
  window.showAufmass = showAufmass;
  window.hideAufmass = hideAufmass;
  window.showAufmassForBaustelle = function (bId) {
    state.filterBId = bId ? (parseInt(bId, 10) || 0) : 0;
    showAufmass();
  };
  window.aufmassModul = {
    showAufmass, hideAufmass, refresh, openEditor, backToList,
    saveCurrent, setStatus, deleteCurrent, uebernehmen,
    addAbschnitt, delAbschnitt, addPosition, delPosition,
    assignFromLager, _setPos, _setFormel, _setAbsName, _clearRef, _dirty,
    // Katalog-Picker & OCR
    _openKatalogPickerAm, _lkpAmSearch, _lkpAmPick,
    _openFotoScanAm, _amOcrPick,
    parseFormel, // exportiert für Tests/Mobile
  };
})();
