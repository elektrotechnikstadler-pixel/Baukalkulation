// MODULE_VERSION: 93
// ============================================================
// VDE 0100 Prüfprotokolle – Frontend (v0.1.0)
// ============================================================
// Protokoll-Verwaltung nach DIN VDE 0100-600.
// Struktur: Protokoll → Gebäude → Verteiler → Sicherungen.
// Canvas-Unterschrift, PDF-Export, Fixier-Funktion.
// ============================================================

(function () {
  'use strict';

  // ── State ────────────────────────────────────────────────
  const state = {
    protokolle: [],
    current: null,       // aktuell geöffnetes Protokoll (vollständiges Objekt)
    searchQ: '',
    statusFilter: '',    // '' | 'entwurf' | 'fixiert'
    sigResolve: null,    // Resolve-Callback für Signature-Modal
    sigCanvas: null,
    sigCtx: null,
    sigDrawing: false,
    sigLastX: 0,
    sigLastY: 0,
    templates: [],                 // gecachte Vorlagen (v2.7.1)
    gebaeudeCollapsed: {},         // { [gebaeudeId]: true }  (v2.7.1)
  };

  // ── Helpers ──────────────────────────────────────────────
  function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function notify(msg) {
    if (typeof window.showNotification === 'function') window.showNotification(msg);
    else console.log('[VDE0100]', msg);
  }

  function fmtVal(v) {
    if (v === null || v === undefined || v === '') return '–';
    return String(v).replace('.', ',');
  }

  // ── API ──────────────────────────────────────────────────
  async function api(sub, body, method = 'POST') {
    const url = 'api.php?action=module&module=vde0100&sub=' + encodeURIComponent(sub);
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

  // ── View-Wechsel ─────────────────────────────────────────
  function hideOtherViews() {
    const ids = [
      'emptyState','baustelleDetail','materialKatalogView','stundenKatalogView',
      'offenesMaterialView','whatsappView','schnellnotizenView','kundenstammView',
      'dienstleisterView','allgemeinSettingsView','rechnungenView','auswertungView',
      'uebersichtView','din1090View','aufmassView','lagerView','dashboardView',
    ];
    ids.forEach(id => document.getElementById(id)?.classList.add('hidden'));
    document.getElementById('vde0100View')?.classList.remove('hidden');
  }

  // ── Kunden-Selector bauen ────────────────────────────────
  function kundenOptions(selected) {
    let opts = '<option value="">– Kein Auftraggeber –</option>';
    const kd = (typeof kundenData !== 'undefined' ? kundenData : []) || [];
    kd.forEach(k => {
      const label = (k.firma || '').trim() || `${k.vorname || ''} ${k.nachname || ''}`.trim() || '(unbekannt)';
      const sel = String(selected) === String(k.id) ? ' selected' : '';
      opts += `<option value="${k.id}"${sel}>${esc(label)}</option>`;
    });
    return opts;
  }

  // ── Baustellen-Selector bauen ─────────────────────────────
  function baustellenOptions(selected) {
    let opts = '<option value="">– Keine Baustelle –</option>';
    const bd = (typeof appData !== 'undefined' ? appData.baustellen : []) || [];
    bd.forEach(b => {
      const sel = String(selected) === String(b.id) ? ' selected' : '';
      opts += `<option value="${b.id}"${sel}>${esc(b.name || '')}</option>`;
    });
    return opts;
  }

  // ── Hauptansicht öffnen ───────────────────────────────────
  async function showVde0100View() {
    const view = document.getElementById('vde0100View');
    if (!view) return;
    document.querySelectorAll('.sidebar-overview-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('btnVde0100')?.classList.add('active');
    if (typeof window.selectedId !== 'undefined') window.selectedId = null;
    if (typeof window.renderSidebar === 'function') window.renderSidebar();
    hideOtherViews();

    if (typeof appSettings !== 'undefined' && appSettings.modul_vde0100 === false) {
      view.innerHTML = '<div class="vde-empty"><div class="vde-empty-icon">⚡</div><p>Das Modul VDE 0100 ist deaktiviert.</p></div>';
      return;
    }
    await loadProtokolle();
  }

  function hideVde0100View() {
    document.getElementById('vde0100View')?.classList.add('hidden');
  }

  // ── Protokollliste laden und rendern ──────────────────────
  async function loadProtokolle() {
    const view = document.getElementById('vde0100View');
    if (!view) return;
    view.innerHTML = '<div style="padding:20px;color:#888;">⚡ Lade Protokolle…</div>';
    try {
      const j = await api('protokoll_list', {}, 'GET');
      state.protokolle = j.protokolle || [];
      renderProtokollliste();
    } catch (e) {
      view.innerHTML = `<div class="vde-empty"><div class="vde-empty-icon">⚠️</div><p>${esc(e.message)}</p></div>`;
    }
  }

  function renderProtokollliste() {
    const view = document.getElementById('vde0100View');
    if (!view) return;

    const filtered = state.protokolle.filter(p => {
      const q = state.searchQ.toLowerCase();
      const matchQ = !q || (p.titel || '').toLowerCase().includes(q) || (p.kunde_display || '').toLowerCase().includes(q);
      const matchS = !state.statusFilter || p.status === state.statusFilter;
      return matchQ && matchS;
    });

    const listHtml = filtered.length === 0
      ? `<div class="vde-empty"><div class="vde-empty-icon">📋</div><p>Keine Protokolle gefunden.</p></div>`
      : filtered.map(p => {
          const badge = p.status === 'fixiert'
            ? '<span class="vde-badge vde-badge-fixiert">✓ Fixiert</span>'
            : '<span class="vde-badge vde-badge-entwurf">✎ Entwurf</span>';
          const meta = [
            p.kunde_display ? `👤 ${esc(p.kunde_display)}` : null,
            p.b_name        ? `🏗 ${esc(p.b_name)}` : null,
            p.erstellt_am   ? `📅 ${esc((p.erstellt_am || '').substring(0,10))}` : null,
          ].filter(Boolean).join(' &nbsp;·&nbsp; ');
          return `<div class="vde-protokoll-card" onclick="window.vde0100Modul.openProtokolle(${p.id})">
            <div class="vde-card-icon">⚡</div>
            <div class="vde-card-body">
              <div class="vde-card-titel">${esc(p.titel)}</div>
              <div class="vde-card-meta">${meta || '&nbsp;'}</div>
            </div>
            <div class="vde-card-actions">
              ${badge}
              <button class="btn-vde-icon" title="Löschen" onclick="event.stopPropagation(); window.vde0100Modul.deleteProtokolle(${p.id})">🗑</button>
            </div>
          </div>`;
        }).join('');

    view.innerHTML = `
      <div class="vde-header">
        <h2>⚡ VDE 0100 Prüfprotokolle</h2>
        <button class="btn-vde-primary" onclick="window.vde0100Modul.openNeuesProtokoll()">+ Neues Protokoll</button>
      </div>
      <div class="vde-list-container">
        <div class="vde-toolbar">
          <input type="text" class="form-control" placeholder="🔍 Suche…" style="max-width:260px;font-size:.85rem"
            value="${esc(state.searchQ)}"
            oninput="window.vde0100Modul._setSearch(this.value)">
          <select class="form-control" style="max-width:160px;font-size:.85rem"
            onchange="window.vde0100Modul._setStatusFilter(this.value)">
            <option value="" ${!state.statusFilter ? 'selected' : ''}>Alle Status</option>
            <option value="entwurf" ${state.statusFilter === 'entwurf' ? 'selected' : ''}>Entwurf</option>
            <option value="fixiert" ${state.statusFilter === 'fixiert' ? 'selected' : ''}>Fixiert</option>
          </select>
        </div>
        ${listHtml}
      </div>`;
  }

  // ── Protokoll-Formular (Neu / Bearbeiten) ─────────────────
  function openNeuesProtokoll() {
    renderProtokolllForm(null);
  }

  function renderProtokolllForm(p) {
    const isEdit = !!p;
    const fixiert = p && p.status === 'fixiert';
    const dis = fixiert ? ' disabled' : '';
    const view = document.getElementById('vde0100View');
    if (!view) return;

    view.innerHTML = `
      <div class="vde-header">
        <button class="btn-vde-secondary" onclick="window.vde0100Modul.loadProtokolle()">← Zurück</button>
        <h2>${isEdit ? esc(p.titel) : 'Neues Prüfprotokoll'}</h2>
        ${isEdit && !fixiert ? `<button class="btn-vde-fixieren" onclick="window.vde0100Modul.fixieren(${p.id})">🔒 Fixieren</button>` : ''}
        ${isEdit && fixiert ? `<button class="btn-vde-secondary" onclick="window.vde0100Modul.kopierenAlsEntwurf(${p.id})">📋 Als Entwurf kopieren</button>` : ''}
        ${isEdit ? `<button class="btn-vde-secondary" onclick="window.vde0100Modul.exportPdf(${p.id})">📄 PDF</button>` : ''}
        ${isEdit ? `<button class="btn-vde-secondary" onclick="window.vde0100Modul.emailPdf(${p.id})">✉ E-Mail</button>` : ''}
      </div>
      <div class="vde-detail-container">
        <div class="vde-section">
          <div class="vde-section-header">📋 Protokoll-Kopfdaten</div>
          <div class="vde-section-body">
            <div class="vde-form-grid">
              <div class="vde-form-group vde-form-full">
                <label>Titel *</label>
                <input type="text" id="vdeTitel" value="${esc(p?.titel || '')}"${dis} placeholder="z.B. Erstprüfung EFH Musterstraße 1">
              </div>
              <div class="vde-form-group">
                <label>Auftraggeber (Kunde)</label>
                <div class="vde-search-select">
                  ${dis ? '' : `<input type="text" class="vde-search-filter" placeholder="🔍 Suchen..." oninput="window.vde0100Modul._filterSelect('vdeKundeId', this.value)">`}
                  <select id="vdeKundeId"${dis} onchange="window.vde0100Modul._onKundeChange(this.value)">
                    ${kundenOptions(p?.kundeId || '')}
                  </select>
                </div>
              </div>
              <div class="vde-form-group">
                <label>Baustelle / Projekt</label>
                <div class="vde-search-select">
                  ${dis ? '' : `<input type="text" class="vde-search-filter" placeholder="🔍 Suchen..." oninput="window.vde0100Modul._filterSelect('vdeBaustelleId', this.value)">`}
                  <select id="vdeBaustelleId"${dis} onchange="window.vde0100Modul._onBaustelleChange(this.value)">
                    ${baustellenOptions(p?.baustelleId || '')}
                  </select>
                </div>
              </div>
              <div class="vde-form-group">
                <label>Prüfnorm</label>
                <select id="vdeNorm"${dis}>
                  <option value="vde0100_600"${(p?.norm||'vde0100_600') === 'vde0100_600' ? ' selected' : ''}>DIN VDE 0100-600 (HD 60364-6 / IEC 60364-6) – Erst- &amp; Wiederholungsprüfung</option>
                  <option value="vde0105"${(p?.norm||'') === 'vde0105' ? ' selected' : ''}>DIN VDE 0105-100 (EN 50110-1) – Betrieb elektrischer Anlagen</option>
                  <option value="dguv_v3"${(p?.norm||'') === 'dguv_v3' ? ' selected' : ''}>DGUV Vorschrift 3 (BGV A3) – Elektrische Betriebsmittel</option>
                </select>
              </div>
              <div class="vde-form-group">
                <label>Projektnummer</label>
                <input type="text" id="vdeProjektnummer" value="${esc(p?.projektnummer || '')}"${dis} placeholder="z.B. B-2024-001 (leer = aus Baustelle)">
              </div>
              <div class="vde-form-group">
                <label>Anlagenart</label>
                <select id="vdeAnlagenart"${dis}>
                  ${['','Hausinstallation','Industrieanlage','Gewerbe','Außenanlage','Sonstige']
                    .map(v => `<option value="${v}"${(p?.anlagenart||'') === v ? ' selected' : ''}>${v || '– Keine Angabe –'}</option>`).join('')}
                </select>
              </div>
              <div class="vde-form-group">
                <label>Netzform</label>
                <select id="vdeSchutzmassnahme"${dis}>
                  ${['','TN-S','TN-C','TN-C-S','TT','IT']
                    .map(v => `<option value="${v}"${(p?.schutzmassnahme||'') === v ? ' selected' : ''}>${v || '– Keine Angabe –'}</option>`).join('')}
                </select>
              </div>
              <div class="vde-form-group">
                <label>Nennspannung (V)</label>
                <input type="text" id="vdeNennspannung" value="${esc(p?.nennspannung || '230')}"${dis}>
              </div>
              <div class="vde-form-group">
                <label>Nennfrequenz (Hz)</label>
                <input type="text" id="vdeNennfrequenz" value="${esc(p?.nennfrequenz || '50')}"${dis}>
              </div>
              <div class="vde-form-group">
                <label>Nennstrom (A)</label>
                <input type="text" id="vdeNennstrom" value="${esc(p?.nennstrom || '')}"${dis} placeholder="z.B. 63">
              </div>
              <div class="vde-form-group">
                <label>Prüfdatum</label>
                <input type="date" id="vdePruefDatum" value="${esc(p?.pruef_datum || '')}"${isEdit && fixiert ? ` onchange="window.vde0100Modul._savePruefDatum(${p.id}, this.value)"` : ''}>
              </div>
              <div class="vde-form-group">
                <label>Hersteller Messgerät</label>
                <input type="text" id="vdeMessgeraetHersteller" value="${esc(p?.messgeraet_hersteller || '')}"${dis} placeholder="z.B. Fluke, Metrel">
              </div>
              <div class="vde-form-group">
                <label>Typ Messgerät</label>
                <input type="text" id="vdeMessgeraetTyp" value="${esc(p?.messgeraet_typ || '')}"${dis} placeholder="z.B. 1664 FC">
              </div>
              <div class="vde-form-group">
                <label>Letzte Kalibrierung</label>
                <input type="date" id="vdeMessgeraetKalibrierung" value="${esc(p?.messgeraet_kalibrierung || '')}"${dis}>
              </div>
              <div class="vde-form-group vde-form-full">
                <label>Bemerkung</label>
                <textarea id="vdeBemerkung"${dis}>${esc(p?.bemerkung || '')}</textarea>
              </div>
              <div class="vde-form-group">
                <label>Sichtprüfung</label>
                <select id="vdeSichtpruefungStatus"${dis}>
                  ${['','ok','fehler','ausstehend'].map(v => `<option value="${v}"${(p?.sichtpruefung_status||'') === v ? ' selected' : ''}>${v === '' ? '– Nicht bewertet –' : v === 'ok' ? '✓ Bestanden' : v === 'fehler' ? '✗ Mängel festgestellt' : '◯ Ausstehend'}</option>`).join('')}
                </select>
              </div>
              <div class="vde-form-group">
                <label>Sichtprüfung Bemerkung</label>
                <input type="text" id="vdeSichtpruefungBem" value="${esc(p?.sichtpruefung_bem || '')}"${dis}>
              </div>
              <div class="vde-form-group">
                <label>Funktionsprüfung</label>
                <select id="vdeFunktionspruefungStatus"${dis}>
                  ${['','ok','fehler','ausstehend'].map(v => `<option value="${v}"${(p?.funktionspruefung_status||'') === v ? ' selected' : ''}>${v === '' ? '– Nicht bewertet –' : v === 'ok' ? '✓ Bestanden' : v === 'fehler' ? '✗ Mängel festgestellt' : '◯ Ausstehend'}</option>`).join('')}
                </select>
              </div>
              <div class="vde-form-group">
                <label>Funktionsprüfung Bemerkung</label>
                <input type="text" id="vdeFunktionspruefungBem" value="${esc(p?.funktionspruefung_bem || '')}"${dis}>
              </div>
              <div class="vde-form-group vde-form-full">
                <label>Kundenanschrift</label>
                <textarea id="vdeKundenanschrift" rows="2"${dis} placeholder="Wird automatisch aus Kundenstamm übernommen, änderbar">${esc(p?.kundenanschrift || '')}</textarea>
              </div>
              <div class="vde-form-group vde-form-full">
                <label>Anlagenanschrift</label>
                <textarea id="vdeAnlagenanschrift" rows="2"${dis} placeholder="Anschrift der elektrischen Anlage">${esc(p?.anlagenanschrift || '')}</textarea>
              </div>
              <div class="vde-form-group">
                <label>Netzbetreiber</label>
                <input type="text" id="vdeNetzbetreiber" value="${esc(p?.netzbetreiber || '')}"${dis} placeholder="z.B. Bayernwerk AG">
              </div>
            </div>
            ${fixiert ? '' : `
              <div style="margin-top:12px;display:flex;gap:8px;">
                <button class="btn-vde-primary" onclick="window.vde0100Modul.saveProtokolllForm(${isEdit ? p.id : 'null'})">💾 Speichern</button>
                <button class="btn-vde-secondary" onclick="window.vde0100Modul.loadProtokolle()">Abbrechen</button>
              </div>`}
          </div>
        </div>

        ${isEdit ? renderGebaeudeTree(p, fixiert) : ''}
      </div>`;
  }

  // ── Gebäude-Baum ─────────────────────────────────────────
  function renderGebaeudeTree(p, fixiert) {
    const gebaeudeHtml = (p.gebaeude || []).map(g => renderGebaeude(g, fixiert)).join('');
    return `
      <div class="vde-section" id="vdeBaumSection">
        <div class="vde-section-header">🏠 Gebäude &amp; Verteiler</div>
        <div class="vde-section-body">
          ${fixiert ? '' : `
            <div class="vde-toolbar">
              <button class="btn-vde-primary" onclick="window.vde0100Modul.addGebaeude(${p.id})">+ Gebäude</button>
            </div>`}
          ${gebaeudeHtml || '<p style="font-size:.85rem;color:#888;">Noch keine Gebäude angelegt.</p>'}
        </div>
      </div>`;
  }

  function renderGebaeude(g, fixiert) {
    const verteilHtml = (g.verteiler || []).map(v => renderVerteiler(v, fixiert)).join('');
    const collapsed = !!state.gebaeudeCollapsed[g.id];
    const toggleIcon = collapsed ? '▸' : '▾';
    return `
      <div class="vde-gebaeude-block" id="vde-g-${g.id}">
        <div class="vde-gebaeude-header" onclick="window.vde0100Modul.toggleGebaeude(${g.id})" style="cursor:pointer;">
          <span id="vde-g-toggle-${g.id}" class="vde-gebaeude-toggle${collapsed ? '' : ' vde-open'}">${toggleIcon}</span>
          🏠 <span>${esc(g.bezeichnung)}</span>
          <span style="flex:1"></span>
          ${fixiert ? '' : `
            <button class="btn-vde-icon" title="Gruppe aus Vorlage einfügen" onclick="event.stopPropagation();window.vde0100Modul.openTemplatePickerModal(${g.id}, ${g.protokollId})">📑</button>
            <button class="btn-vde-icon" title="Verteiler hinzufügen" onclick="event.stopPropagation();window.vde0100Modul.addVerteiler(${g.id}, ${g.protokollId})">+ Verteiler</button>
            <button class="btn-vde-icon" title="Verteiler per Foto erkennen (KI)" onclick="event.stopPropagation();window.vde0100Modul.openVerteilerKiScan(${g.id}, ${g.protokollId})">🤖 KI-Verteiler</button>
            <button class="btn-vde-icon" title="Umbenennen" onclick="event.stopPropagation();window.vde0100Modul.editGebaeude(${g.id}, ${g.protokollId}, '${esc(g.bezeichnung)}')">✎</button>
            <button class="btn-vde-icon" title="Löschen" onclick="event.stopPropagation();window.vde0100Modul.deleteGebaeude(${g.id}, ${g.protokollId})">🗑</button>
          `}
        </div>
        <div class="vde-gebaeude-body${collapsed ? ' vde-collapsed' : ''}" id="vde-g-body-${g.id}">
          ${verteilHtml || '<p style="font-size:.82rem;color:#aaa;padding:4px 8px;">Noch keine Verteiler.</p>'}
        </div>
      </div>`;
  }

  function renderVerteiler(v, fixiert) {
    const rcdHtml = (v.rcd || []).map(r => renderRcd(r, fixiert)).join('');
    const direktSicherungen = v.sicherungenDirekt || [];
    const direktHtml = direktSicherungen.length > 0
      ? `<div class="vde-rcd-block" style="border-left:3px solid #bbb;">
           <div class="vde-rcd-header" style="background:#f0f0f0;color:#555;">⚡ Abgänge ohne RCD</div>
           <div class="vde-rcd-body">
             ${renderSicherungenTable(direktSicherungen, null, fixiert)}
             ${fixiert ? '' : `<div style="margin-top:6px;"><button class="btn-vde-secondary" style="font-size:.78rem;padding:4px 8px" onclick="window.vde0100Modul.addSicherungDirekt(${v.id})">+ Abgang</button></div>`}
           </div>
         </div>`
      : '';
    return `
      <div class="vde-verteiler-block" id="vde-v-${v.id}">
        <div class="vde-verteiler-header">
          ⚡ ${esc(v.bezeichnung)}${v.nennstrom ? ' · ' + v.nennstrom + ' A' : ''}
          <span style="flex:1"></span>
          ${fixiert ? '' : `
            <button class="btn-vde-icon" title="Als Vorlage speichern" onclick="window.vde0100Modul.openSaveTemplateModal(${v.id})">📑</button>
            <button class="btn-vde-icon" title="Bearbeiten" onclick="window.vde0100Modul.editVerteiler(${v.id}, ${v.gebaeudeId})">✎</button>
            <button class="btn-vde-icon" title="Löschen" onclick="window.vde0100Modul.deleteVerteiler(${v.id}, ${v.gebaeudeId})">🗑</button>
          `}
        </div>
        <div class="vde-verteiler-body">
          ${rcdHtml}
          ${direktHtml}
          ${fixiert ? '' : `<div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;">
            <button class="btn-vde-secondary" style="font-size:.78rem;padding:4px 8px" onclick="window.vde0100Modul.addRcd(${v.id})">+ FI/RCD-Block</button>
            <button class="btn-vde-secondary" style="font-size:.78rem;padding:4px 8px" onclick="window.vde0100Modul.addSicherungDirekt(${v.id})">+ Abgang (ohne RCD)</button>
          </div>`}
        </div>
      </div>`;
  }

  function renderRcd(r, fixiert) {
    const rcdInfo = [
      r.nennstrom       != null ? r.nennstrom       + ' A'   : '',
      r.nennfehlerstrom != null ? r.nennfehlerstrom + ' mA'  : '',
      r.typ             ? 'Typ ' + esc(r.typ) : '',
    ].filter(Boolean).join(' / ');
    const dis = fixiert ? ' disabled' : '';
    const sicherungenHtml = renderSicherungenTable(r.sicherungen || [], r.id, fixiert);
    const rcdMessHtml = `
      <div class="vde-rcd-mess-row">
        <span class="vde-rcd-mess-label">⚡ FI-Messung:</span>
        <label>Auslösestrom I&#916;N (mA)
          <input type="number" step="0.1" inputmode="decimal" value="${r.messung_rcd_id ?? ''}"${dis}
            onchange="window.vde0100Modul.saveRcdField(${r.id}, 'messung_rcd_id', this.value)" style="width:70px">
        </label>
        <label>Auslösezeit t&#916; (ms)
          <input type="number" step="0.1" inputmode="decimal" value="${r.messung_rcd_tt ?? ''}"${dis}
            onchange="window.vde0100Modul.saveRcdField(${r.id}, 'messung_rcd_tt', this.value)" style="width:70px">
        </label>
      </div>`;
    return `
      <div class="vde-rcd-block" id="vde-rcd-${r.id}">
        <div class="vde-rcd-header">
          ⚡ ${esc(r.bezeichnung)}${rcdInfo ? ' <span style="font-weight:normal;font-size:.8em;color:#444;">(' + rcdInfo + ')</span>' : ''}
          <span style="flex:1"></span>
          ${fixiert ? '' : `
            <button class="btn-vde-icon" title="Bearbeiten" onclick="window.vde0100Modul.editRcd(${r.id}, ${r.verteilerId})">✎</button>
            <button class="btn-vde-icon" title="Löschen" onclick="window.vde0100Modul.deleteRcd(${r.id}, ${r.verteilerId})">🗑</button>
          `}
        </div>
        <div class="vde-rcd-body">
          ${rcdMessHtml}
          ${sicherungenHtml}
          ${fixiert ? '' : `<div style="margin-top:6px;"><button class="btn-vde-secondary" style="font-size:.78rem;padding:4px 8px" onclick="window.vde0100Modul.addSicherung(${r.id})">+ Abgang</button></div>`}
        </div>
      </div>`;
  }

  function renderSicherungenTable(sicherungen, rcdId, fixiert) {
    if (sicherungen.length === 0) {
      return '<p style="font-size:.8rem;color:#aaa;padding:4px;">Keine Abgänge.</p>';
    }
    const dis = fixiert ? ' disabled' : '';
    const rows = sicherungen.map(s => {
      const statusOpts = ['','ok','fehler','ausstehend'].map(st =>
        `<option value="${st}"${s.pruefstatus === st ? ' selected' : ''}>${st || '–'}</option>`
      ).join('');
      return `<tr>
        <td data-label="Bezeichnung"><input class="vde-bez-input" type="text" value="${esc(s.bezeichnung)}"${dis} onchange="window.vde0100Modul.saveSicherungField(${s.id}, 'bezeichnung', this.value)"></td>
        <td data-label="Typ">
          <select${dis} onchange="window.vde0100Modul.saveSicherungField(${s.id}, 'typ', this.value)">
            ${['','LS','NH','FI/RCCB','RCD','Leitungsschutz','Steckdose','Sonstige'].map(t =>
              `<option value="${t}"${s.typ === t ? ' selected' : ''}>${t || '–'}</option>`).join('')}
          </select>
        </td>
        <td data-label="Nennstrom A"><input type="number" step="0.1" inputmode="decimal" value="${s.nennstrom ?? ''}"${dis} onchange="window.vde0100Modul.saveSicherungField(${s.id}, 'nennstrom', this.value)" style="width:60px;text-align:right"></td>
        <td data-label="Leiter mm²"><input type="text" inputmode="decimal" value="${esc(s.leiterquerschnitt||'')}"${dis} onchange="window.vde0100Modul.saveSicherungField(${s.id}, 'leiterquerschnitt', this.value)" style="width:55px"></td>
        <td data-label="Iso-R MΩ"><input type="number" step="0.01" inputmode="decimal" value="${s.messung_iso ?? ''}"${dis} onchange="window.vde0100Modul.saveSicherungField(${s.id}, 'messung_iso', this.value)" style="width:65px;text-align:right"></td>
        <td data-label="Zs Ω"><input type="number" step="0.001" inputmode="decimal" value="${s.messung_zs ?? ''}"${dis} onchange="window.vde0100Modul.saveSicherungField(${s.id}, 'messung_zs', this.value)" style="width:65px;text-align:right"></td>
        <td data-label="Zi Ω"><input type="number" step="0.001" inputmode="decimal" value="${s.messung_zi ?? ''}"${dis} onchange="window.vde0100Modul.saveSicherungField(${s.id}, 'messung_zi', this.value)" style="width:65px;text-align:right"></td>
        <td data-label="Polarität">
          <select${dis} onchange="window.vde0100Modul.saveSicherungField(${s.id}, 'messung_polaritaet', this.value)" class="vde-status-${s.messung_polaritaet || 'leer'}">
            <option value=""${!s.messung_polaritaet ? ' selected' : ''}>–</option>
            <option value="ok"${s.messung_polaritaet === 'ok' ? ' selected' : ''}>✓ OK</option>
            <option value="fehler"${s.messung_polaritaet === 'fehler' ? ' selected' : ''}>✗ Fehler</option>
          </select>
        </td>
        <td data-label="Re Ω"><input type="number" step="0.001" inputmode="decimal" value="${s.messung_re ?? ''}"${dis} onchange="window.vde0100Modul.saveSicherungField(${s.id}, 'messung_re', this.value)" style="width:60px;text-align:right"></td>
        <td data-label="Status">
          <select${dis} onchange="window.vde0100Modul.saveSicherungField(${s.id}, 'pruefstatus', this.value)" class="vde-status-${s.pruefstatus || 'leer'}">
            ${statusOpts}
          </select>
        </td>
        ${fixiert ? '' : `<td data-label=""><button class="btn-vde-icon" onclick="window.vde0100Modul.deleteSicherung(${s.id}, ${rcdId})">🗑</button></td>`}
      </tr>`;
    }).join('');

    const delHeader = fixiert ? '' : '<th></th>';
    return `
      <p class="vde-table-scroll-hint">← Tabelle scrollen →</p>
      <div class="vde-table-wrap">
        <table class="vde-sicherungen-table">
          <thead>
            <tr>
              <th>Bezeichnung</th><th>Typ</th><th>Nennstrom A</th><th>Leiter mm²</th>
              <th>Iso-R MΩ</th><th>Zs Ω</th><th>Zi Ω</th><th>Polarität</th>
              <th>Re Ω</th><th>Status</th>${delHeader}
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>`;
  }

  // ── Protokoll speichern ────────────────────────────────────
  async function saveProtokolllForm(existingId) {
    const getVal = id => document.getElementById(id)?.value ?? '';
    const body = {
      titel:           getVal('vdeTitel').trim(),
      kundeId:         getVal('vdeKundeId'),
      baustelleId:     getVal('vdeBaustelleId'),
      norm:            getVal('vdeNorm') || 'vde0100_600',
      projektnummer:   getVal('vdeProjektnummer').trim(),
      anlagenart:      getVal('vdeAnlagenart'),
      schutzmassnahme: getVal('vdeSchutzmassnahme'),
      nennspannung:    getVal('vdeNennspannung'),
      nennfrequenz:    getVal('vdeNennfrequenz'),
      nennstrom:       getVal('vdeNennstrom'),
      pruef_datum:              getVal('vdePruefDatum'),
      bemerkung:                getVal('vdeBemerkung'),
      sichtpruefung_status:     getVal('vdeSichtpruefungStatus'),
      sichtpruefung_bem:        getVal('vdeSichtpruefungBem').trim(),
      funktionspruefung_status: getVal('vdeFunktionspruefungStatus'),
      funktionspruefung_bem:    getVal('vdeFunktionspruefungBem').trim(),
      messgeraet_hersteller:    getVal('vdeMessgeraetHersteller').trim(),
      messgeraet_typ:           getVal('vdeMessgeraetTyp').trim(),
      messgeraet_kalibrierung:  getVal('vdeMessgeraetKalibrierung'),
      anlagenanschrift:         (document.getElementById('vdeAnlagenanschrift')?.value || '').trim(),
      kundenanschrift:          (document.getElementById('vdeKundenanschrift')?.value || '').trim(),
      netzbetreiber:            getVal('vdeNetzbetreiber').trim(),
    };
    if (!body.titel) { notify('Bitte Titel angeben.'); return; }
    if (existingId) body.id = existingId;
    try {
      const j = await api('protokoll_save', body);
      notify('Protokoll gespeichert.');
      await openProtokolle(j.id);
    } catch (e) {
      notify('Fehler: ' + e.message);
    }
  }

  // ── Protokoll löschen ─────────────────────────────────────
  async function deleteProtokolle(id) {
    const p = state.protokolle.find(x => x.id === id);
    if (!confirm(`Protokoll "${p?.titel || id}" wirklich löschen?`)) return;
    try {
      await api('protokoll_delete', { id });
      notify('Protokoll gelöscht.');
      await loadProtokolle();
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  // ── Protokoll öffnen (Detail) ──────────────────────────────
  async function openProtokolle(id, keepScroll) {
    const view = document.getElementById('vde0100View');
    if (!view) return;
    const savedScroll = keepScroll ? (view.scrollTop || 0) : 0;
    if (!keepScroll) view.innerHTML = '<div style="padding:20px;color:#888;">⚡ Lade Protokoll…</div>';
    try {
      const j = await api('protokoll_get', { id }, 'POST');
      state.current = j.protokoll;
      renderProtokolllForm(j.protokoll);
      if (keepScroll && savedScroll > 0) {
        requestAnimationFrame(() => {
          const v = document.getElementById('vde0100View');
          if (v) v.scrollTop = savedScroll;
        });
      }
    } catch (e) {
      notify('Fehler: ' + e.message);
      await loadProtokolle();
    }
  }

  // ── Protokoll fixieren ────────────────────────────────────
  async function fixieren(id) {
    if (!confirm('Protokoll fixieren (sperren)? Danach sind keine Änderungen mehr möglich.')) return;
    const sig = await openSignatureModal();
    try {
      await api('protokoll_fixieren', { id, unterschrift: sig || '' });
      notify('Protokoll fixiert.');
      await openProtokolle(id);
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  // ── Protokoll als Entwurf kopieren ──────────────────────
  async function kopierenAlsEntwurf(id) {
    const p = state.protokolle.find(x => x.id === id) || state.current;
    if (!confirm(`Fixiertes Protokoll „${p?.titel || id}" als neuen Entwurf kopieren?`)) return;
    try {
      const j = await api('protokoll_kopieren', { id });
      notify('Kopie als Entwurf angelegt.');
      await loadProtokolle();
      await openProtokolle(j.newId);
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  // ── PDF exportieren ───────────────────────────────────────
  async function exportPdf(id) {
    const url = 'api.php?action=module&module=vde0100&sub=protokoll_pdf';
    try {
      const r = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id }),
      });
      if (!r.ok) {
        const j = await r.json().catch(() => ({}));
        throw new Error(j.error || ('HTTP ' + r.status));
      }
      const blob = await r.blob();
      const objUrl = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = objUrl;
      a.download = 'VDE0100_Protokoll_' + id + '.pdf';
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(objUrl);
    } catch (e) { notify('PDF Fehler: ' + e.message); }
  }

  // ── PDF per E-Mail senden ─────────────────────────────────
  async function emailPdf(id) {
    // Kundendaten aus Protokoll laden, um Standardempfänger zu ermitteln
    let defaultTo = '';
    try {
      const p = state.protokolle.find(x => x.id === id);
      if (p && p.kundeEmail) defaultTo = p.kundeEmail;
    } catch (e) {}
    if (typeof openEmailDialog === 'function') {
      openEmailDialog(defaultTo, 'VDE 0100 Prüfprotokoll', async (to, subject) => {
        try {
          const r = await fetch('api.php?action=send_vde', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ protokollId: id, to, subject }),
          });
          const j = await r.json();
          if (j.ok) notify('E-Mail gesendet.');
          else notify('Fehler: ' + (j.error || 'Unbekannter Fehler'));
        } catch (e) { notify('Netzwerkfehler: ' + e.message); }
      });
    } else {
      notify('E-Mail-Funktion nicht verfügbar.');
    }
  }

  // ── Gebäude CRUD ─────────────────────────────────────────
  async function addGebaeude(protokollId) {
    const bez = prompt('Gebäude-Bezeichnung:');
    if (!bez || !bez.trim()) return;
    try {
      await api('gebaeude_save', { protokollId, bezeichnung: bez.trim(), sortPos: 0 });
      await openProtokolle(protokollId, true);
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  async function editGebaeude(gebaeudeId, protokollId, currentName) {
    const bez = prompt('Neue Bezeichnung:', currentName);
    if (!bez || !bez.trim()) return;
    try {
      await api('gebaeude_save', { id: gebaeudeId, protokollId, bezeichnung: bez.trim() });
      await openProtokolle(protokollId, true);
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  async function deleteGebaeude(gebaeudeId, protokollId) {
    if (!confirm('Gebäude und alle enthaltenen Verteiler/Abgänge löschen?')) return;
    try {
      await api('gebaeude_delete', { id: gebaeudeId, protokollId });
      await openProtokolle(protokollId);
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  // ── Verteiler CRUD ────────────────────────────────────────
  async function addVerteiler(gebaeudeId, protokollId) {
    openVerteilerModal(null, gebaeudeId, protokollId);
  }

  async function editVerteiler(verteilerId, gebaeudeId) {
    const p = state.current;
    if (!p) return;
    let verteiler = null;
    for (const g of p.gebaeude || []) {
      verteiler = (g.verteiler || []).find(v => v.id === verteilerId) || null;
      if (verteiler) break;
    }
    openVerteilerModal(verteiler, gebaeudeId, p.id);
  }

  function openVerteilerModal(v, gebaeudeId, protokollId) {
    const isEdit = !!v;
    const overlay = document.createElement('div');
    overlay.id = 'vdeVerteilerModal';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;display:flex;align-items:center;justify-content:center;';
    overlay.innerHTML = `
      <div style="background:#fff;border-radius:10px;padding:20px;width:380px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,.25);">
        <h3 style="margin:0 0 12px;font-size:1rem;">⚡ Verteiler ${isEdit ? 'bearbeiten' : 'hinzufügen'}</h3>
        <div class="vde-form-grid">
          <div class="vde-form-group vde-form-full">
            <label>Bezeichnung *</label>
            <input type="text" id="vdeVBez" value="${esc(v?.bezeichnung || '')}">
          </div>
          <div class="vde-form-group">
            <label>Nennstrom (A)</label>
            <input type="number" step="0.1" id="vdeVNennstrom" value="${v?.nennstrom ?? ''}">
          </div>
          <div class="vde-form-group vde-form-full">
            <label>Bemerkung</label>
            <textarea id="vdeVBemerkung" style="min-height:50px">${esc(v?.bemerkung || '')}</textarea>
          </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:12px;justify-content:flex-end;">
          <button class="btn-vde-secondary" onclick="document.getElementById('vdeVerteilerModal').remove()">Abbrechen</button>
          <button class="btn-vde-primary" onclick="window.vde0100Modul._saveVerteilerModal(${isEdit ? v.id : 'null'}, ${gebaeudeId}, ${protokollId})">Speichern</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
  }

  async function _saveVerteilerModal(verteilerId, gebaeudeId, protokollId) {
    const getVal = id => document.getElementById(id)?.value ?? '';
    const body = {
      gebaeudeId,
      bezeichnung: getVal('vdeVBez').trim(),
      nennstrom:   getVal('vdeVNennstrom'),
      bemerkung:   getVal('vdeVBemerkung').trim(),
    };
    if (!body.bezeichnung) { notify('Bezeichnung ist erforderlich.'); return; }
    if (verteilerId) body.id = verteilerId;
    try {
      await api('verteiler_save', body);
      document.getElementById('vdeVerteilerModal')?.remove();
      await openProtokolle(protokollId, true);
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  async function deleteVerteiler(verteilerId, gebaeudeId) {
    if (!confirm('Verteiler und alle Abgänge löschen?')) return;
    const p = state.current;
    try {
      await api('verteiler_delete', { id: verteilerId });
      if (p) await openProtokolle(p.id, true);
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  // ── Sicherungen CRUD ─────────────────────────────────────
  async function addSicherung(rcdId) {
    try {
      await api('sicherung_save', { rcdId, bezeichnung: 'Neuer Abgang', sortPos: 0 });
      if (state.current) await openProtokolle(state.current.id, true);
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  async function addSicherungDirekt(verteilerId) {
    try {
      await api('sicherung_save', { verteilerId, rcdId: null, bezeichnung: 'Neuer Abgang', sortPos: 0 });
      if (state.current) await openProtokolle(state.current.id, true);
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  async function saveSicherungField(sicherungId, field, value) {
    const body = { id: sicherungId };
    const p = state.current;
    if (p) {
      outer: for (const g of p.gebaeude || []) {
        for (const v of g.verteiler || []) {
          for (const r of v.rcd || []) {
            const s = (r.sicherungen || []).find(s => s.id === sicherungId);
            if (s) {
              // Vollständige Zeile senden, um Datenverlust bei Partial-Update zu vermeiden
              body.rcdId              = r.id;
              body.bezeichnung        = s.bezeichnung        ?? '';
              body.typ                = s.typ                ?? '';
              body.nennstrom          = s.nennstrom          ?? '';
              body.leiterquerschnitt  = s.leiterquerschnitt  ?? '';
              body.messung_rb         = s.messung_rb         ?? '';
              body.messung_iso        = s.messung_iso        ?? '';
              body.messung_zs         = s.messung_zs         ?? '';
              body.messung_zi         = s.messung_zi         ?? '';
              body.messung_polaritaet = s.messung_polaritaet ?? '';
              body.messung_re         = s.messung_re         ?? '';
              body.pruefstatus        = s.pruefstatus        ?? '';
              body.bemerkung          = s.bemerkung          ?? '';
              body[field]             = value; // Override mit neuem Wert
              break outer;
            }
          }
          // Abgänge direkt am Verteiler (ohne RCD)
          const sd = (v.sicherungenDirekt || []).find(s => s.id === sicherungId);
          if (sd) {
            body.verteilerId        = v.id;
            body.rcdId              = null;
            body.bezeichnung        = sd.bezeichnung        ?? '';
            body.typ                = sd.typ                ?? '';
            body.nennstrom          = sd.nennstrom          ?? '';
            body.leiterquerschnitt  = sd.leiterquerschnitt  ?? '';
            body.messung_rb         = sd.messung_rb         ?? '';
            body.messung_iso        = sd.messung_iso        ?? '';
            body.messung_zs         = sd.messung_zs         ?? '';
            body.messung_zi         = sd.messung_zi         ?? '';
            body.messung_polaritaet = sd.messung_polaritaet ?? '';
            body.messung_re         = sd.messung_re         ?? '';
            body.pruefstatus        = sd.pruefstatus        ?? '';
            body.bemerkung          = sd.bemerkung          ?? '';
            body[field]             = value;
            break outer;
          }
        }
      }
    }
    if (!body.rcdId && !body.verteilerId) return;
    try {
      await api('sicherung_save', body);
      // State aktualisieren ohne vollen Reload
      if (p) {
        for (const g of p.gebaeude || []) {
          for (const v of g.verteiler || []) {
            for (const r of v.rcd || []) {
              const s = (r.sicherungen || []).find(s => s.id === sicherungId);
              if (s) { s[field] = value === '' ? null : (isNaN(Number(value)) ? value : Number(value)); return; }
            }
            const sd = (v.sicherungenDirekt || []).find(s => s.id === sicherungId);
            if (sd) { sd[field] = value === '' ? null : (isNaN(Number(value)) ? value : Number(value)); return; }
          }
        }
      }
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  async function saveRcdField(rcdId, field, value) {
    const p = state.current;
    if (!p) return;
    let rcd = null;
    outer: for (const g of p.gebaeude || []) {
      for (const v of g.verteiler || []) {
        rcd = (v.rcd || []).find(r => r.id === rcdId) || null;
        if (rcd) break outer;
      }
    }
    if (!rcd) return;
    const body = {
      id:              rcdId,
      verteilerId:     rcd.verteilerId,
      bezeichnung:     rcd.bezeichnung     || 'RCD',
      nennstrom:       rcd.nennstrom       ?? '',
      nennfehlerstrom: rcd.nennfehlerstrom ?? '',
      typ:             rcd.typ             || '',
      bemerkung:       rcd.bemerkung       || '',
      messung_rcd_id:  rcd.messung_rcd_id  ?? '',
      messung_rcd_tt:  rcd.messung_rcd_tt  ?? '',
      [field]:         value,
    };
    try {
      await api('rcd_save', body);
      rcd[field] = value === '' ? null : (isNaN(Number(value)) ? value : Number(value));
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  async function deleteSicherung(sicherungId, rcdId) {
    try {
      await api('sicherung_delete', { id: sicherungId });
      if (state.current) await openProtokolle(state.current.id, true);
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  // ── RCD CRUD ──────────────────────────────────────────────
  async function addRcd(verteilerId) {
    openRcdModal(null, verteilerId, state.current?.id);
  }

  async function editRcd(rcdId, verteilerId) {
    const p = state.current;
    if (!p) return;
    let rcd = null;
    for (const g of p.gebaeude || []) {
      for (const v of g.verteiler || []) {
        rcd = (v.rcd || []).find(r => r.id === rcdId) || null;
        if (rcd) break;
      }
      if (rcd) break;
    }
    openRcdModal(rcd, verteilerId, p.id);
  }

  function openRcdModal(r, verteilerId, protokollId) {
    const isEdit = !!r;
    document.getElementById('vdeRcdModal')?.remove();
    const overlay = document.createElement('div');
    overlay.id = 'vdeRcdModal';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;display:flex;align-items:center;justify-content:center;';
    overlay.innerHTML = `
      <div style="background:#fff;border-radius:10px;padding:20px;width:400px;max-width:95vw;box-shadow:0 8px 32px rgba(0,0,0,.25);">
        <h3 style="margin:0 0 12px;font-size:1rem;">⚡ FI/RCD-Block ${isEdit ? 'bearbeiten' : 'hinzufügen'}</h3>
        <div class="vde-form-grid">
          <div class="vde-form-group vde-form-full">
            <label>Bezeichnung *</label>
            <input type="text" id="vdeRBez" value="${esc(r?.bezeichnung || 'FI')}" placeholder="z.B. FI 40A/30mA Typ A">
          </div>
          <div class="vde-form-group">
            <label>Nennstrom (A)</label>
            <input type="number" step="0.1" id="vdeRNennstrom" value="${r?.nennstrom ?? ''}">
          </div>
          <div class="vde-form-group">
            <label>Nennfehlerstrom (mA)</label>
            <input type="number" step="1" id="vdeRNennfehler" value="${r?.nennfehlerstrom ?? ''}">
          </div>
          <div class="vde-form-group">
            <label>Typ</label>
            <select id="vdeRTyp">
              ${['','A','B','F'].map(t => `<option value="${t}"${(r?.typ||'') === t ? ' selected' : ''}>${t || '–'}</option>`).join('')}
            </select>
          </div>
          <div class="vde-form-group">
            <label>Bemerkung</label>
            <input type="text" id="vdeRBemerkung" value="${esc(r?.bemerkung || '')}">
          </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:12px;justify-content:flex-end;">
          <button class="btn-vde-secondary" onclick="document.getElementById('vdeRcdModal').remove()">Abbrechen</button>
          <button class="btn-vde-primary" onclick="window.vde0100Modul._saveRcdModal(${isEdit ? r.id : 'null'}, ${verteilerId}, ${protokollId})">Speichern</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);
    setTimeout(() => document.getElementById('vdeRBez')?.focus(), 50);
  }

  async function _saveRcdModal(rcdId, verteilerId, protokollId) {
    const getVal = id => document.getElementById(id)?.value ?? '';
    const body = {
      verteilerId,
      bezeichnung:     getVal('vdeRBez').trim(),
      nennstrom:       getVal('vdeRNennstrom'),
      nennfehlerstrom: getVal('vdeRNennfehler'),
      typ:             getVal('vdeRTyp'),
      bemerkung:       getVal('vdeRBemerkung').trim(),
    };
    if (!body.bezeichnung) { notify('Bezeichnung ist erforderlich.'); return; }
    if (rcdId) body.id = rcdId;
    try {
      await api('rcd_save', body);
      document.getElementById('vdeRcdModal')?.remove();
      await openProtokolle(protokollId, true);
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  async function deleteRcd(rcdId, verteilerId) {
    if (!confirm('FI/RCD-Block und alle Abgänge löschen?')) return;
    const p = state.current;
    try {
      await api('rcd_delete', { id: rcdId });
      if (p) await openProtokolle(p.id, true);
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  // ── Canvas-Unterschrift ───────────────────────────────────
  function openSignatureModal() {
    return new Promise(resolve => {
      state.sigResolve = resolve;
      const overlay = document.createElement('div');
      overlay.id = 'vdeSignatureModal';
      overlay.innerHTML = `
        <div class="vde-sig-box">
          <h3>✍ Unterschrift</h3>
          <p style="font-size:.8rem;color:#777;margin-bottom:8px;">Bitte mit Maus oder Finger unterschreiben.</p>
          <canvas id="vdeSignatureCanvas" width="380" height="180"></canvas>
          <div class="vde-sig-actions">
            <button class="btn-vde-secondary" onclick="window.vde0100Modul._sigClear()">Löschen</button>
            <button class="btn-vde-secondary" onclick="window.vde0100Modul._sigFullscreen()">⤢ Vollbild</button>
            <button class="btn-vde-secondary" onclick="window.vde0100Modul._sigSkip()">Ohne Unterschrift</button>
            <button class="btn-vde-primary" onclick="window.vde0100Modul._sigSave()">✓ Übernehmen</button>
          </div>
        </div>`;
      document.body.appendChild(overlay);
      const canvas = document.getElementById('vdeSignatureCanvas');
      state.sigCanvas = canvas;
      state.sigCtx    = canvas.getContext('2d');
      state.sigCtx.strokeStyle = '#222';
      state.sigCtx.lineWidth   = 2;
      state.sigCtx.lineCap     = 'round';
      state.sigDrawing = false;

      // Mouse events
      canvas.addEventListener('mousedown',  _sigMouseDown);
      canvas.addEventListener('mousemove',  _sigMouseMove);
      canvas.addEventListener('mouseup',    _sigMouseUp);
      canvas.addEventListener('mouseleave', _sigMouseUp);
      // Touch events
      canvas.addEventListener('touchstart', _sigTouchStart, { passive: false });
      canvas.addEventListener('touchmove',  _sigTouchMove,  { passive: false });
      canvas.addEventListener('touchend',   _sigMouseUp);
    });
  }

  function _sigPos(e) {
    const rect = state.sigCanvas.getBoundingClientRect();
    const scaleX = state.sigCanvas.width  / rect.width;
    const scaleY = state.sigCanvas.height / rect.height;
    return { x: (e.clientX - rect.left) * scaleX, y: (e.clientY - rect.top) * scaleY };
  }

  function _sigMouseDown(e) { state.sigDrawing = true; const p = _sigPos(e); state.sigCtx.beginPath(); state.sigCtx.moveTo(p.x, p.y); }
  function _sigMouseMove(e) { if (!state.sigDrawing) return; const p = _sigPos(e); state.sigCtx.lineTo(p.x, p.y); state.sigCtx.stroke(); }
  function _sigMouseUp()   { state.sigDrawing = false; }
  function _sigTouchStart(e) { e.preventDefault(); const t = e.touches[0]; _sigMouseDown(t); }
  function _sigTouchMove(e)  { e.preventDefault(); const t = e.touches[0]; _sigMouseMove(t); }

  function _sigClear() { state.sigCtx?.clearRect(0, 0, state.sigCanvas.width, state.sigCanvas.height); }
  function _sigSkip()  { document.getElementById('vdeSignatureModal')?.remove(); state.sigResolve?.(''); state.sigResolve = null; }
  function _sigSave()  {
    const dataUrl = state.sigCanvas?.toDataURL('image/png') || '';
    document.getElementById('vdeSignatureModal')?.remove();
    state.sigResolve?.(dataUrl);
    state.sigResolve = null;
  }

  // ── Vollbild-Unterschrift (v2.7.4) ────────────────────────
  function _sigFullscreen() {
    document.getElementById('vdeSigFsOverlay')?.remove();
    const overlay = document.createElement('div');
    overlay.id = 'vdeSigFsOverlay';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:9999;background:#fff;display:flex;flex-direction:column;';
    overlay.innerHTML = `
      <div style="background:linear-gradient(135deg,#00B4D8,#0096B7);color:#fff;padding:12px 16px;display:flex;align-items:center;gap:8px;flex-shrink:0;">
        <span style="font-size:.95rem;font-weight:700;flex:1;">✍ Unterschrift</span>
        <button onclick="window.vde0100Modul._sigFsClear()" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:6px;padding:8px 12px;font-size:.85rem;cursor:pointer;min-height:40px;">Löschen</button>
        <button onclick="window.vde0100Modul._sigFsCancel()" style="background:rgba(255,255,255,.2);color:#fff;border:none;border-radius:6px;padding:8px 12px;font-size:.85rem;cursor:pointer;min-height:40px;">✕</button>
        <button onclick="window.vde0100Modul._sigFsConfirm()" style="background:#fff;color:#0096B7;border:none;border-radius:6px;padding:8px 16px;font-size:.85rem;font-weight:700;cursor:pointer;min-height:40px;">✓ Übernehmen</button>
      </div>
      <div style="flex:1;display:flex;align-items:center;justify-content:center;background:#f4f4f4;padding:16px;box-sizing:border-box;">
        <canvas id="vdeSigFsCanvas" style="background:#fff;touch-action:none;cursor:crosshair;border-radius:6px;box-shadow:0 2px 12px rgba(0,0,0,.12);max-width:100%;max-height:100%;display:block;"></canvas>
      </div>`;
    document.body.appendChild(overlay);

    const canvas = document.getElementById('vdeSigFsCanvas');
    const dpr    = window.devicePixelRatio || 1;
    const cw     = Math.round((window.innerWidth  - 32)  * dpr);
    const ch     = Math.round((window.innerHeight - 100) * dpr);
    canvas.width  = cw;
    canvas.height = ch;
    canvas.style.width  = (cw / dpr) + 'px';
    canvas.style.height = (ch / dpr) + 'px';

    const ctx = canvas.getContext('2d');
    ctx.strokeStyle = '#222';
    ctx.lineWidth   = 2 * dpr;
    ctx.lineCap     = 'round';
    ctx.lineJoin    = 'round';
    state.sigFsCtx    = ctx;
    state.sigFsCanvas = canvas;
    state.sigFsDrawing = false;

    // Vorhandene Unterschrift übertragen
    if (state.sigCanvas) {
      const img = new Image();
      img.onload = () => ctx.drawImage(img, 0, 0, cw, ch);
      img.src = state.sigCanvas.toDataURL('image/png');
    }

    function _fsPos(e) {
      const rect = canvas.getBoundingClientRect();
      return {
        x: (e.clientX - rect.left) * (canvas.width  / rect.width),
        y: (e.clientY - rect.top)  * (canvas.height / rect.height),
      };
    }
    function _fsDown(e) { state.sigFsDrawing = true; const p = _fsPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
    function _fsMove(e) { if (!state.sigFsDrawing) return; const p = _fsPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
    function _fsUp()    { state.sigFsDrawing = false; }
    canvas.addEventListener('mousedown',  _fsDown);
    canvas.addEventListener('mousemove',  _fsMove);
    canvas.addEventListener('mouseup',    _fsUp);
    canvas.addEventListener('mouseleave', _fsUp);
    canvas.addEventListener('touchstart', e => { e.preventDefault(); _fsDown(e.touches[0]); }, { passive: false });
    canvas.addEventListener('touchmove',  e => { e.preventDefault(); _fsMove(e.touches[0]); }, { passive: false });
    canvas.addEventListener('touchend',   _fsUp);
  }

  function _sigFsCancel() { document.getElementById('vdeSigFsOverlay')?.remove(); }

  function _sigFsClear() {
    if (state.sigFsCtx && state.sigFsCanvas) {
      state.sigFsCtx.clearRect(0, 0, state.sigFsCanvas.width, state.sigFsCanvas.height);
    }
  }

  function _sigFsConfirm() {
    if (!state.sigFsCanvas || !state.sigCanvas) { document.getElementById('vdeSigFsOverlay')?.remove(); return; }
    const dataUrl = state.sigFsCanvas.toDataURL('image/png');
    document.getElementById('vdeSigFsOverlay')?.remove();
    const img = new Image();
    img.onload = () => {
      state.sigCtx.clearRect(0, 0, state.sigCanvas.width, state.sigCanvas.height);
      state.sigCtx.drawImage(img, 0, 0, state.sigCanvas.width, state.sigCanvas.height);
    };
    img.src = dataUrl;
  }

  // ── Prüfdatum direkt speichern (auch bei fixierten Protokollen) ────
  async function _savePruefDatum(id, value) {
    try {
      await api('protokoll_save_pruefdatum', { id, pruef_datum: value });
      notify('Prüfdatum gespeichert.');
    } catch (e) { notify('Fehler: ' + e.message); }
  }

  // ── Filter-Helpers ────────────────────────────────────────
  function _setSearch(q) { state.searchQ = q; renderProtokollliste(); }
  function _setStatusFilter(v) { state.statusFilter = v; renderProtokollliste(); }

  function _filterSelect(selectId, query) {
    const sel = document.getElementById(selectId);
    if (!sel) return;
    const q = query.toLowerCase().trim();
    let firstVisible = null;
    Array.from(sel.options).forEach(opt => {
      const matches = q === '' || opt.text.toLowerCase().includes(q);
      opt.style.display = matches ? '' : 'none';
      if (matches && !firstVisible) firstVisible = opt;
    });
    const cur = sel.options[sel.selectedIndex];
    if (cur && cur.style.display === 'none') {
      sel.value = firstVisible ? firstVisible.value : '';
    }
  }

  // ── Gruppen-Vorlagen (v2.7.1) ─────────────────────────────

  async function loadTemplates() {
    if (state.templates.length > 0) return state.templates;
    try {
      const j = await api('template_list', {}, 'GET');
      state.templates = [
        ...(j.predefined || []).map(t => ({ ...t, ist_vordefiniert: true })),
        ...(j.user       || []).map(t => ({ ...t, ist_vordefiniert: false })),
      ];
    } catch (e) {
      notify('Vorlagen konnten nicht geladen werden.');
    }
    return state.templates;
  }

  function openTemplatePickerModal(gebaeudeId, protokollId) {
    document.getElementById('vdeTemplateModal')?.remove();
    loadTemplates().then(templates => {
      const rows = templates.map(t => {
        const daten = typeof t.daten === 'string' ? (() => { try { return JSON.parse(t.daten); } catch { return {}; } })() : (t.daten || {});
        // Neue Struktur (rcd_gruppen) oder altes Format (sicherungen direkt)
        let anzahl = 0;
        let rcdTxt = '';
        if (Array.isArray(daten.rcd_gruppen)) {
          anzahl = daten.rcd_gruppen.reduce((n, g) => n + (g.sicherungen?.length || 0), 0);
          rcdTxt = daten.rcd_gruppen.length === 1
            ? daten.rcd_gruppen[0].bezeichnung
            : daten.rcd_gruppen.length + ' FI/RCD-Blöcke';
        } else {
          anzahl = Array.isArray(daten.sicherungen) ? daten.sicherungen.length : 0;
          rcdTxt = daten.rcd_vorhanden
            ? `FI ${daten.rcd_nennstrom || ''}A/${daten.rcd_nennfehler || ''}mA Typ ${daten.rcd_typ || '?'}`
            : 'Kein FI';
        }
        const badge = t.ist_vordefiniert
          ? '<span class="vde-template-badge-pre">Standard</span>'
          : '<span class="vde-template-badge-user">Eigene</span>';
        const delBtn = t.ist_vordefiniert ? '' :
          `<button class="btn-vde-icon" title="Vorlage löschen" onclick="window.vde0100Modul.deleteTemplate('${t.id}', ${gebaeudeId}, ${protokollId})">🗑</button>`;
        return `
          <div class="vde-template-item">
            <div class="vde-template-item-info">
              <div class="vde-template-item-name">${badge} ${esc(t.name)}</div>
              <div class="vde-template-item-desc">${esc(t.beschreibung)} · ${anzahl} Abgänge · ${esc(rcdTxt)}</div>
            </div>
            <button class="btn-vde-primary" style="font-size:.78rem;padding:5px 10px;white-space:nowrap;"
              onclick="window.vde0100Modul._insertTemplate('${t.id}', ${gebaeudeId}, ${protokollId})">Einfügen</button>
            ${delBtn}
          </div>`;
      }).join('');

      const html = `
        <div class="vde-template-modal" id="vdeTemplateModal">
          <div class="vde-template-box">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
              <strong style="font-size:1rem;">📑 Gruppe aus Vorlage einfügen</strong>
              <button class="btn-vde-icon" onclick="document.getElementById('vdeTemplateModal').remove()">✕</button>
            </div>
            ${rows || '<p style="color:#aaa;font-size:.85rem;">Keine Vorlagen vorhanden.</p>'}
          </div>
        </div>`;
      // Alle Nutzerwerte in rows sind per esc() maskiert.
      document.body.insertAdjacentHTML('beforeend', html); // nosemgrep: typescript.react.security.audit.react-unsanitized-method.react-unsanitized-method
    });
  }

  async function _insertTemplate(templateId, gebaeudeId, protokollId) {
    document.getElementById('vdeTemplateModal')?.remove();
    try {
      const j = await api('template_insert', { templateId: String(templateId), gebaeudeId: Number(gebaeudeId) });
      notify('Vorlage eingefügt.');
      state.templates = []; // Cache invalidieren
      await openProtokolle(j.protokollId || protokollId);
    } catch (e) {
      notify('Fehler: ' + e.message);
    }
  }

  function openSaveTemplateModal(verteilerId) {
    document.getElementById('vdeSaveTemplateModal')?.remove();
    const html = `
      <div class="vde-template-modal" id="vdeSaveTemplateModal">
        <div class="vde-template-box" style="max-width:420px;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <strong style="font-size:1rem;">📑 Als Vorlage speichern</strong>
            <button class="btn-vde-icon" onclick="document.getElementById('vdeSaveTemplateModal').remove()">✕</button>
          </div>
          <label style="font-size:.85rem;font-weight:600;display:block;margin-bottom:4px;">Name der Vorlage *</label>
          <input id="vdeTplName" type="text" placeholder="z.B. Wohnbereich EG" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:5px;font-size:.9rem;margin-bottom:10px;">
          <label style="font-size:.85rem;font-weight:600;display:block;margin-bottom:4px;">Beschreibung (optional)</label>
          <input id="vdeTplDesc" type="text" placeholder="z.B. RCD 40A/30mA + 5× B16" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:5px;font-size:.9rem;margin-bottom:14px;">
          <div style="display:flex;gap:8px;">
            <button class="btn-vde-primary" onclick="window.vde0100Modul._doSaveTemplate(${verteilerId})">💾 Speichern</button>
            <button class="btn-vde-secondary" onclick="document.getElementById('vdeSaveTemplateModal').remove()">Abbrechen</button>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
    setTimeout(() => document.getElementById('vdeTplName')?.focus(), 50);
  }

  async function _doSaveTemplate(verteilerId) {
    const name = (document.getElementById('vdeTplName')?.value || '').trim();
    const desc = (document.getElementById('vdeTplDesc')?.value || '').trim();
    if (!name) { notify('Bitte einen Namen eingeben.'); return; }
    try {
      await api('template_save', { verteilerId: Number(verteilerId), name, beschreibung: desc });
      notify('Vorlage gespeichert.');
      state.templates = [];
      document.getElementById('vdeSaveTemplateModal')?.remove();
    } catch (e) {
      notify('Fehler: ' + e.message);
    }
  }

  async function deleteTemplate(templateId, gebaeudeId, protokollId) {
    if (!confirm('Vorlage löschen?')) return;
    document.getElementById('vdeTemplateModal')?.remove();
    try {
      await api('template_delete', { id: Number(templateId) });
      state.templates = [];
      notify('Vorlage gelöscht.');
    } catch (e) {
      notify('Fehler: ' + e.message);
    }
  }

  function toggleGebaeude(gebaeudeId) {
    state.gebaeudeCollapsed[gebaeudeId] = !state.gebaeudeCollapsed[gebaeudeId];
    const body   = document.getElementById('vde-g-body-'   + gebaeudeId);
    const toggle = document.getElementById('vde-g-toggle-' + gebaeudeId);
    if (body)   body.classList.toggle('vde-collapsed', !!state.gebaeudeCollapsed[gebaeudeId]);
    if (toggle) {
      toggle.textContent = state.gebaeudeCollapsed[gebaeudeId] ? '▸' : '▾';
      toggle.classList.toggle('vde-open', !state.gebaeudeCollapsed[gebaeudeId]);
    }
  }

  // ── Verteiler KI-Erkennung (Foto → Verteilerbaum) ──────────
  let _vdeKi = { tree: null, gebaeudeId: null, protokollId: null, file: null };

  function openVerteilerKiScan(gebaeudeId, protokollId) {
    document.getElementById('vdeKiScanModal')?.remove();
    _vdeKi = { tree: null, gebaeudeId, protokollId, file: null };
    const html = `
      <div class="vde-template-modal" id="vdeKiScanModal">
        <div class="vde-template-box" style="max-width:420px;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <strong style="font-size:1rem;">🤖 Verteiler per Foto erkennen</strong>
            <button class="btn-vde-icon" onclick="document.getElementById('vdeKiScanModal').remove()">✕</button>
          </div>
          <p style="font-size:.82rem;color:#666;margin:0 0 10px;">
            Foto des Unterverteilers / Baustromverteilers hochladen. Die KI erkennt FI-Gruppen und Abgänge –
            das Ergebnis kann anschließend geprüft und bearbeitet werden.
          </p>
          <div id="vdeKiDropzone"
            style="border:2px dashed #ccc;border-radius:8px;padding:20px 14px;text-align:center;cursor:pointer;margin-bottom:10px;"
            onclick="document.getElementById('vdeKiFileInput').click()">
            <div style="font-size:1.8rem;margin-bottom:4px;">📷</div>
            <div style="font-size:.85rem;color:#666;">Klicken und Foto auswählen</div>
            <div id="vdeKiFileName" style="font-size:.78rem;color:#999;margin-top:4px;"></div>
          </div>
          <input type="file" id="vdeKiFileInput" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none"
            onchange="window.vde0100Modul._kiFileChosen(this.files[0])">
          <div id="vdeKiStatus" style="display:none;text-align:center;padding:8px;color:#666;font-size:.85rem;"></div>
          <div id="vdeKiError" style="display:none;padding:8px 10px;background:#ffebee;border-radius:6px;color:#b71c1c;font-size:.82rem;margin-bottom:8px;"></div>
          <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button class="btn-vde-secondary" onclick="document.getElementById('vdeKiScanModal').remove()">Abbrechen</button>
            <button class="btn-vde-primary" id="vdeKiAnalyzeBtn" disabled onclick="window.vde0100Modul._kiRun()">Analysieren</button>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
  }

  function _kiFileChosen(file) {
    if (!file) return;
    const err = document.getElementById('vdeKiError');
    if (err) err.style.display = 'none';
    if (file.size > 10 * 1024 * 1024) {
      if (err) { err.textContent = 'Datei zu groß (max. 10 MB).'; err.style.display = ''; }
      return;
    }
    _vdeKi.file = file;
    const nameEl = document.getElementById('vdeKiFileName');
    if (nameEl) nameEl.textContent = file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)';
    const btn = document.getElementById('vdeKiAnalyzeBtn');
    if (btn) btn.disabled = false;
  }

  async function _kiRun() {
    if (!_vdeKi.file) return;
    const err = document.getElementById('vdeKiError');
    const status = document.getElementById('vdeKiStatus');
    const btn = document.getElementById('vdeKiAnalyzeBtn');
    if (err) err.style.display = 'none';
    if (status) { status.style.display = ''; status.textContent = '🤖 KI analysiert das Foto …'; }
    if (btn) btn.disabled = true;
    try {
      const base64 = await new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload  = () => resolve(String(reader.result).split(',')[1] || '');
        reader.onerror = reject;
        reader.readAsDataURL(_vdeKi.file);
      });
      const j = await api('verteiler_ki_scan', { mimeType: _vdeKi.file.type, data: base64 });
      document.getElementById('vdeKiScanModal')?.remove();
      _vdeKi.tree = j.verteiler || { bezeichnung: '', nennstrom: null, rcd_gruppen: [] };
      if (j.hint) notify(j.hint);
      showVerteilerKiReview();
    } catch (e) {
      if (status) status.style.display = 'none';
      if (err) { err.textContent = 'Fehler: ' + e.message; err.style.display = ''; }
      if (btn) btn.disabled = false;
    }
  }

  function showVerteilerKiReview() {
    document.getElementById('vdeKiReviewModal')?.remove();
    const html = `
      <div class="vde-template-modal" id="vdeKiReviewModal">
        <div class="vde-template-box" style="max-width:640px;max-height:85vh;overflow-y:auto;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
            <strong style="font-size:1rem;">🤖 Erkannter Verteiler – prüfen &amp; übernehmen</strong>
            <button class="btn-vde-icon" onclick="document.getElementById('vdeKiReviewModal').remove()">✕</button>
          </div>
          <div id="vdeKiReviewBody"></div>
          <div style="display:flex;gap:8px;justify-content:space-between;margin-top:14px;">
            <button class="btn-vde-secondary" onclick="window.vde0100Modul._kiAddGruppe()">+ FI/RCD-Gruppe</button>
            <div style="display:flex;gap:8px;">
              <button class="btn-vde-secondary" onclick="document.getElementById('vdeKiReviewModal').remove()">Abbrechen</button>
              <button class="btn-vde-primary" onclick="window.vde0100Modul._kiImport()">✅ Übernehmen</button>
            </div>
          </div>
        </div>
      </div>`;
    document.body.insertAdjacentHTML('beforeend', html);
    _renderKiReviewBody();
  }

  function _renderKiReviewBody() {
    const body = document.getElementById('vdeKiReviewBody');
    if (!body || !_vdeKi.tree) return;
    const t = _vdeKi.tree;
    const gruppenHtml = (t.rcd_gruppen || []).map((rg, gi) => {
      const rows = (rg.sicherungen || []).map((s, si) => `
        <div style="display:grid;grid-template-columns:2fr 1fr 70px 70px 28px;gap:4px;margin-bottom:4px;align-items:center;">
          <input type="text" value="${esc(s.bezeichnung || '')}" placeholder="Bezeichnung" style="padding:4px 6px;border:1px solid #ccc;border-radius:4px;font-size:.8rem;"
            onchange="window.vde0100Modul._kiSetAbgang(${gi},${si},'bezeichnung',this.value)">
          <input type="text" value="${esc(s.typ || '')}" placeholder="Typ" style="padding:4px 6px;border:1px solid #ccc;border-radius:4px;font-size:.8rem;"
            onchange="window.vde0100Modul._kiSetAbgang(${gi},${si},'typ',this.value)">
          <input type="number" step="0.1" value="${s.nennstrom ?? ''}" placeholder="A" style="padding:4px 6px;border:1px solid #ccc;border-radius:4px;font-size:.8rem;text-align:right;"
            onchange="window.vde0100Modul._kiSetAbgang(${gi},${si},'nennstrom',this.value)">
          <input type="text" value="${esc(s.leiterquerschnitt || '')}" placeholder="mm²" style="padding:4px 6px;border:1px solid #ccc;border-radius:4px;font-size:.8rem;text-align:right;"
            onchange="window.vde0100Modul._kiSetAbgang(${gi},${si},'leiterquerschnitt',this.value)">
          <button class="btn-vde-icon" title="Abgang entfernen" onclick="window.vde0100Modul._kiRemoveAbgang(${gi},${si})">🗑</button>
        </div>`).join('');
      return `
        <div style="border:1px solid #ddd;border-radius:8px;padding:10px;margin-bottom:10px;background:#fafafa;">
          <div style="display:grid;grid-template-columns:2fr 80px 90px 70px 28px;gap:4px;margin-bottom:8px;align-items:center;">
            <input type="text" value="${esc(rg.bezeichnung || '')}" placeholder="FI-Bezeichnung" style="padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-size:.85rem;font-weight:600;"
              onchange="window.vde0100Modul._kiSetGruppe(${gi},'bezeichnung',this.value)">
            <input type="number" step="0.1" value="${rg.nennstrom ?? ''}" placeholder="A" style="padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-size:.85rem;text-align:right;"
              onchange="window.vde0100Modul._kiSetGruppe(${gi},'nennstrom',this.value)">
            <input type="number" step="1" value="${rg.nennfehlerstrom ?? ''}" placeholder="mA" style="padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-size:.85rem;text-align:right;"
              onchange="window.vde0100Modul._kiSetGruppe(${gi},'nennfehlerstrom',this.value)">
            <input type="text" value="${esc(rg.typ || '')}" placeholder="Typ" style="padding:5px 7px;border:1px solid #ccc;border-radius:4px;font-size:.85rem;"
              onchange="window.vde0100Modul._kiSetGruppe(${gi},'typ',this.value)">
            <button class="btn-vde-icon" title="Gruppe entfernen" onclick="window.vde0100Modul._kiRemoveGruppe(${gi})">🗑</button>
          </div>
          ${rows || '<p style="font-size:.78rem;color:#aaa;margin:0 0 6px;">Keine Abgänge.</p>'}
          <button class="btn-vde-secondary" style="font-size:.75rem;padding:3px 8px;" onclick="window.vde0100Modul._kiAddAbgang(${gi})">+ Abgang</button>
        </div>`;
    }).join('');

    body.innerHTML = `
      <div style="display:grid;grid-template-columns:2fr 100px;gap:6px;margin-bottom:12px;">
        <div>
          <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:3px;">Verteiler-Bezeichnung *</label>
          <input type="text" value="${esc(t.bezeichnung || '')}" style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:5px;font-size:.88rem;"
            onchange="window.vde0100Modul._kiSetVerteiler('bezeichnung',this.value)">
        </div>
        <div>
          <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:3px;">Nennstrom (A)</label>
          <input type="number" step="0.1" value="${t.nennstrom ?? ''}" style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:5px;font-size:.88rem;"
            onchange="window.vde0100Modul._kiSetVerteiler('nennstrom',this.value)">
        </div>
      </div>
      ${gruppenHtml || '<p style="font-size:.82rem;color:#aaa;">Keine FI/RCD-Gruppen erkannt. Über „+ FI/RCD-Gruppe" ergänzen.</p>'}`;
  }

  function _kiSetVerteiler(field, value) {
    if (!_vdeKi.tree) return;
    _vdeKi.tree[field] = field === 'nennstrom' ? (value === '' ? null : parseFloat(value)) : value;
  }

  function _kiSetGruppe(gi, field, value) {
    const rg = _vdeKi.tree?.rcd_gruppen?.[gi];
    if (!rg) return;
    rg[field] = (field === 'nennstrom' || field === 'nennfehlerstrom')
      ? (value === '' ? null : parseFloat(value))
      : value;
  }

  function _kiSetAbgang(gi, si, field, value) {
    const s = _vdeKi.tree?.rcd_gruppen?.[gi]?.sicherungen?.[si];
    if (!s) return;
    s[field] = field === 'nennstrom' ? (value === '' ? null : parseFloat(value)) : value;
  }

  function _kiAddGruppe() {
    if (!_vdeKi.tree) return;
    if (!Array.isArray(_vdeKi.tree.rcd_gruppen)) _vdeKi.tree.rcd_gruppen = [];
    _vdeKi.tree.rcd_gruppen.push({ bezeichnung: 'Neue FI-Gruppe', nennstrom: null, nennfehlerstrom: null, typ: '', sicherungen: [] });
    _renderKiReviewBody();
  }

  function _kiRemoveGruppe(gi) {
    _vdeKi.tree?.rcd_gruppen?.splice(gi, 1);
    _renderKiReviewBody();
  }

  function _kiAddAbgang(gi) {
    const rg = _vdeKi.tree?.rcd_gruppen?.[gi];
    if (!rg) return;
    if (!Array.isArray(rg.sicherungen)) rg.sicherungen = [];
    rg.sicherungen.push({ bezeichnung: '', typ: 'LS', nennstrom: null, leiterquerschnitt: '' });
    _renderKiReviewBody();
  }

  function _kiRemoveAbgang(gi, si) {
    _vdeKi.tree?.rcd_gruppen?.[gi]?.sicherungen?.splice(si, 1);
    _renderKiReviewBody();
  }

  async function _kiImport() {
    if (!_vdeKi.tree || !_vdeKi.gebaeudeId) return;
    if (!(_vdeKi.tree.bezeichnung || '').trim()) { notify('Bitte eine Verteiler-Bezeichnung eingeben.'); return; }
    try {
      const j = await api('verteiler_ki_import', { gebaeudeId: Number(_vdeKi.gebaeudeId), daten: _vdeKi.tree });
      document.getElementById('vdeKiReviewModal')?.remove();
      notify('Verteiler übernommen.');
      const protokollId = j.protokollId || _vdeKi.protokollId;
      _vdeKi = { tree: null, gebaeudeId: null, protokollId: null, file: null };
      await openProtokolle(protokollId, true);
    } catch (e) {
      notify('Fehler: ' + e.message);
    }
  }

  // ── Kunde/Baustelle onchange: Anschriften vorbelegen (v2.10.1) ────
  function _onKundeChange(kundeId) {
    const kd = (typeof kundenData !== 'undefined' ? kundenData : []) || [];
    const k  = kd.find(x => String(x.id) === String(kundeId));
    if (!k) return;
    const el = document.getElementById('vdeKundenanschrift');
    if (el && !el.value.trim()) {
      const parts = [k.strasse, [k.plz, k.ort].filter(Boolean).join(' ')].filter(Boolean);
      el.value = parts.join('\n');
    }
  }

  function _onBaustelleChange(baustelleId) {
    const bd = (typeof appData !== 'undefined' ? appData.baustellen : []) || [];
    const b  = bd.find(x => String(x.id) === String(baustelleId));
    if (!b) return;
    const el = document.getElementById('vdeAnlagenanschrift');
    if (el && !el.value.trim()) {
      el.value = b.name || '';
    }
  }

  // ── Public API ────────────────────────────────────────────
  window.vde0100Modul = {
    showVde0100View, hideVde0100View, loadProtokolle,
    openNeuesProtokoll, openProtokolle, saveProtokolllForm, deleteProtokolle,
    fixieren, kopierenAlsEntwurf, exportPdf, emailPdf,
    addGebaeude, editGebaeude, deleteGebaeude,
    addVerteiler, editVerteiler, _saveVerteilerModal, deleteVerteiler,
    openVerteilerKiScan, _kiFileChosen, _kiRun,
    _kiSetVerteiler, _kiSetGruppe, _kiSetAbgang,
    _kiAddGruppe, _kiRemoveGruppe, _kiAddAbgang, _kiRemoveAbgang, _kiImport,
    addRcd, editRcd, openRcdModal, _saveRcdModal, deleteRcd,
    addSicherung, addSicherungDirekt, saveSicherungField, saveRcdField, deleteSicherung,
    _sigClear, _sigSkip, _sigSave, _sigFullscreen,
    _sigFsCancel, _sigFsClear, _sigFsConfirm,
    _savePruefDatum,
    _setSearch, _setStatusFilter, _filterSelect,
    _onKundeChange, _onBaustelleChange,
    loadTemplates, openTemplatePickerModal, _insertTemplate,
    openSaveTemplateModal, _doSaveTemplate, deleteTemplate,
    toggleGebaeude,
    _state: state,
  };
})();
