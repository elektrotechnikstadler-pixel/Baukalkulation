// MODULE_VERSION: 56
// ============================================================
// Lager-Modul – Frontend (v2.1, Phase 2B)
// ============================================================
// Vollständige Lagerverwaltung: Lagerorte CRUD, Artikel CRUD,
// Suche/Filter, Bestandsbuchungen, Mobile-Variante. Anbindung
// an "Offenes Material" und Projekt-Material erfolgt über Hooks
// in script.js (window.lagerModul.*).
// ============================================================

(function () {
  'use strict';

  // ── State (modulweit) ───────────────────────────────────────
  const state = {
    orte: [],
    artikel: [],
    q: '',
    lagerortFilter: 0,
    nurMitBestand: false,
    unterMindest: false,
    loading: false,
    searchTimer: null,
    ocrAvailable: null, // null = noch nicht geprüft, true/false nach features-Check
  };

  // ── Helpers ─────────────────────────────────────────────────
  function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }
  function fmtNum(n, dec = 2) {
    if (n === null || n === undefined || n === '') return '';
    const v = Number(n);
    if (!isFinite(v)) return '';
    return v.toLocaleString('de-DE', { minimumFractionDigits: dec, maximumFractionDigits: dec });
  }
  function fmtEur(n) {
    if (n === null || n === undefined || n === '') return '';
    const v = Number(n);
    if (!isFinite(v)) return '';
    return v.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
  }
  function parseNum(s) {
    if (s === null || s === undefined) return 0;
    if (typeof s === 'number') return s;
    return parseFloat(String(s).replace(',', '.')) || 0;
  }
  function notify(msg) {
    if (typeof window.showNotification === 'function') window.showNotification(msg);
    else console.log('[Lager]', msg);
  }
  function highlight(text, q) {
    if (!q) return esc(text);
    const tokens = q.split(/\s+/).filter(Boolean);
    let safe = esc(text);
    tokens.forEach(tok => {
      const r = new RegExp('(' + tok.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
      safe = safe.replace(r, '<mark>$1</mark>');
    });
    return safe;
  }

  // ── API ─────────────────────────────────────────────────────
  async function api(sub, body, method = 'POST') {
    const url = 'api.php?action=module&module=lager&sub=' + encodeURIComponent(sub);
    const opt = { method };
    if (method === 'POST') {
      opt.headers = { 'Content-Type': 'application/json' };
      opt.body = JSON.stringify(body || {});
    }
    const r = await fetch(url, opt);
    let j;
    try { j = await r.json(); } catch (e) { j = { error: 'Antwort nicht lesbar.' }; }
    if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status));
    return j;
  }

  // ── Hide helper (analog zum bestehenden Pattern in script.js) ─
  function hideAllOtherViews() {
    const ids = ['emptyState','baustelleDetail','materialKatalogView','stundenKatalogView',
      'offenesMaterialView','whatsappView','schnellnotizenView','kundenstammView',
      'dienstleisterView','allgemeinSettingsView','rechnungenView','auswertungView',
      'uebersichtView','din1090View','aufmassView','vde0100View','dashboardView'];
    ids.forEach(id => document.getElementById(id)?.classList.add('hidden'));
  }

  // ── Public Show/Hide ────────────────────────────────────────
  function showLager() {
    const view = document.getElementById('lagerView');
    if (!view) return;
    document.querySelectorAll('.sidebar-overview-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('btnLager')?.classList.add('active');
    if (typeof window.selectedId !== 'undefined') window.selectedId = null;
    if (typeof window.renderSidebar === 'function') window.renderSidebar();
    hideAllOtherViews();
    view.classList.remove('hidden');

    if (typeof appSettings !== 'undefined' && appSettings.modul_lager === false) {
      view.innerHTML = '<div style="padding:20px"><em>Modul Lager ist deaktiviert.</em></div>';
      return;
    }
    if (typeof canDo === 'function' && !canDo('canReadLager')) {
      view.innerHTML = '<div style="padding:20px"><em>Keine Berechtigung für das Lager-Modul.</em></div>';
      return;
    }
    renderLagerShell(view);
    refreshAll();
  }

  function hideLager() {
    document.getElementById('lagerView')?.classList.add('hidden');
    document.getElementById('btnLager')?.classList.remove('active');
  }

  // ── Daten laden ─────────────────────────────────────────────
  async function loadOrte() {
    try { const j = await api('orte_list', null, 'GET'); state.orte = j.orte || []; }
    catch (e) { notify('Lagerorte konnten nicht geladen werden: ' + e.message); }
  }

  async function loadArtikel() {
    state.loading = true;
    renderArtikelTable();
    try {
      const params = new URLSearchParams();
      if (state.q) params.set('q', state.q);
      if (state.lagerortFilter) params.set('lagerortId', String(state.lagerortFilter));
      if (state.nurMitBestand) params.set('nurMitBestand', '1');
      if (state.unterMindest) params.set('unterMindest', '1');
      const url = 'api.php?action=module&module=lager&sub=artikel_list' + (params.toString() ? '&' + params.toString() : '');
      const r = await fetch(url);
      const j = await r.json();
      if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status));
      state.artikel = j.artikel || [];
    } catch (e) {
      notify('Artikel konnten nicht geladen werden: ' + e.message);
      state.artikel = [];
    } finally {
      state.loading = false;
      renderArtikelTable();
    }
  }

  async function refreshAll() {
    await loadOrte();
    renderOrteSelect();
    await loadArtikel();
  }

  // ── Shell ───────────────────────────────────────────────────
  function renderLagerShell(view) {
    const canWrite = typeof canDo !== 'function' || canDo('canWriteLager');
    const canManageOrte = typeof canDo !== 'function' || canDo('canManageLagerorte');
    view.innerHTML = `
      <div class="lager-wrap">
        <div class="lager-header">
          <h2 style="margin:0;display:flex;align-items:center;gap:8px">📦 Lagerbestand</h2>
          <div class="lager-actions">
            ${canWrite ? '<button class="btn btn-primary" onclick="window.lagerModul.openArtikelDialog()">+ Artikel</button>' : ''}
            ${canManageOrte ? '<button class="btn btn-secondary" onclick="window.lagerModul.openOrteDialog()">Lagerorte verwalten</button>' : ''}
            <button class="btn btn-ghost" onclick="window.lagerModul.refreshAll()">⟳ Aktualisieren</button>
          </div>
        </div>

        <div class="lager-search">
          <input type="text" id="lagerSearchInput" class="form-control" placeholder="Suchen (Bezeichnung, Artikel-Nr., Kategorie) …" autocomplete="off">
          <select id="lagerOrtFilter" class="form-control"></select>
          <label class="lager-chk"><input type="checkbox" id="lagerNurMitBestand"> nur mit Bestand</label>
          <label class="lager-chk"><input type="checkbox" id="lagerUnterMindest"> unter Mindestbestand</label>
        </div>

        <div id="lagerArtikelContainer" class="lager-table-wrap"></div>
      </div>
    `;

    // Search handlers
    const inp = document.getElementById('lagerSearchInput');
    inp.addEventListener('input', () => {
      clearTimeout(state.searchTimer);
      state.searchTimer = setTimeout(() => { state.q = inp.value.trim(); loadArtikel(); }, 200);
    });
    document.getElementById('lagerNurMitBestand').addEventListener('change', e => { state.nurMitBestand = e.target.checked; loadArtikel(); });
    document.getElementById('lagerUnterMindest').addEventListener('change', e => { state.unterMindest = e.target.checked; loadArtikel(); });
    document.getElementById('lagerOrtFilter').addEventListener('change', e => { state.lagerortFilter = parseInt(e.target.value, 10) || 0; loadArtikel(); });

    // Resize-Handler: bei Orientierungswechsel neu rendern (Desktop↔Mobil)
    if (window._lagerResizeHandler) window.removeEventListener('resize', window._lagerResizeHandler);
    let _lagerResizeTimer = null;
    window._lagerResizeHandler = function () {
      clearTimeout(_lagerResizeTimer);
      _lagerResizeTimer = setTimeout(() => {
        if (document.getElementById('lagerArtikelContainer')) renderArtikelTable();
        else window.removeEventListener('resize', window._lagerResizeHandler);
      }, 250);
    };
    window.addEventListener('resize', window._lagerResizeHandler);
  }

  function renderOrteSelect() {
    const sel = document.getElementById('lagerOrtFilter');
    if (!sel) return;
    const cur = state.lagerortFilter;
    sel.innerHTML = '<option value="0">Alle Lagerorte</option>'
      + state.orte.map(o => `<option value="${o.id}" ${cur === o.id ? 'selected' : ''}>${esc(o.name)}${o.aktiv ? '' : ' (inaktiv)'} (${o.anzahl_artikel})</option>`).join('');
  }

  // ── Tabelle / Mobile Cards ───────────────────────────────────
  function renderArtikelTable() {
    const c = document.getElementById('lagerArtikelContainer');
    if (!c) return;
    const canWrite = typeof canDo !== 'function' || canDo('canWriteLager');

    if (state.loading) {
      c.innerHTML = '<div class="lager-empty"><em>Lade …</em></div>';
      return;
    }
    if (!state.artikel.length) {
      c.innerHTML = '<div class="lager-empty"><em>Keine Artikel gefunden.</em></div>';
      return;
    }

    // ── Mobile: Card-Layout ──────────────────────────────────
    if (window.innerWidth <= 720) {
      const cards = state.artikel.map(a => {
        const unter = a.mindestbestand > 0 && a.menge < a.mindestbestand;
        const istNull = a.menge <= 0;
        const ortBadge = a.lagerort_name
          ? `<span class="lager-pill">${esc(a.lagerort_name)}</span>`
          : '<span class="lager-pill lager-pill-grey">– kein Ort –</span>';
        const mindWarn = unter ? `<span class="lager-pill lager-pill-warn">↓ &lt; ${fmtNum(a.mindestbestand)}</span>` : '';
        return `<div class="lager-card-mobile${istNull ? ' lager-card-empty' : ''}">
          <div class="lager-card-main">
            <div class="lager-card-info">
              <div class="lager-bez">${highlight(a.bezeichnung, state.q)}</div>
              ${a.kategorie ? `<div class="lager-kat">${esc(a.kategorie)}</div>` : ''}
              ${a.artikelnr ? `<div class="lager-kat" style="color:#bbb">${esc(a.artikelnr)}</div>` : ''}
            </div>
            <div class="lager-card-bestand">
              <strong>${fmtNum(a.menge, a.menge % 1 === 0 ? 0 : 2)}</strong>
              <span class="lager-einheit">${esc(a.einheit || '')}</span>
            </div>
          </div>
          <div class="lager-card-badges">${ortBadge}${mindWarn}</div>
          ${canWrite ? `<div class="lager-card-actions">
            <button class="lager-card-btn" onclick="window.lagerModul.openBuchungDialog(${a.id}, 1)">+ Zugang</button>
            <button class="lager-card-btn" onclick="window.lagerModul.openBuchungDialog(${a.id}, -1)">− Abgang</button>
            <button class="lager-card-btn" onclick="window.lagerModul.openBuchungDialog(${a.id}, 0)">⚖ Korr.</button>
            <button class="lager-card-btn lager-card-btn-ghost" title="Bearbeiten" onclick="window.lagerModul.openArtikelDialog(${a.id})">✎</button>
            <button class="lager-card-btn lager-card-btn-danger" title="Löschen" onclick="window.lagerModul.deleteArtikel(${a.id})">🗑</button>
          </div>` : ''}
        </div>`;
      }).join('');
      c.innerHTML = `<div class="lager-cards-mobile">${cards}<div class="lager-cards-count">${state.artikel.length} Artikel</div></div>`;
      return;
    }

    // ── Desktop: Tabelle ─────────────────────────────────────
    let rows = '';
    state.artikel.forEach(a => {
      const unter = a.mindestbestand > 0 && a.menge < a.mindestbestand;
      const istNull = a.menge <= 0;
      const ortBadge = a.lagerort_name ? `<span class="lager-pill">${esc(a.lagerort_name)}</span>` : '<span class="lager-pill lager-pill-grey">– kein Ort –</span>';
      const mindWarn = unter ? `<span class="lager-pill lager-pill-warn" title="unter Mindestbestand">↓ &lt; ${fmtNum(a.mindestbestand)}</span>` : '';
      const ekCol = (typeof canDo === 'function' && canDo('canSeePrices')) ? `<td class="num">${a.ek_preis !== null ? fmtEur(a.ek_preis) : ''}</td>` : '';
      rows += `
        <tr class="${istNull ? 'lager-row-empty' : ''}">
          <td>${esc(a.artikelnr || '')}</td>
          <td>
            <div class="lager-bez">${highlight(a.bezeichnung, state.q)}</div>
            ${a.kategorie ? `<div class="lager-kat">${esc(a.kategorie)}</div>` : ''}
          </td>
          <td class="num">
            <strong>${fmtNum(a.menge, a.menge % 1 === 0 ? 0 : 2)}</strong>
            <span class="lager-einheit">${esc(a.einheit || '')}</span>
            ${mindWarn}
          </td>
          ${ekCol}
          <td>${ortBadge}</td>
          <td class="lager-actions-cell">
            ${canWrite ? `
              <button class="btn-icon" title="Zugang (+)" onclick="window.lagerModul.openBuchungDialog(${a.id}, 1)">+</button>
              <button class="btn-icon" title="Abgang (−)" onclick="window.lagerModul.openBuchungDialog(${a.id}, -1)">−</button>
              <button class="btn-icon" title="Korrektur" onclick="window.lagerModul.openBuchungDialog(${a.id}, 0)">⚖</button>
              <button class="btn-icon" title="Bearbeiten" onclick="window.lagerModul.openArtikelDialog(${a.id})">✎</button>
              <button class="btn-icon btn-icon-danger" title="Löschen" onclick="window.lagerModul.deleteArtikel(${a.id})">🗑</button>
            ` : ''}
          </td>
        </tr>
      `;
    });

    const ekHead = (typeof canDo === 'function' && canDo('canSeePrices')) ? '<th class="num">EK</th>' : '';
    c.innerHTML = `
      <table class="lager-table">
        <thead>
          <tr>
            <th style="width:120px">Artikel-Nr.</th>
            <th>Bezeichnung</th>
            <th class="num" style="width:160px">Bestand</th>
            ${ekHead}
            <th style="width:160px">Lagerort</th>
            <th style="width:200px"></th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
        <tfoot><tr><td colspan="6" style="font-size:.78rem;color:#666;padding:6px 10px">${state.artikel.length} Artikel</td></tr></tfoot>
      </table>
    `;
  }

  // ── Artikel-Dialog ──────────────────────────────────────────
  function openArtikelDialog(id) {
    const isEdit = id !== undefined && id !== null;
    const a = isEdit ? state.artikel.find(x => x.id === id) : null;
    if (isEdit && !a) { notify('Artikel nicht gefunden.'); return; }
    const a0 = a || { artikelnr:'', bezeichnung:'', menge:0, einheit:'Stk', ek_preis:'', vk_preis:'', lagerort_id:'', mindestbestand:0, kategorie:'', notiz:'' };

    const orteOpts = '<option value="">– kein Ort –</option>' + state.orte.map(o =>
      `<option value="${o.id}" ${a0.lagerort_id === o.id ? 'selected' : ''}>${esc(o.name)}</option>`).join('');

    // Toolbar: Katalogsuche immer, Foto-Scan wenn OCR oder KI verfügbar
    const fotoUnavailable = state.ocrAvailable === false && !state.aiVisionAvailable;
    const fotoLabel = state.aiVisionAvailable ? '🤖 Foto per KI analysieren' : '📷 Foto scannen';
    const fotoTitle = state.aiVisionAvailable ? 'KI-Bilderkennung (Google Gemini)' : 'Foto scannen (OCR)';
    const ocrBtn = fotoUnavailable
      ? `<button class="btn btn-ghost lager-katalog-toolbar-btn" disabled title="Kein API-Key konfiguriert (Einstellungen → KI-Bilderkennung) und kein Tesseract installiert">${fotoLabel}</button>`
      : `<button class="btn btn-ghost lager-katalog-toolbar-btn" title="${fotoTitle}" onclick="window.lagerModul._openFotoScanLager(${isEdit ? id : 'null'})">${fotoLabel}</button>`;

    const toolbar = `<div class="lager-katalog-toolbar">
      <button class="btn btn-ghost lager-katalog-toolbar-btn" onclick="window.lagerModul._openKatalogPickerLager(${isEdit ? id : 'null'})">🔍 Aus Katalog suchen</button>
      ${ocrBtn}
    </div>`;

    showModal(`
      <h3 style="margin-top:0">${isEdit ? 'Artikel bearbeiten' : 'Neuer Lagerartikel'}</h3>
      ${toolbar}
      <div class="lager-form-grid">
        <label><span>Bezeichnung *</span><input type="text" id="laBez" class="form-control" value="${esc(a0.bezeichnung)}" maxlength="200"></label>
        <label><span>Artikel-Nr.</span><input type="text" id="laNr" class="form-control" value="${esc(a0.artikelnr || '')}"></label>
        <label><span>Kategorie</span><input type="text" id="laKat" class="form-control" value="${esc(a0.kategorie || '')}"></label>
        <label><span>Lagerort</span><select id="laOrt" class="form-control">${orteOpts}</select></label>
        <label><span>Bestand</span><input type="text" id="laMenge" class="form-control" value="${fmtNum(a0.menge, a0.menge % 1 === 0 ? 0 : 2)}" inputmode="decimal"></label>
        <label><span>Einheit</span><input type="text" id="laEinh" class="form-control" value="${esc(a0.einheit || '')}" placeholder="Stk, m, kg …"></label>
        <label><span>EK-Preis (€)</span><input type="text" id="laEk" class="form-control" value="${a0.ek_preis !== null && a0.ek_preis !== '' ? fmtNum(a0.ek_preis) : ''}" inputmode="decimal"></label>
        <label><span>VK-Preis (€)</span><input type="text" id="laVk" class="form-control" value="${a0.vk_preis !== null && a0.vk_preis !== '' ? fmtNum(a0.vk_preis) : ''}" inputmode="decimal"></label>
        <label><span>Mindestbestand</span><input type="text" id="laMin" class="form-control" value="${fmtNum(a0.mindestbestand, 0)}" inputmode="decimal"></label>
        <label class="full"><span>Notiz</span><textarea id="laNot" class="form-control" rows="2">${esc(a0.notiz || '')}</textarea></label>
      </div>
      <div class="lager-modal-actions">
        <button class="btn btn-ghost" onclick="window.lagerModul.closeModal()">Abbrechen</button>
        <button class="btn btn-primary" onclick="window.lagerModul._submitArtikel(${isEdit ? id : 'null'})">Speichern</button>
      </div>
    `);
    // OCR-Verfügbarkeit beim ersten Öffnen asynchron ermitteln
    if (state.ocrAvailable === null) _checkOcrAvailable();
    setTimeout(() => document.getElementById('laBez')?.focus(), 50);
  }

  async function _submitArtikel(id) {
    const body = {
      id: id || 0,
      bezeichnung: document.getElementById('laBez').value.trim(),
      artikelnr: document.getElementById('laNr').value.trim(),
      kategorie: document.getElementById('laKat').value.trim(),
      lagerort_id: document.getElementById('laOrt').value || null,
      menge: parseNum(document.getElementById('laMenge').value),
      einheit: document.getElementById('laEinh').value.trim(),
      ek_preis: document.getElementById('laEk').value.trim() === '' ? '' : parseNum(document.getElementById('laEk').value),
      vk_preis: document.getElementById('laVk').value.trim() === '' ? '' : parseNum(document.getElementById('laVk').value),
      mindestbestand: parseNum(document.getElementById('laMin').value),
      notiz: document.getElementById('laNot').value.trim(),
    };
    if (!body.bezeichnung) { notify('Bezeichnung ist erforderlich.'); return; }
    try {
      await api('artikel_save', body);
      closeModal();
      notify(id ? 'Artikel gespeichert.' : 'Artikel angelegt.');
      await refreshAll();
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  async function deleteArtikel(id) {
    const a = state.artikel.find(x => x.id === id);
    if (!confirm(`Artikel "${a ? a.bezeichnung : '#' + id}" wirklich löschen?`)) return;
    try {
      await api('artikel_delete', { id });
      notify('Artikel gelöscht.');
      await refreshAll();
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  // ── Buchungs-Dialog ─────────────────────────────────────────
  // direction: +1 = Zugang, -1 = Abgang, 0 = Korrektur (absolut)
  function openBuchungDialog(artikelId, direction, opts) {
    opts = opts || {};
    const a = state.artikel.find(x => x.id === artikelId);
    if (!a) { notify('Artikel nicht gefunden.'); return; }
    const titel = direction > 0 ? 'Zugang buchen' : direction < 0 ? 'Abgang buchen' : 'Bestand korrigieren';
    const isKorrektur = direction === 0;
    showModal(`
      <h3 style="margin-top:0">${titel}</h3>
      <div style="margin-bottom:10px"><strong>${esc(a.bezeichnung)}</strong>${a.artikelnr ? ` <span style="color:#888">(${esc(a.artikelnr)})</span>` : ''}</div>
      <div style="margin-bottom:10px;color:#555">Aktuell: <strong>${fmtNum(a.menge, a.menge % 1 === 0 ? 0 : 2)} ${esc(a.einheit || '')}</strong>${a.lagerort_name ? ' im ' + esc(a.lagerort_name) : ''}</div>
      <div class="lager-form-grid">
        <label class="full"><span>${isKorrektur ? 'Neuer Bestand' : 'Menge'}</span>
          <input type="text" id="lbMenge" class="form-control" inputmode="decimal" value="${isKorrektur ? fmtNum(a.menge, a.menge % 1 === 0 ? 0 : 2) : ''}" placeholder="z. B. 5">
        </label>
        <label class="full"><span>Grund / Bemerkung</span>
          <input type="text" id="lbGrund" class="form-control" maxlength="200" value="${esc(opts.grund || '')}" placeholder="${isKorrektur ? 'Inventur, Korrektur …' : direction > 0 ? 'Wareneingang, Rückläufer …' : 'Verbrauch, Projekt …'}">
        </label>
      </div>
      <div class="lager-modal-actions">
        <button class="btn btn-ghost" onclick="window.lagerModul.closeModal()">Abbrechen</button>
        <button class="btn btn-primary" onclick="window.lagerModul._submitBuchung(${artikelId}, ${direction}, ${opts.baustelleId || 0})">Buchen</button>
      </div>
    `);
    setTimeout(() => document.getElementById('lbMenge')?.focus(), 50);
  }

  async function _submitBuchung(artikelId, direction, baustelleId) {
    const a = state.artikel.find(x => x.id === artikelId);
    if (!a) return;
    const eingabe = parseNum(document.getElementById('lbMenge').value);
    if (eingabe < 0) { notify('Menge darf nicht negativ sein.'); return; }
    let delta;
    if (direction === 0) {
      delta = eingabe - a.menge;
      if (delta === 0) { notify('Keine Änderung.'); return; }
    } else if (direction > 0) {
      if (eingabe <= 0) { notify('Menge > 0 erforderlich.'); return; }
      delta = eingabe;
    } else {
      if (eingabe <= 0) { notify('Menge > 0 erforderlich.'); return; }
      delta = -eingabe;
    }
    const grund = document.getElementById('lbGrund').value.trim();
    try {
      const r = await api('buchung', { artikelId, delta, grund, baustelleId });
      closeModal();
      notify(`Gebucht. Neuer Bestand: ${fmtNum(r.menge, r.menge % 1 === 0 ? 0 : 2)} ${a.einheit || ''}`);
      await refreshAll();
      return r;
    } catch (e) {
      notify('Fehler: ' + e.message);
      throw e;
    }
  }

  // ── Lagerorte-Dialog ────────────────────────────────────────
  function openOrteDialog() {
    const canManage = typeof canDo !== 'function' || canDo('canManageLagerorte');
    if (!canManage) { notify('Keine Berechtigung.'); return; }
    showModal(`
      <h3 style="margin-top:0">Lagerorte verwalten</h3>
      <div id="lagerOrteList"></div>
      <hr style="margin:14px 0">
      <div class="lager-form-grid">
        <label><span>Neuer Lagerort *</span><input type="text" id="loName" class="form-control" maxlength="100" placeholder="z. B. Werkstatt"></label>
        <label class="full"><span>Beschreibung</span><input type="text" id="loBesc" class="form-control" placeholder="Optional"></label>
      </div>
      <div class="lager-modal-actions">
        <button class="btn btn-ghost" onclick="window.lagerModul.closeModal()">Schließen</button>
        <button class="btn btn-primary" onclick="window.lagerModul._addOrt()">+ Anlegen</button>
      </div>
    `, { wide: true });
    renderOrteList();
  }

  function renderOrteList() {
    const c = document.getElementById('lagerOrteList');
    if (!c) return;
    if (!state.orte.length) { c.innerHTML = '<em>Noch keine Lagerorte.</em>'; return; }
    c.innerHTML = `<table class="lager-table"><thead><tr><th>Name</th><th>Beschreibung</th><th>Aktiv</th><th>Artikel</th><th></th></tr></thead><tbody>${
      state.orte.map(o => `
        <tr>
          <td><input type="text" class="form-control" value="${esc(o.name)}" data-ort-name="${o.id}"></td>
          <td><input type="text" class="form-control" value="${esc(o.beschreibung || '')}" data-ort-besc="${o.id}"></td>
          <td><input type="checkbox" data-ort-aktiv="${o.id}" ${o.aktiv ? 'checked' : ''}></td>
          <td>${o.anzahl_artikel}</td>
          <td>
            <button class="btn-icon" onclick="window.lagerModul._saveOrt(${o.id})">💾</button>
            <button class="btn-icon btn-icon-danger" onclick="window.lagerModul._deleteOrt(${o.id})" ${o.anzahl_artikel > 0 ? 'disabled title="Erst Artikel umlagern"' : ''}>🗑</button>
          </td>
        </tr>
      `).join('')
    }</tbody></table>`;
  }

  async function _addOrt() {
    const name = document.getElementById('loName').value.trim();
    const besc = document.getElementById('loBesc').value.trim();
    if (!name) { notify('Name erforderlich.'); return; }
    try {
      await api('ort_save', { name, beschreibung: besc, aktiv: 1 });
      document.getElementById('loName').value = '';
      document.getElementById('loBesc').value = '';
      await loadOrte();
      renderOrteList();
      renderOrteSelect();
      notify('Lagerort angelegt.');
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  async function _saveOrt(id) {
    const name = document.querySelector(`[data-ort-name="${id}"]`).value.trim();
    const besc = document.querySelector(`[data-ort-besc="${id}"]`).value.trim();
    const aktiv = document.querySelector(`[data-ort-aktiv="${id}"]`).checked ? 1 : 0;
    if (!name) { notify('Name erforderlich.'); return; }
    try {
      await api('ort_save', { id, name, beschreibung: besc, aktiv });
      await loadOrte();
      renderOrteList();
      renderOrteSelect();
      await loadArtikel();
      notify('Gespeichert.');
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  async function _deleteOrt(id) {
    const o = state.orte.find(x => x.id === id);
    if (!confirm(`Lagerort "${o ? o.name : '#' + id}" löschen?`)) return;
    try {
      await api('ort_delete', { id });
      await loadOrte();
      renderOrteList();
      renderOrteSelect();
      notify('Gelöscht.');
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  // ── Modal-System (eigenständig, damit Modul nicht von script.js abhängt) ─
  function showModal(html, opts) {
    opts = opts || {};
    closeModal();
    const ov = document.createElement('div');
    ov.id = 'lagerModalOverlay';
    ov.className = 'lager-modal-overlay';
    ov.innerHTML = `<div class="lager-modal ${opts.wide ? 'lager-modal-wide' : ''}">${html}</div>`;
    ov.addEventListener('click', (e) => { if (e.target === ov) closeModal(); });
    document.body.appendChild(ov);
    document.addEventListener('keydown', escClose);
  }
  function escClose(e) { if (e.key === 'Escape') closeModal(); }
  function closeModal() {
    document.getElementById('lagerModalOverlay')?.remove();
    document.removeEventListener('keydown', escClose);
  }

  // ── Public API für Hooks aus script.js ──────────────────────
  // Wird von der Projekt-Material-Sektion aufgerufen, um Lagerartikel
  // ins Projekt zu übernehmen (mit sofortiger Bestandsabbuchung).
  async function pickAndConsumeForProject(opts) {
    opts = opts || {};
    await loadOrte();
    await loadArtikel();
    return new Promise((resolve) => {
      showModal(`
        <h3 style="margin-top:0">Aus Lager hinzufügen</h3>
        <div class="lager-search" style="margin-bottom:8px">
          <input type="text" id="lpSearch" class="form-control" placeholder="Suchen …" autocomplete="off" autofocus>
          <label class="lager-chk"><input type="checkbox" id="lpNurMit" checked> nur mit Bestand</label>
        </div>
        <div id="lpResults" class="lager-pick-results"></div>
        <div class="lager-modal-actions">
          <button class="btn btn-ghost" onclick="window.lagerModul.closeModal()">Abbrechen</button>
        </div>
      `, { wide: true });

      const renderResults = () => {
        const q = document.getElementById('lpSearch').value.trim().toLowerCase();
        const nurMit = document.getElementById('lpNurMit').checked;
        const list = state.artikel.filter(a => {
          if (nurMit && a.menge <= 0) return false;
          if (!q) return true;
          const tokens = q.split(/\s+/);
          return tokens.every(t =>
            (a.bezeichnung || '').toLowerCase().includes(t) ||
            (a.artikelnr || '').toLowerCase().includes(t) ||
            (a.kategorie || '').toLowerCase().includes(t));
        });
        const c = document.getElementById('lpResults');
        if (!list.length) { c.innerHTML = '<em>Keine Treffer.</em>'; return; }
        c.innerHTML = list.map(a => `
          <div class="lager-pick-row" data-id="${a.id}">
            <div>
              <div class="lager-bez">${highlight(a.bezeichnung, q)}</div>
              <div class="lager-kat">${esc(a.artikelnr || '')}${a.lagerort_name ? ' · ' + esc(a.lagerort_name) : ''}</div>
            </div>
            <div class="num">${fmtNum(a.menge, a.menge % 1 === 0 ? 0 : 2)} ${esc(a.einheit || '')}</div>
            <button class="btn btn-primary" onclick="window.lagerModul._pickConsume(${a.id})">Übernehmen</button>
          </div>
        `).join('');
      };
      document.getElementById('lpSearch').addEventListener('input', renderResults);
      document.getElementById('lpNurMit').addEventListener('change', renderResults);
      renderResults();

      // Resolver merken
      pickAndConsumeForProject._resolve = resolve;
      pickAndConsumeForProject._opts = opts;
    });
  }

  async function _pickConsume(artikelId) {
    const a = state.artikel.find(x => x.id === artikelId);
    if (!a) return;
    const opts = pickAndConsumeForProject._opts || {};
    const resolve = pickAndConsumeForProject._resolve;
    closeModal();
    // Mengen-Dialog
    showModal(`
      <h3 style="margin-top:0">Menge entnehmen</h3>
      <div style="margin-bottom:10px"><strong>${esc(a.bezeichnung)}</strong></div>
      <div style="margin-bottom:10px;color:#555">Verfügbar: <strong>${fmtNum(a.menge, a.menge % 1 === 0 ? 0 : 2)} ${esc(a.einheit || '')}</strong></div>
      <div class="lager-form-grid">
        <label class="full"><span>Menge entnehmen *</span>
          <input type="text" id="lpcMenge" class="form-control" inputmode="decimal" value="${a.menge > 0 ? '1' : ''}" autofocus>
        </label>
      </div>
      <div class="lager-modal-actions">
        <button class="btn btn-ghost" onclick="window.lagerModul._pickCancel()">Abbrechen</button>
        <button class="btn btn-primary" onclick="window.lagerModul._pickConfirm(${artikelId})">Entnehmen</button>
      </div>
    `);
  }

  function _pickCancel() {
    closeModal();
    if (pickAndConsumeForProject._resolve) pickAndConsumeForProject._resolve(null);
    pickAndConsumeForProject._resolve = null;
  }

  async function _pickConfirm(artikelId) {
    const a = state.artikel.find(x => x.id === artikelId);
    if (!a) return;
    const menge = parseNum(document.getElementById('lpcMenge').value);
    if (menge <= 0) { notify('Menge > 0 erforderlich.'); return; }
    if (menge > a.menge) { notify('Mehr verlangt als auf Lager.'); return; }
    const opts = pickAndConsumeForProject._opts || {};
    try {
      const r = await api('buchung', {
        artikelId,
        delta: -menge,
        grund: 'Projekt-Entnahme' + (opts.baustelleName ? ': ' + opts.baustelleName : ''),
        baustelleId: opts.baustelleId || 0,
      });
      closeModal();
      const result = {
        artikelId,
        bezeichnung: a.bezeichnung,
        artikelnr: a.artikelnr,
        einheit: a.einheit,
        ek_preis: a.ek_preis,
        vk_preis: a.vk_preis,
        menge,
        neuerBestand: r.menge,
      };
      if (pickAndConsumeForProject._resolve) pickAndConsumeForProject._resolve(result);
      pickAndConsumeForProject._resolve = null;
      notify(`Entnommen: ${fmtNum(menge)} ${a.einheit || ''} – neuer Bestand ${fmtNum(r.menge)}`);
    } catch (e) {
      notify('Fehler: ' + e.message);
    }
  }

  // Match Offenes Material gegen Lagerbestand (für script.js Hook)
  async function matchOffenesMaterial(eintraege) {
    if (typeof canDo === 'function' && !canDo('canReadLager')) return [];
    if (typeof appSettings !== 'undefined' && appSettings.modul_lager === false) return [];
    if (!Array.isArray(eintraege) || eintraege.length === 0) return [];
    try {
      const j = await api('match_offenes_material', { eintraege });
      return j.matches || [];
    } catch (e) {
      return [];
    }
  }

  // ── Katalog- und OCR-Hilfsfunktionen ───────────────────────

  /** Prüft beim ersten Öffnen des Artikeldialogs, ob Tesseract (OCR) oder Gemini (KI) verfügbar ist. */
  async function _checkOcrAvailable() {
    try {
      const fd = new FormData();
      // Leeres POST → Backend antwortet 400 "Kein Bild" wenn OCR aktiv ist,
      // oder 200 + hint wenn Tesseract nicht installiert ist.
      const [ocrRes, aiRes] = await Promise.allSettled([
        fetch('api.php?action=module&module=lager&sub=foto_ocr', { method: 'POST', body: fd }),
        fetch('api.php?action=module&module=lager&sub=foto_ai_available'),
      ]);

      // OCR-Prüfung
      if (ocrRes.status === 'fulfilled') {
        const r = ocrRes.value;
        if (r.status === 400) {
          state.ocrAvailable = true;
        } else if (r.status === 200) {
          const j = await r.json().catch(() => ({}));
          state.ocrAvailable = !j.hint;
        } else {
          state.ocrAvailable = false;
        }
      } else {
        state.ocrAvailable = false;
      }

      // KI-Prüfung
      if (aiRes.status === 'fulfilled' && aiRes.value.ok) {
        const j = await aiRes.value.json().catch(() => ({}));
        state.aiVisionAvailable = !!(j.ok && j.available);
      } else {
        state.aiVisionAvailable = false;
      }
    } catch (_) {
      state.ocrAvailable = false;
      state.aiVisionAvailable = false;
    }
    // Wenn Dialog noch geöffnet: Foto-Schaltfläche ggf. aktivieren
    const btn = document.querySelector('.lager-katalog-toolbar-btn[disabled]');
    if (btn && (state.ocrAvailable || state.aiVisionAvailable)) {
      btn.removeAttribute('disabled');
      btn.title = state.aiVisionAvailable ? 'KI-Bilderkennung (Google Gemini)' : 'Foto scannen (OCR)';
    }
  }

  /**
   * Füllt den offenen Artikel-Dialog mit Werten aus einem Katalogeintrag.
   * src: { artikelnr, bezeichnung, einheit, ek, lp }  (Datanorm oder eigene DB)
   */
  function _fillArtikelFromKatalog(src) {
    const bez  = document.getElementById('laBez');
    const nr   = document.getElementById('laNr');
    const einh = document.getElementById('laEinh');
    const ek   = document.getElementById('laEk');
    const vk   = document.getElementById('laVk');
    if (bez  && bez.value  === '') bez.value  = src.bezeichnung || '';
    else if (bez) bez.value = src.bezeichnung || bez.value;
    if (nr   && nr.value   === '') nr.value   = src.artNr || src.artikelnr || '';
    else if (nr) nr.value = src.artNr || src.artikelnr || nr.value;
    if (einh && einh.value === '') einh.value = src.einheit || '';
    else if (einh && src.einheit) einh.value = src.einheit;
    if (ek && src.ek != null)  ek.value  = fmtNum(src.ek);
    if (vk && src.lp != null)  vk.value  = fmtNum(src.lp);
    bez?.focus();
  }

  /**
   * Öffnet einen separaten Such-Picker, der Datanorm + eigene Materialdatenbank durchsucht.
   * targetId: ID des Artikels falls Edit-Modus, sonst null.
   */
  async function _openKatalogPickerLager(targetId) {
    // Picker-Modal zeigen
    showModal(`
      <h3 style="margin-top:0">🔍 Aus Katalog suchen</h3>
      <div class="lager-katalog-search-row">
        <input type="text" id="lkpQ" class="form-control" placeholder="Artikelnummer oder Bezeichnung …" autofocus>
        <button class="btn btn-primary" onclick="window.lagerModul._lkpSearch()">Suchen</button>
      </div>
      <div id="lkpResults" class="lager-pick-results" style="min-height:60px;margin-top:12px"></div>
      <div class="lager-modal-actions" style="margin-top:8px">
        <button class="btn btn-ghost" onclick="window.lagerModul._lkpBack(${targetId})">← Zurück</button>
      </div>
    `);
    // Eingabe-Live-Suche
    const inp = document.getElementById('lkpQ');
    if (inp) {
      inp.focus();
      inp.addEventListener('keydown', e => {
        if (e.key === 'Enter') window.lagerModul._lkpSearch();
        if (e.key === 'Escape') window.lagerModul._lkpBack(targetId);
      });
      inp.addEventListener('input', () => {
        clearTimeout(state._lkpTimer);
        state._lkpTimer = setTimeout(() => window.lagerModul._lkpSearch(), 400);
      });
    }
    // Callback-Zustand speichern
    state._lkpTargetId = targetId;
  }

  /** Führt die Datanorm-Suche aus und rendert Ergebnisse. */
  async function _lkpSearch() {
    const inp = document.getElementById('lkpQ');
    const q = inp ? inp.value.trim() : '';
    if (!q) return;
    const box = document.getElementById('lkpResults');
    if (box) box.innerHTML = '<span style="color:var(--text-muted)">Suche …</span>';

    let items = [];
    try {
      // Datanorm
      const dn = await fetch(`api.php?action=datanorm_search&q=${encodeURIComponent(q)}`).then(r => r.json());
      if (dn.results) {
        items = dn.results.map(x => ({
          quelle: 'Datanorm',
          artNr: x.artNr,
          bezeichnung: x.bezeichnung,
          einheit: x.einheit,
          ek: x.ek,
          lp: x.lp,
        }));
      }
      // Eigene Datenbank (appData.materialKatalog falls vorhanden)
      if (typeof appData !== 'undefined' && Array.isArray(appData.materialKatalog)) {
        const ql = q.toLowerCase();
        const eigene = appData.materialKatalog
          .filter(m => (m.bezeichnung || '').toLowerCase().includes(ql) || (m.artikelnr || '').toLowerCase().includes(ql))
          .slice(0, 20)
          .map(m => ({ quelle: 'Eigene DB', artNr: m.artikelnr || '', bezeichnung: m.bezeichnung, einheit: m.einheit || '', ek: m.ek_preis, lp: m.vk_preis }));
        items = [...eigene, ...items];
      }
    } catch (e) {
      if (box) box.innerHTML = '<span style="color:var(--danger)">Fehler bei der Suche.</span>';
      return;
    }

    if (!box) return;
    if (items.length === 0) {
      box.innerHTML = '<span style="color:var(--text-muted)">Keine Treffer.</span>';
      return;
    }
    box.innerHTML = items.map((it, i) => `
      <div class="lager-pick-row" onclick="window.lagerModul._lkpPick(${i})" title="${esc(it.bezeichnung)}">
        <span class="lager-katalog-quelle">${esc(it.quelle)}</span>
        <span class="lager-katalog-nr">${esc(it.artNr)}</span>
        <span class="lager-katalog-bez">${esc(it.bezeichnung)}</span>
        <span class="lager-katalog-einh">${esc(it.einheit)}</span>
        <span class="lager-katalog-preis">${it.ek != null ? fmtEur(it.ek) : '–'}</span>
      </div>
    `).join('');
    // Items in state speichern für _lkpPick
    state._lkpItems = items;
  }

  /** Wählt einen Katalogeintrag aus und füllt den Artikeldialog. */
  function _lkpPick(index) {
    const it = state._lkpItems?.[index];
    if (!it) return;
    // Artikeldialog wiederherstellen und füllen
    openArtikelDialog(state._lkpTargetId ?? undefined);
    setTimeout(() => _fillArtikelFromKatalog(it), 50);
  }

  /** Schließt den Picker und öffnet den Artikeldialog wieder. */
  function _lkpBack(targetId) {
    openArtikelDialog(targetId ?? undefined);
  }

  // ── Foto-OCR ──────────────────────────────────────────────

  /**
   * Öffnet einen unsichtbaren File-Input, empfängt das Foto, sendet es an das Backend,
   * zeigt anschließend kombinierte Lager- + Datanorm-Treffer zur Auswahl.
   */
  function _openFotoScanLager(targetId) {
    // Versteckten File-Input anlegen
    let inp = document.getElementById('_lagerOcrInput');
    if (!inp) {
      inp = document.createElement('input');
      inp.type = 'file';
      inp.id = '_lagerOcrInput';
      inp.accept = 'image/*';
      inp.capture = 'environment'; // Kamera auf Mobilgeräten bevorzugen
      inp.style.cssText = 'position:absolute;left:-9999px;top:-9999px;opacity:0';
      document.body.appendChild(inp);
    }
    // Alten Listener entfernen, neuen setzen
    inp.onchange = async () => {
      const file = inp.files?.[0];
      inp.value = ''; // Reset für erneute Nutzung
      if (!file) return;
      await _sendFotoOcr(file, targetId);
    };
    inp.click();
  }

  /** Sendet das Foto an das Backend und verarbeitet die Antwort. Nutzt KI wenn verfügbar, sonst OCR. */
  async function _sendFotoOcr(file, targetId) {
    // KI-Pfad bevorzugen wenn Gemini-Key konfiguriert
    if (state.aiVisionAvailable) {
      await _sendFotoAi(file, targetId);
      return;
    }
    // Lade-Modal
    showModal(`<div style="text-align:center;padding:32px 16px">
      <div class="lager-ocr-spinner"></div>
      <p style="margin-top:16px;color:var(--text-muted)">Bild wird analysiert …</p>
    </div>`);

    let json;
    try {
      const fd = new FormData();
      fd.append('foto', file);
      const r = await fetch('api.php?action=module&module=lager&sub=foto_ocr', { method: 'POST', body: fd });
      json = await r.json();
    } catch (e) {
      showModal(`<div style="text-align:center;padding:24px">
        <p style="color:var(--danger)">Netzwerkfehler beim Übermitteln des Fotos.</p>
        <button class="btn btn-ghost" onclick="window.lagerModul._lkpBack(${targetId ?? 'null'})">← Zurück</button>
      </div>`);
      return;
    }

    if (!json.ok) {
      showModal(`<div style="padding:24px">
        <p style="color:var(--danger)">${esc(json.error || json.hint || 'Unbekannter Fehler.')}</p>
        <div class="lager-modal-actions">
          <button class="btn btn-ghost" onclick="window.lagerModul._lkpBack(${targetId ?? 'null'})">← Zurück</button>
        </div>
      </div>`);
      return;
    }

    if (json.hint) {
      showModal(`<div style="padding:24px">
        <p style="color:var(--text-muted)">${esc(json.hint)}</p>
        <div class="lager-modal-actions">
          <button class="btn btn-ghost" onclick="window.lagerModul._lkpBack(${targetId ?? 'null'})">← Zurück</button>
        </div>
      </div>`);
      return;
    }

    // Alle Treffer zusammenführen
    const lager = (json.lager_treffer || []).map(x => ({
      quelle: 'Lager', artNr: x.artikelnr || '', bezeichnung: x.bezeichnung,
      einheit: x.einheit || '', ek: x.ek_preis, lp: null,
    }));
    const dn = (json.datanorm_treffer || []).map(x => ({
      quelle: 'Datanorm', artNr: x.artNr || '', bezeichnung: x.bezeichnung,
      einheit: x.einheit || '', ek: x.ek, lp: x.lp,
    }));
    const items = [...lager, ...dn];
    state._lkpItems = items;
    state._lkpTargetId = targetId;

    const ocrText = json.text || '';
    const textPreview = ocrText
      ? `<details class="lager-ocr-text-details"><summary>Erkannter Text anzeigen</summary><pre class="lager-ocr-text">${esc(ocrText)}</pre></details>`
      : '';

    if (items.length === 0) {
      showModal(`<div style="padding:24px">
        <p style="color:var(--text-muted)">Keine passenden Artikel gefunden.</p>
        ${textPreview}
        <div class="lager-modal-actions">
          <button class="btn btn-ghost" onclick="window.lagerModul._lkpBack(${targetId ?? 'null'})">← Zurück</button>
        </div>
      </div>`);
      return;
    }

    showModal(`
      <h3 style="margin-top:0">📷 Foto-Treffer</h3>
      ${textPreview}
      <div class="lager-pick-results" style="margin-top:8px">
        ${items.map((it, i) => `
          <div class="lager-pick-row" onclick="window.lagerModul._lkpPick(${i})" title="${esc(it.bezeichnung)}">
            <span class="lager-katalog-quelle">${esc(it.quelle)}</span>
            <span class="lager-katalog-nr">${esc(it.artNr)}</span>
            <span class="lager-katalog-bez">${esc(it.bezeichnung)}</span>
            <span class="lager-katalog-einh">${esc(it.einheit)}</span>
            <span class="lager-katalog-preis">${it.ek != null ? fmtEur(it.ek) : '–'}</span>
          </div>`).join('')}
      </div>
      <div class="lager-modal-actions" style="margin-top:8px">
        <button class="btn btn-ghost" onclick="window.lagerModul._lkpBack(${targetId ?? 'null'})">← Zurück ohne Auswahl</button>
      </div>
    `);
  }

  // ── KI-Foto-Analyse (Google Gemini) ────────────────────────

  /** Sendet das Foto an den Gemini-Backend-Endpoint und zeigt die KI-Vorschau. */
  async function _sendFotoAi(file, targetId) {
    showModal(`<div style="text-align:center;padding:32px 16px">
      <div class="lager-ocr-spinner"></div>
      <p style="margin-top:16px;color:var(--text-muted)">🤖 KI analysiert das Bild …</p>
    </div>`);

    let json;
    try {
      const fd = new FormData();
      fd.append('foto', file);
      const r = await fetch('api.php?action=module&module=lager&sub=foto_ai', { method: 'POST', body: fd });
      json = await r.json();
    } catch (e) {
      showModal(`<div style="text-align:center;padding:24px">
        <p style="color:var(--danger)">Netzwerkfehler beim KI-Aufruf.</p>
        <button class="btn btn-ghost" onclick="window.lagerModul._lkpBack(${targetId ?? 'null'})">← Zurück</button>
      </div>`);
      return;
    }

    if (!json.ok || (json.hint && !json.bezeichnung)) {
      showModal(`<div style="padding:24px">
        <p style="color:var(--text-muted)">${esc(json.hint || json.error || 'KI konnte den Artikel nicht erkennen.')}</p>
        <div class="lager-modal-actions">
          <button class="btn btn-ghost" onclick="window.lagerModul._lkpBack(${targetId ?? 'null'})">← Zurück</button>
        </div>
      </div>`);
      return;
    }

    _showAiVorschau(json, targetId);
  }

  /** Zeigt die KI-Vorschau-Karte mit Konfidenz-Balken und Übernahme-Option. */
  function _showAiVorschau(json, targetId) {
    const confidence = Math.max(0, Math.min(100, json.confidence || 0));
    const confColor  = confidence >= 70 ? '#22c55e' : confidence >= 40 ? '#f59e0b' : '#ef4444';
    const confLabel  = confidence >= 70 ? 'Hoch' : confidence >= 40 ? 'Mittel' : 'Niedrig';

    const fieldRow = (label, val) => val
      ? `<tr><td style="color:#888;font-size:.8rem;padding:3px 8px 3px 0;white-space:nowrap">${label}</td><td style="font-size:.88rem;font-weight:500">${esc(String(val))}</td></tr>`
      : '';

    // KI-JSON für spätere Übernahme merken
    state._aiJson = json;

    // Lager-Treffer als klickbare Liste
    const treffer = (json.lager_treffer || []);
    state._lkpItems = treffer.map(t => ({
      quelle: 'Lager', artNr: t.artikelnr || '', bezeichnung: t.bezeichnung,
      einheit: t.einheit || '', ek: t.ek_preis,
    }));
    state._lkpTargetId = targetId;

    const trefferHtml = treffer.length ? `
      <div style="margin-top:14px">
        <p style="font-size:.78rem;color:#888;margin-bottom:4px">Ähnliche Lagerartikel:</p>
        <div class="lager-pick-results">
          ${treffer.map((t, i) => `
            <div class="lager-pick-row" onclick="window.lagerModul._lkpPick(${i})">
              <span class="lager-katalog-quelle">Lager</span>
              <span class="lager-katalog-nr">${esc(t.artikelnr || '')}</span>
              <span class="lager-katalog-bez">${esc(t.bezeichnung)}</span>
              <span class="lager-katalog-einh">${esc(t.einheit || '')}</span>
              <span class="lager-katalog-preis">${t.ek_preis != null ? fmtEur(t.ek_preis) : '–'}</span>
            </div>`).join('')}
        </div>
      </div>` : '';

    // Katalog-Treffer: Backend (Datanorm) + Frontend (eigener Materialkatalog)
    const katTreffer = (json.katalog_treffer || []).slice(0, 5);
    if (typeof appData !== 'undefined' && Array.isArray(appData.materialKatalog) && json.bezeichnung) {
      const tokens = json.bezeichnung.toLowerCase().split(/\s+/).filter(t => t.length >= 2);
      const eigene = appData.materialKatalog
        .filter(m => {
          const hay = ((m.bezeichnung || '') + ' ' + (m.artNr || '')).toLowerCase();
          return tokens.some(t => hay.includes(t));
        })
        .slice(0, 3)
        .map(m => ({ quelle: 'Katalog', artNr: m.artNr || '', bezeichnung: m.bezeichnung || '', einheit: m.einheit || '', ek: m.ek ?? null, lp: m.lp ?? null }));
      katTreffer.push(...eigene);
    }

    const katHtml = katTreffer.length ? `
      <div style="margin-top:14px">
        <p style="font-size:.78rem;color:#888;margin-bottom:4px">📦 Katalog-Vorschläge:</p>
        <div class="lager-pick-results">
          ${katTreffer.map((k, i) => {
            const payload = JSON.stringify(k).replace(/"/g, '&quot;');
            return `<div class="lager-pick-row" onclick="window.lagerModul._applyAiFromKatalog(JSON.parse(this.dataset.item))" data-item="${payload}">
              <span class="lager-katalog-quelle">${esc(k.quelle)}</span>
              <span class="lager-katalog-nr">${esc(k.artNr)}</span>
              <span class="lager-katalog-bez">${esc(k.bezeichnung)}</span>
              <span class="lager-katalog-einh">${esc(k.einheit)}</span>
              <span class="lager-katalog-preis">${k.ek != null ? fmtEur(k.ek) : '–'}</span>
            </div>`;
          }).join('')}
        </div>
      </div>` : '';

    showModal(`
      <h3 style="margin-top:0">🤖 KI-Vorschlag</h3>

      <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
        <div style="flex:1;height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden">
          <div style="width:${confidence}%;height:100%;background:${confColor};border-radius:4px;transition:width .4s"></div>
        </div>
        <span style="font-size:.8rem;color:${confColor};font-weight:600;white-space:nowrap">${confidence}% ${confLabel}</span>
      </div>

      <table style="width:100%;border-collapse:collapse">
        ${fieldRow('Bezeichnung', json.bezeichnung)}
        ${fieldRow('Artikel-Nr.', json.artikelnr)}
        ${fieldRow('Einheit', json.einheit)}
        ${fieldRow('Menge', json.menge != null ? json.menge : null)}
        ${fieldRow('Kategorie', json.kategorie)}
        ${fieldRow('EK-Preis', json.ek_preis != null ? fmtEur(json.ek_preis) : null)}
        ${fieldRow('Notiz', json.notiz)}
      </table>

      ${trefferHtml}
      ${katHtml}

      <div class="lager-modal-actions" style="margin-top:16px">
        <button class="btn btn-ghost" onclick="window.lagerModul._lkpBack(${targetId ?? 'null'})">← Zurück</button>
        <button class="btn btn-primary" onclick="window.lagerModul._applyAiVorschlag(window.lagerModul._state._aiJson)">✓ Übernehmen</button>
      </div>
    `);
  }

  /** Übernimmt einen Katalogeintrag gemischt mit den KI-Zusatzfeldern ins Formular. */
  function _applyAiFromKatalog(item) {
    const ai = state._aiJson || {};
    const merged = {
      bezeichnung: item.bezeichnung || '',
      artikelnr:   item.artNr       || '',
      einheit:     item.einheit     || '',
      ek_preis:    item.ek          ?? null,
      menge:       ai.menge         ?? null,
      kategorie:   ai.kategorie     || '',
      notiz:       ai.notiz         || '',
    };
    _applyAiVorschlag(merged);
  }

  /** Übernimmt den KI-Vorschlag ins offene Artikelformular. */
  function _applyAiVorschlag(json) {
    openArtikelDialog(state._lkpTargetId ?? undefined);
    setTimeout(() => {
      _fillArtikelFromKatalog({
        bezeichnung: json.bezeichnung || '',
        artikelnr:   json.artikelnr  || '',
        artNr:       json.artikelnr  || '',
        einheit:     json.einheit    || '',
        ek:          json.ek_preis ?? null,
        lp:          null,
      });
      // Zusatzfelder die _fillArtikelFromKatalog nicht kennt
      if (json.menge != null) {
        const el = document.getElementById('laMenge');
        if (el && (el.value === '' || el.value === '0')) el.value = fmtNum(json.menge);
      }
      if (json.kategorie) {
        const el = document.getElementById('laKat');
        if (el && el.value === '') el.value = json.kategorie;
      }
      if (json.notiz) {
        const el = document.getElementById('laNot');
        if (el && el.value === '') el.value = json.notiz;
      }
    }, 50);
  }

  // ── Globaler Export ─────────────────────────────────────────
  window.showLager = showLager;
  window.hideLager = hideLager;
  window.lagerModul = {
    showLager, hideLager,
    refreshAll,
    openArtikelDialog, _submitArtikel, deleteArtikel,
    openBuchungDialog, _submitBuchung,
    openOrteDialog, _addOrt, _saveOrt, _deleteOrt,
    closeModal,
    pickAndConsumeForProject, _pickConsume, _pickConfirm, _pickCancel,
    matchOffenesMaterial,
    // Katalog-Picker & OCR/KI
    _openKatalogPickerLager, _lkpSearch, _lkpPick, _lkpBack,
    _openFotoScanLager, _applyAiVorschlag, _applyAiFromKatalog,
    // State-Zugriff für externes Refresh
    _state: state,
  };
})();
