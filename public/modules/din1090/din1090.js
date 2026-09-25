// MODULE_VERSION: 56
// ═══════════════════════════════════════════════════════════════
// DIN EN 1090 – Baubegleitung & Dokumentation
// Separates Modul für die Baukalkulation App
// ═══════════════════════════════════════════════════════════════

(function () {
  'use strict';

  const API = 'din1090_api.php';

  // ── State ──────────────────────────────────────────────────
  let din1090Projects = [];
  let din1090CurrentProject = null;
  let din1090Tab = 'uebersicht';
  let din1090Baustellen = [];

  // ── API-Helfer ─────────────────────────────────────────────
  async function din1090Api(sub, data = {}) {
    const payload = Object.assign({ sub }, data);
    const r = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    return r.json();
  }

  function din1090Esc(s) {
    if (typeof esc === 'function') return esc(s);
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
  }

  function din1090Ico(name, cls) {
    if (typeof ico === 'function') return ico(name, cls);
    return name;
  }

  function din1090Notify(msg, type) {
    if (typeof showNotification === 'function') return showNotification(msg, type);
    alert(msg);
  }

  // ── Berechtigungsprüfung ───────────────────────────────────
  function din1090CanWrite() {
    return typeof canDo !== 'function' || canDo('canWriteDin1090');
  }

  function din1090Fmt(d) {
    if (!d) return '–';
    const p = d.split('-');
    return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : d;
  }

  // ── Baustellen aus Hauptapp laden ──────────────────────────
  async function din1090LoadBaustellen() {
    try {
      const r = await fetch('api.php?action=load');
      const j = await r.json();
      const data = j.data || j;
      din1090Baustellen = data.baustellen || [];
    } catch (e) {
      din1090Baustellen = [];
    }
  }

  // ── Baustellen-Dropdown filtern ────────────────────────────
  window.din1090FilterBaustellen = function (q) {
    const sel = document.getElementById('d1090_baustelleId');
    if (!sel) return;
    const terms = q.trim().toLowerCase().split(/\s+/).filter(t => t);
    const currentVal = sel.value;
    sel.innerHTML = '<option value="">— Keine —</option>';
    din1090Baustellen.forEach(b => {
      const hay = ((b.projektNr || '') + ' ' + b.name).toLowerCase();
      if (terms.length > 0 && !terms.every(t => hay.includes(t))) return;
      const opt = document.createElement('option');
      opt.value = b.id;
      opt.textContent = (b.projektNr ? b.projektNr + ' – ' : '') + b.name;
      if (String(b.id) === currentVal) opt.selected = true;
      sel.appendChild(opt);
    });
  };

  // ═════════════════════════════════════════════════════════════
  //  HAUPTRENDER-FUNKTION (wird von showDin1090 aufgerufen)
  // ═════════════════════════════════════════════════════════════
  window.renderDin1090 = async function () {
    const container = document.getElementById('din1090View');
    if (!container) return;

    if (din1090Baustellen.length === 0) await din1090LoadBaustellen();
    if (din1090Projects.length === 0) await din1090LoadProjects();

    container.innerHTML = `
      <div class="din1090-header">
        <h2>${din1090Ico('construction', 'text-primary')} DIN EN 1090 – Baubegleitung & Dokumentation</h2>
        <div class="din1090-project-selector">
          <div style="position:relative;flex:1;min-width:200px;">
            <input type="text" id="din1090ProjectSearch" class="form-control" placeholder="🔍 Projekt suchen (Name, Projekt-Nr.)…"
              oninput="din1090FilterProjects()" onfocus="din1090FilterProjects()"
              value="${din1090CurrentProject ? din1090Esc(din1090CurrentProject.bezeichnung + (din1090CurrentProject.projektNr ? ' (' + din1090CurrentProject.projektNr + ')' : '')) : ''}"
              autocomplete="off">
            <div id="din1090ProjectDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:999;background:#fff;border:1px solid #ccc;border-radius:6px;max-height:240px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.15);margin-top:2px;"></div>
          </div>
          ${din1090CanWrite() ? '<button class="btn btn-primary btn-sm" onclick="din1090EditProject(0)" title="Neues Projekt">+ Neues Projekt</button>' : ''}
        </div>
      </div>

      ${din1090CurrentProject ? din1090RenderTabs() : din1090RenderProjectListHtml()}

      <div id="din1090Content"></div>
    `;

    if (din1090CurrentProject) {
      din1090SwitchTab(din1090Tab);
    }
  };

  // ── Projekt laden ──────────────────────────────────────────
  async function din1090LoadProjects() {
    const r = await din1090Api('list_projects');
    din1090Projects = r.ok ? r.data : [];
  }

  window.din1090SelectProject = async function (id) {
    if (!id) { din1090CurrentProject = null; renderDin1090(); return; }
    const r = await din1090Api('get_project', { id: parseInt(id) });
    din1090CurrentProject = r.ok ? r.data : null;
    din1090Tab = 'uebersicht';
    renderDin1090();
  };

  // ── Projekt-Suche / Dropdown ───────────────────────────────
  window.din1090FilterProjects = function () {
    const input = document.getElementById('din1090ProjectSearch');
    const dd = document.getElementById('din1090ProjectDropdown');
    if (!input || !dd) return;
    const q = input.value.toLowerCase().trim();

    const filtered = din1090Projects.filter(p => {
      const haystack = [
        p.bezeichnung || '',
        p.ausfuehrungsklasse || '',
        p.baustelle_name || '',
        p.projektNr || ''
      ].join(' ').toLowerCase();
      return !q || haystack.includes(q);
    });

    if (filtered.length === 0 && q) {
      dd.innerHTML = '<div style="padding:10px;color:#999;font-size:12px;">Keine Projekte gefunden.</div>';
    } else {
      dd.innerHTML = filtered.map(p => {
        const pnr = p.projektNr ? `<span style="color:#1565C0;font-weight:600;">${din1090Esc(p.projektNr)}</span> – ` : '';
        const bst = p.baustelle_name ? `<span style="color:#666;font-size:11px;"> | ${din1090Esc(p.baustelle_name)}</span>` : '';
        const isActive = din1090CurrentProject && din1090CurrentProject.id == p.id;
        return `<div onclick="din1090PickProject(${p.id})" style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:13px;${isActive ? 'background:#E3F2FD;' : ''}"
          onmouseenter="this.style.background='#f5f5f5'" onmouseleave="this.style.background='${isActive ? '#E3F2FD' : ''}'">
          ${pnr}<strong>${din1090Esc(p.bezeichnung)}</strong> <span style="color:#888;font-size:11px;">(${din1090Esc(p.ausfuehrungsklasse)})</span>${bst}
        </div>`;
      }).join('');
    }
    dd.style.display = 'block';
  };

  window.din1090PickProject = function (id) {
    const dd = document.getElementById('din1090ProjectDropdown');
    if (dd) dd.style.display = 'none';
    din1090SelectProject(id);
  };

  // Dropdown schließen bei Klick außerhalb
  document.addEventListener('click', function (e) {
    const dd = document.getElementById('din1090ProjectDropdown');
    const input = document.getElementById('din1090ProjectSearch');
    if (dd && input && !dd.contains(e.target) && e.target !== input) {
      dd.style.display = 'none';
    }
  });

  // ── Projektliste (Startseite ohne ausgewähltem Projekt) ─────
  let din1090ProjectListSearch = '';

  function din1090RenderProjectListHtml() {
    const q = din1090ProjectListSearch.toLowerCase().trim();
    const filtered = din1090Projects.filter(p => {
      const hay = [p.bezeichnung || '', p.projektNr || '', p.baustelle_name || '', p.ausfuehrungsklasse || ''].join(' ').toLowerCase();
      return !q || hay.includes(q);
    });

    const statusBadge = s => {
      const cfg = { aktiv: ['#E8F5E9', '#2E7D32', '● aktiv'], abgeschlossen: ['#E3F2FD', '#1565C0', '✓ abgeschlossen'], archiviert: ['#F5F5F5', '#757575', '⊘ archiviert'] };
      const [bg, col, txt] = cfg[s] || ['#fff3e0', '#e65100', s];
      return `<span style="background:${bg};color:${col};padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:700;white-space:nowrap">${txt}</span>`;
    };

    const cardsHtml = filtered.length === 0
      ? `<div class="din1090-empty"><p>${q ? 'Keine Projekte gefunden.' : 'Noch keine Projekte vorhanden. Erstellen Sie ein neues Projekt.'}</p></div>`
      : filtered.map(p => {
          const pnr = p.projektNr ? `<span style="color:#1565C0;font-size:.78rem;font-weight:600">${din1090Esc(p.projektNr)}</span>` : '';
          const bst = p.baustelle_name ? `<span style="color:#666;font-size:.8rem">🏗 ${din1090Esc(p.baustelle_name)}</span>` : '';
          const exc = p.ausfuehrungsklasse ? `<span style="background:#EDE7F6;color:#4527A0;padding:1px 7px;border-radius:8px;font-size:.72rem;font-weight:700">${din1090Esc(p.ausfuehrungsklasse)}</span>` : '';
          const del = din1090CanWrite()
            ? `<button style="background:none;border:none;cursor:pointer;color:#e53935;font-size:1rem;padding:4px 8px" title="Projekt löschen" onclick="event.stopPropagation();din1090DeleteProjectFromList(${p.id})">🗑</button>`
            : '';
          return `<div onclick="din1090SelectProject(${p.id})" style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--surface,#fff);border:1px solid #e0e0e0;border-radius:10px;cursor:pointer;transition:box-shadow .15s" onmouseenter="this.style.boxShadow='0 2px 10px rgba(0,0,0,.12)'" onmouseleave="this.style.boxShadow=''">
            <div style="font-size:1.6rem;width:36px;text-align:center;flex-shrink:0">🏗️</div>
            <div style="flex:1;min-width:0">
              <div style="font-weight:700;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${din1090Esc(p.bezeichnung)}</div>
              <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px">${[pnr, exc, bst].filter(Boolean).join(' ')}</div>
            </div>
            <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">
              ${statusBadge(p.status)}
              ${del}
            </div>
          </div>`;
        }).join('');

    return `<div style="padding:16px">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
        <input type="text" class="form-control" placeholder="🔍 Projekte suchen…" style="max-width:320px;font-size:.88rem"
          value="${din1090Esc(din1090ProjectListSearch)}"
          oninput="din1090SetProjectListSearch(this.value)" >
        <span style="color:#888;font-size:.85rem">${din1090Projects.length} Projekt${din1090Projects.length === 1 ? '' : 'e'}</span>
      </div>
      <div id="din1090ProjectCards" style="display:flex;flex-direction:column;gap:10px">${cardsHtml}</div>
    </div>`;
  }

  window.din1090RenderProjectListHtml = din1090RenderProjectListHtml;

  window.din1090SetProjectListSearch = function (val) {
    din1090ProjectListSearch = val;
    const cards = document.getElementById('din1090ProjectCards');
    if (!cards) return;
    // Nur die Karten neu rendern, Suchfeld bleibt
    const q = val.toLowerCase().trim();
    const filtered = din1090Projects.filter(p => {
      const hay = [p.bezeichnung || '', p.projektNr || '', p.baustelle_name || '', p.ausfuehrungsklasse || ''].join(' ').toLowerCase();
      return !q || hay.includes(q);
    });
    const statusBadge = s => {
      const cfg = { aktiv: ['#E8F5E9', '#2E7D32', '\u25cf aktiv'], abgeschlossen: ['#E3F2FD', '#1565C0', '\u2713 abgeschlossen'], archiviert: ['#F5F5F5', '#757575', '\u2298 archiviert'] };
      const [bg, col, txt] = cfg[s] || ['#fff3e0', '#e65100', s];
      return `<span style="background:${bg};color:${col};padding:2px 8px;border-radius:10px;font-size:.72rem;font-weight:700;white-space:nowrap">${txt}</span>`;
    };
    if (filtered.length === 0) {
      cards.innerHTML = `<div class="din1090-empty"><p>${q ? 'Keine Projekte gefunden.' : 'Noch keine Projekte vorhanden.'}</p></div>`;
      return;
    }
    cards.innerHTML = filtered.map(p => {
      const pnr = p.projektNr ? `<span style="color:#1565C0;font-size:.78rem;font-weight:600">${din1090Esc(p.projektNr)}</span>` : '';
      const bst = p.baustelle_name ? `<span style="color:#666;font-size:.8rem">🏗 ${din1090Esc(p.baustelle_name)}</span>` : '';
      const exc = p.ausfuehrungsklasse ? `<span style="background:#EDE7F6;color:#4527A0;padding:1px 7px;border-radius:8px;font-size:.72rem;font-weight:700">${din1090Esc(p.ausfuehrungsklasse)}</span>` : '';
      const del = din1090CanWrite()
        ? `<button style="background:none;border:none;cursor:pointer;color:#e53935;font-size:1rem;padding:4px 8px" title="Projekt l\u00f6schen" onclick="event.stopPropagation();din1090DeleteProjectFromList(${p.id})">🗑</button>`
        : '';
      return `<div onclick="din1090SelectProject(${p.id})" style="display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--surface,#fff);border:1px solid #e0e0e0;border-radius:10px;cursor:pointer;transition:box-shadow .15s" onmouseenter="this.style.boxShadow='0 2px 10px rgba(0,0,0,.12)'" onmouseleave="this.style.boxShadow=''">
        <div style="font-size:1.6rem;width:36px;text-align:center;flex-shrink:0">🏗️</div>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${din1090Esc(p.bezeichnung)}</div>
          <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-top:4px">${[pnr, exc, bst].filter(Boolean).join(' ')}</div>
        </div>
        <div style="display:flex;align-items:center;gap:6px;flex-shrink:0">${statusBadge(p.status)}${del}</div>
      </div>`;
    }).join('');
  };

  window.din1090DeleteProjectFromList = async function (id) {
    const p = din1090Projects.find(x => x.id === id);
    if (!p) return;
    if (!confirm(`Projekt "${p.bezeichnung}" und alle zugehörigen Daten wirklich löschen?\nDiese Aktion kann nicht rückgängig gemacht werden.`)) return;
    const r = await din1090Api('delete_project', { id });
    if (!r.ok) { din1090Notify('Fehler beim Löschen: ' + (r.error || '?'), 'error'); return; }
    din1090Projects = din1090Projects.filter(x => x.id !== id);
    if (din1090CurrentProject && din1090CurrentProject.id === id) din1090CurrentProject = null;
    din1090Notify('Projekt gelöscht.', 'success');
    renderDin1090();
  };

  // ── Tabs ───────────────────────────────────────────────────
  function din1090RenderTabs() {
    const tabs = [
      { id: 'uebersicht',   label: 'Übersicht',      icon: 'dashboard' },
      { id: 'material',     label: 'Material',        icon: 'inventory' },
      { id: 'schweissen',   label: 'Schweißen',       icon: 'local_fire_department' },
      { id: 'pruefungen',   label: 'Prüfungen',       icon: 'verified' },
      { id: 'oberflaeche',  label: 'Oberfläche',      icon: 'format_paint' },
      { id: 'abweichungen', label: 'Abweichungen',    icon: 'warning' },
      { id: 'checklisten',  label: 'Checklisten',     icon: 'checklist' },
      { id: 'audit',        label: 'Protokoll',       icon: 'history' },
    ];
    return `<div class="din1090-tabs">${tabs.map(t =>
      `<button class="din1090-tab ${din1090Tab === t.id ? 'active' : ''}" onclick="din1090SwitchTab('${t.id}')">
        ${din1090Ico(t.icon)} ${t.label}
      </button>`
    ).join('')}</div>`;
  }

  window.din1090SwitchTab = function (tab) {
    din1090Tab = tab;
    document.querySelectorAll('.din1090-tab').forEach(b => b.classList.toggle('active', b.textContent.trim().includes(
      { uebersicht: 'Übersicht', material: 'Material', schweissen: 'Schweißen', pruefungen: 'Prüfungen', oberflaeche: 'Oberfläche', abweichungen: 'Abweichungen', checklisten: 'Checklisten', audit: 'Protokoll' }[tab] || ''
    )));
    const content = document.getElementById('din1090Content');
    if (!content) return;

    const renderers = {
      uebersicht:   din1090RenderDashboard,
      material:     din1090RenderMaterial,
      schweissen:   din1090RenderSchweissen,
      pruefungen:   din1090RenderPruefungen,
      oberflaeche:  din1090RenderOberflaeche,
      abweichungen: din1090RenderAbweichungen,
      checklisten:  din1090RenderChecklisten,
      audit:        din1090RenderAudit,
    };

    if (renderers[tab]) renderers[tab](content);
  };

  // ═════════════════════════════════════════════════════════════
  //  DASHBOARD / ÜBERSICHT
  // ═════════════════════════════════════════════════════════════
  async function din1090RenderDashboard(el) {
    const proj = din1090CurrentProject;
    el.innerHTML = '<div class="din1090-loading">Lade Dashboard…</div>';
    const r = await din1090Api('dashboard', { projectId: proj.id });
    const d = r.ok ? r.data : {};

    const chkPct = d.checklist_total > 0 ? Math.round(d.checklist_done / d.checklist_total * 100) : 0;

    el.innerHTML = `
      <div class="din1090-dashboard">
        <div class="din1090-info-card">
          <h3>Projektdetails</h3>
          <table class="din1090-info-table">
            <tr><td><strong>Bezeichnung:</strong></td><td>${din1090Esc(proj.bezeichnung)}</td></tr>
            <tr><td><strong>Baustelle:</strong></td><td>${din1090Esc(proj.baustelle_name || '–')}</td></tr>
            <tr><td><strong>Ausführungsklasse:</strong></td><td><span class="din1090-exc-badge exc-${proj.ausfuehrungsklasse}">${proj.ausfuehrungsklasse}</span></td></tr>
            <tr><td><strong>Werkstoff:</strong></td><td>${din1090Esc(proj.werkstoff)}</td></tr>
            <tr><td><strong>Normen:</strong></td><td>${din1090Esc(proj.normen)}</td></tr>
            <tr><td><strong>Verantwortlicher:</strong></td><td>${din1090Esc(proj.verantwortlicher)}</td></tr>
            <tr><td><strong>Schweißaufsicht:</strong></td><td>${din1090Esc(proj.schweissaufsicht)}</td></tr>
            <tr><td><strong>Prüfstelle:</strong></td><td>${din1090Esc(proj.pruefstelle || '–')}</td></tr>
            <tr><td><strong>Status:</strong></td><td>${din1090StatusBadge(proj.status)}</td></tr>
          </table>
          <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
            ${din1090CanWrite() ? `<button class="btn btn-sm btn-secondary" onclick="din1090EditProject(${proj.id})">Bearbeiten</button>` : ''}
            <button class="btn btn-sm btn-secondary" onclick="din1090ExportPDF()">📄 Protokoll PDF</button>
            <button class="btn btn-sm btn-secondary" onclick="din1090EmailPDF()">✉ Per E-Mail senden</button>
          </div>
        </div>

        <div class="din1090-stats-grid">
          <div class="din1090-stat-card">
            <div class="din1090-stat-number">${d.materials || 0}</div>
            <div class="din1090-stat-label">Materialien</div>
          </div>
          <div class="din1090-stat-card">
            <div class="din1090-stat-number">${d.welders || 0}</div>
            <div class="din1090-stat-label">Schweißer</div>
            ${d.welders_expiring > 0 ? `<div class="din1090-stat-warn">⚠ ${d.welders_expiring} Qualifikation(en) laufen ab</div>` : ''}
          </div>
          <div class="din1090-stat-card">
            <div class="din1090-stat-number">${d.wps || 0}</div>
            <div class="din1090-stat-label">WPS</div>
          </div>
          <div class="din1090-stat-card">
            <div class="din1090-stat-number">${d.welds || 0}</div>
            <div class="din1090-stat-label">Schweißnähte</div>
          </div>
          <div class="din1090-stat-card">
            <div class="din1090-stat-number">${d.inspections || 0}</div>
            <div class="din1090-stat-label">Prüfungen</div>
          </div>
          <div class="din1090-stat-card ${d.ncr_offen > 0 ? 'din1090-stat-warn-card' : ''}">
            <div class="din1090-stat-number">${d.ncr_offen || 0} / ${d.ncr || 0}</div>
            <div class="din1090-stat-label">Offene / Gesamt NCR</div>
          </div>
          <div class="din1090-stat-card">
            <div class="din1090-stat-number">${d.surface || 0}</div>
            <div class="din1090-stat-label">Oberflächen</div>
          </div>
          <div class="din1090-stat-card">
            <div class="din1090-stat-number">${chkPct}%</div>
            <div class="din1090-stat-label">Checkliste</div>
            <div class="din1090-progress"><div class="din1090-progress-bar" style="width:${chkPct}%"></div></div>
          </div>
        </div>

        <div class="din1090-info-card" style="margin-top:15px">
          <h3>Anforderungen ${proj.ausfuehrungsklasse}</h3>
          ${din1090ExcInfo(proj.ausfuehrungsklasse)}
        </div>
      </div>
    `;
  }

  function din1090StatusBadge(s) {
    const colors = { aktiv: '#27ae60', abgeschlossen: '#2980b9', archiviert: '#7f8c8d' };
    const labels = { aktiv: 'Aktiv', abgeschlossen: 'Abgeschlossen', archiviert: 'Archiviert' };
    return `<span style="background:${colors[s] || '#999'};color:#fff;padding:2px 8px;border-radius:4px;font-size:12px">${labels[s] || s}</span>`;
  }

  function din1090ExcInfo(exc) {
    const info = {
      EXC1: `<ul>
        <li>Vorwiegend ruhend beanspruchte Tragwerke</li>
        <li>Werkszeugnisse 2.1 nach EN 10204 ausreichend</li>
        <li>Sichtprüfung (VT) aller Schweißnähte</li>
        <li>Keine speziellen Schweißerqualifikationen über EN ISO 9606 hinaus</li>
        <li>Grundlegende geometrische Kontrolle</li>
      </ul>`,
      EXC2: `<ul>
        <li>Standardmäßige Hochbauten und gewöhnliche Tragwerke</li>
        <li>Abnahmeprüfzeugnisse 3.1 nach EN 10204 erforderlich</li>
        <li>WPS nach EN ISO 15609 + WPQR nach EN ISO 15614 erforderlich</li>
        <li>Schweißerprüfungen nach EN ISO 9606 müssen gültig sein</li>
        <li>Schweißaufsicht nach EN ISO 14731 erforderlich</li>
        <li>ZfP-Prüfumfang nach Tabelle 24: ≥ 5% der Nähte</li>
        <li>Rückverfolgbarkeit der Werkstoffe sicherstellen</li>
        <li>Werkseigene Produktionskontrolle (WPK) dokumentieren</li>
      </ul>`,
      EXC3: `<ul>
        <li>Brücken, Krane, ermüdungsbeanspruchte Tragwerke</li>
        <li>Abnahmeprüfzeugnisse 3.1/3.2 nach EN 10204</li>
        <li>Vollständige Schweißfolgeplanung erforderlich</li>
        <li>ZfP-Prüfumfang nach Tabelle 24: ≥ 10–20% der Nähte</li>
        <li>Chargenverfolgung Zusatzwerkstoffe</li>
        <li>Unabhängige Prüfstelle empfohlen</li>
        <li>Vorwärm- und Zwischenlagentemperatur überwachen</li>
        <li>Gleitfeste Verbindungen: Reibflächen prüfen</li>
      </ul>`,
      EXC4: `<ul>
        <li>Extreme Beanspruchung: Kernkraftwerke, Offshore, Sonderbauwerke</li>
        <li>Abnahmeprüfzeugnisse 3.2 nach EN 10204 zwingend</li>
        <li>Höchste ZfP-Prüfumfänge, ggf. 100% RT/UT</li>
        <li>Unabhängige Prüfstelle zwingend erforderlich</li>
        <li>Lückenlose Dokumentation aller Fertigungsschritte</li>
        <li>Erweiterte geometrische Kontrollen</li>
      </ul>`
    };
    return info[exc] || '';
  }

  // ═════════════════════════════════════════════════════════════
  //  PROJEKT DIALOG
  // ═════════════════════════════════════════════════════════════
  window.din1090EditProject = async function (id) {
    let proj = { id: 0, baustelleId: '', bezeichnung: '', ausfuehrungsklasse: 'EXC2', werkstoff: 'S235JR', normen: 'DIN EN 1090-2', verantwortlicher: '', schweissaufsicht: '', pruefstelle: '', status: 'aktiv', notizen: '' };
    if (id > 0) {
      const r = await din1090Api('get_project', { id });
      if (r.ok && r.data) proj = r.data;
    }
    if (din1090Baustellen.length === 0) await din1090LoadBaustellen();

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'din1090ProjectModal';
    overlay.innerHTML = `<div class="modal-box" style="max-width:650px">
      <div class="modal-header">
        <h3>${id > 0 ? 'Projekt bearbeiten' : 'Neues DIN 1090 Projekt'}</h3>
        <button class="modal-close" onclick="document.getElementById('din1090ProjectModal').remove()">×</button>
      </div>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Bezeichnung *</label>
          <input id="d1090_bezeichnung" class="form-control" value="${din1090Esc(proj.bezeichnung)}">
        </div>
        <div class="form-row">
          <div class="form-group" style="flex:1">
            <label class="form-label">Baustelle</label>
            <input id="d1090_baustelleSearch" class="form-control" placeholder="🔍 Baustelle suchen…" style="margin-bottom:4px;font-size:.85rem;" oninput="din1090FilterBaustellen(this.value)">
            <select id="d1090_baustelleId" class="form-control" size="5" style="height:auto;max-height:140px;overflow-y:auto;">
              <option value="">— Keine —</option>
              ${din1090Baustellen.map(b => `<option value="${b.id}" ${proj.baustelleId == b.id ? 'selected' : ''}>${(b.projektNr ? b.projektNr + ' – ' : '') + din1090Esc(b.name)}</option>`).join('')}
            </select>
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label">Ausführungsklasse *</label>
            <select id="d1090_exc" class="form-control">
              ${['EXC1','EXC2','EXC3','EXC4'].map(e => `<option value="${e}" ${proj.ausfuehrungsklasse === e ? 'selected' : ''}>${e}</option>`).join('')}
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group" style="flex:1">
            <label class="form-label">Werkstoff</label>
            <select id="d1090_werkstoff" class="form-control">
              ${['S235JR','S235J2','S275JR','S275J2','S355JR','S355J2','S355K2','S460','Aluminium','Edelstahl','Sonstige'].map(w => `<option ${proj.werkstoff === w ? 'selected' : ''}>${w}</option>`).join('')}
            </select>
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label">Normen</label>
            <input id="d1090_normen" class="form-control" value="${din1090Esc(proj.normen)}">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group" style="flex:1">
            <label class="form-label">Verantwortlicher</label>
            <input id="d1090_verantwortlicher" class="form-control" value="${din1090Esc(proj.verantwortlicher)}">
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label">Schweißaufsicht (IWE/IWT/IWS)</label>
            <input id="d1090_schweissaufsicht" class="form-control" value="${din1090Esc(proj.schweissaufsicht)}">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group" style="flex:1">
            <label class="form-label">Prüfstelle</label>
            <input id="d1090_pruefstelle" class="form-control" value="${din1090Esc(proj.pruefstelle || '')}">
          </div>
          <div class="form-group" style="flex:1">
            <label class="form-label">Status</label>
            <select id="d1090_status" class="form-control">
              ${['aktiv','abgeschlossen','archiviert'].map(s => `<option ${proj.status === s ? 'selected' : ''}>${s}</option>`).join('')}
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Notizen</label>
          <textarea id="d1090_notizen" class="form-control" rows="3">${din1090Esc(proj.notizen)}</textarea>
        </div>
      </div>
      <div class="modal-footer">
        ${id > 0 ? `<button class="btn btn-danger btn-sm" onclick="din1090DeleteProject(${id})" style="margin-right:auto">Löschen</button>` : ''}
        <button class="btn btn-secondary" onclick="document.getElementById('din1090ProjectModal').remove()">Abbrechen</button>
        <button class="btn btn-primary" onclick="din1090SaveProject(${id})">Speichern</button>
      </div>
    </div>`;
    document.body.appendChild(overlay);
  };

  window.din1090SaveProject = async function (id) {
    const bezeichnung = document.getElementById('d1090_bezeichnung').value.trim();
    if (!bezeichnung) { din1090Notify('Bezeichnung ist erforderlich.', 'error'); return; }

    const data = {
      id,
      baustelleId: document.getElementById('d1090_baustelleId').value,
      bezeichnung,
      ausfuehrungsklasse: document.getElementById('d1090_exc').value,
      werkstoff: document.getElementById('d1090_werkstoff').value,
      normen: document.getElementById('d1090_normen').value,
      verantwortlicher: document.getElementById('d1090_verantwortlicher').value,
      schweissaufsicht: document.getElementById('d1090_schweissaufsicht').value,
      pruefstelle: document.getElementById('d1090_pruefstelle').value,
      status: document.getElementById('d1090_status').value,
      notizen: document.getElementById('d1090_notizen').value,
    };

    const r = await din1090Api('save_project', data);
    if (r.ok) {
      din1090Notify('Projekt gespeichert.', 'success');
      document.getElementById('din1090ProjectModal')?.remove();
      await din1090LoadProjects();
      if (r.id) await din1090SelectProject(r.id);
      else renderDin1090();
    } else {
      din1090Notify(r.error || 'Fehler beim Speichern.', 'error');
    }
  };

  window.din1090DeleteProject = async function (id) {
    if (!confirm('Projekt und alle zugehörigen Daten (Material, Schweißdoku, Prüfungen, NCR, Checklisten) endgültig löschen?')) return;
    const r = await din1090Api('delete_project', { id });
    if (r.ok) {
      din1090Notify('Projekt gelöscht.', 'success');
      document.getElementById('din1090ProjectModal')?.remove();
      din1090CurrentProject = null;
      await din1090LoadProjects();
      renderDin1090();
    }
  };

  // ═════════════════════════════════════════════════════════════
  //  MATERIAL
  // ═════════════════════════════════════════════════════════════
  async function din1090RenderMaterial(el) {
    const pid = din1090CurrentProject.id;
    el.innerHTML = '<div class="din1090-loading">Lade Material…</div>';
    const r = await din1090Api('list_materials', { projectId: pid });
    const items = r.ok ? r.data : [];

    el.innerHTML = `
      <div class="din1090-section-header">
        <h3>Materialverfolgung & Werkszeugnisse</h3>
        ${din1090CanWrite() ? '<button class="btn btn-primary btn-sm" onclick="din1090EditMaterial(0)">+ Material</button>' : ''}
      </div>
      <p class="din1090-hint">Werkstoffzeugnisse nach EN 10204 — Rückverfolgbarkeit aller eingesetzten Materialien gemäß ${din1090CurrentProject.ausfuehrungsklasse}.</p>
      ${items.length === 0 ? '<p class="din1090-empty-msg">Noch keine Materialien erfasst.</p>' : `
        <div class="din1090-table-wrap">
          <table class="data-table">
            <thead><tr>
              <th>Bezeichnung</th><th>Werkstoff</th><th>Abmessung</th><th>Charge-Nr.</th>
              <th>Schmelz-Nr.</th><th>Zeugnis</th><th>Lieferant</th><th>Status</th><th></th>
            </tr></thead>
            <tbody>
              ${items.map(m => `<tr>
                <td>${din1090Esc(m.bezeichnung)}</td>
                <td>${din1090Esc(m.werkstoff)}</td>
                <td>${din1090Esc(m.abmessung)}</td>
                <td>${din1090Esc(m.charge_nr)}</td>
                <td>${din1090Esc(m.schmelz_nr)}</td>
                <td><span class="din1090-zeugnis-badge">${din1090Esc(m.zeugnis_typ)}</span> ${din1090Esc(m.zeugnis_nr)}</td>
                <td>${din1090Esc(m.lieferant)}</td>
                <td>${din1090PruefStatusBadge(m.pruef_status)}</td>
                <td>
                  ${din1090CanWrite() ? `<button class="btn btn-sm" onclick="din1090EditMaterial(${m.id})" title="Bearbeiten">✏️</button><button class="btn btn-sm" onclick="din1090DeleteMaterial(${m.id})" title="Löschen">🗑️</button>` : ''}
                </td>
              </tr>`).join('')}
            </tbody>
          </table>
        </div>
      `}
    `;
  }

  function din1090PruefStatusBadge(s) {
    const colors = { offen: '#f39c12', geprueft: '#27ae60', abgelehnt: '#e74c3c' };
    const labels = { offen: 'Offen', geprueft: 'Geprüft', abgelehnt: 'Abgelehnt' };
    return `<span style="background:${colors[s] || '#999'};color:#fff;padding:2px 6px;border-radius:3px;font-size:11px">${labels[s] || s}</span>`;
  }

  window.din1090EditMaterial = async function (id) {
    let item = { id: 0, bezeichnung: '', werkstoff: din1090CurrentProject.werkstoff, abmessung: '', charge_nr: '', schmelz_nr: '', zeugnis_typ: '3.1', zeugnis_nr: '', lieferant: '', menge: '', einheit: 'Stk', pruef_status: 'offen', bemerkung: '' };
    if (id > 0) {
      const r = await din1090Api('list_materials', { projectId: din1090CurrentProject.id });
      if (r.ok) { const found = r.data.find(m => m.id == id); if (found) item = found; }
    }

    din1090ShowFormModal('Material erfassen', [
      { id: 'bezeichnung', label: 'Bezeichnung *', value: item.bezeichnung },
      { id: 'werkstoff', label: 'Werkstoff', value: item.werkstoff },
      { id: 'abmessung', label: 'Abmessung / Profil', value: item.abmessung },
      { id: 'charge_nr', label: 'Chargen-Nr.', value: item.charge_nr },
      { id: 'schmelz_nr', label: 'Schmelz-Nr.', value: item.schmelz_nr },
      { id: 'zeugnis_typ', label: 'Zeugnis-Typ (EN 10204)', type: 'select', options: ['2.1','2.2','3.1','3.2'], value: item.zeugnis_typ },
      { id: 'zeugnis_nr', label: 'Zeugnis-Nr.', value: item.zeugnis_nr },
      { id: 'lieferant', label: 'Lieferant', value: item.lieferant },
      { id: 'menge', label: 'Menge', type: 'number', value: item.menge },
      { id: 'einheit', label: 'Einheit', type: 'select', options: ['Stk','m','kg','t','m²'], value: item.einheit },
      { id: 'pruef_status', label: 'Prüfstatus', type: 'select', options: ['offen','geprueft','abgelehnt'], value: item.pruef_status },
      { id: 'bemerkung', label: 'Bemerkung', type: 'textarea', value: item.bemerkung },
    ], async (vals) => {
      if (!vals.bezeichnung) { din1090Notify('Bezeichnung erforderlich.', 'error'); return false; }
      vals.projectId = din1090CurrentProject.id;
      vals.id = item.id;
      const r = await din1090Api('save_material', vals);
      if (r.ok) { din1090Notify('Material gespeichert.', 'success'); din1090SwitchTab('material'); return true; }
      din1090Notify(r.error || 'Fehler', 'error'); return false;
    }, id > 0 ? () => din1090DeleteMaterial(id) : null);
  };

  window.din1090DeleteMaterial = async function (id) {
    if (!confirm('Material-Eintrag löschen?')) return;
    await din1090Api('delete_material', { id, projectId: din1090CurrentProject.id });
    din1090Notify('Gelöscht.', 'success');
    document.getElementById('din1090FormModal')?.remove();
    din1090SwitchTab('material');
  };

  // ═════════════════════════════════════════════════════════════
  //  SCHWEIßEN (Schweißer, WPS, Protokoll)
  // ═════════════════════════════════════════════════════════════
  async function din1090RenderSchweissen(el) {
    const pid = din1090CurrentProject.id;
    el.innerHTML = '<div class="din1090-loading">Lade Schweißdokumentation…</div>';

    const [rWelders, rWps, rLog] = await Promise.all([
      din1090Api('list_welders'),
      din1090Api('list_wps', { projectId: pid }),
      din1090Api('list_weld_log', { projectId: pid }),
    ]);
    const welders = rWelders.ok ? rWelders.data : [];
    const wpsList = rWps.ok ? rWps.data : [];
    const weldLog = rLog.ok ? rLog.data : [];

    el.innerHTML = `
      <div class="din1090-sub-sections">
        <!-- Schweißer -->
        <div class="din1090-sub-section">
          <div class="din1090-section-header">
            <h3>Schweißer (EN ISO 9606)</h3>
            ${din1090CanWrite() ? '<button class="btn btn-primary btn-sm" onclick="din1090EditWelder(0)">+ Schweißer</button>' : ''}
          </div>
          ${welders.length === 0 ? '<p class="din1090-empty-msg">Noch keine Schweißer erfasst.</p>' : `
            <table class="data-table">
              <thead><tr><th>Name</th><th>Stempel</th><th>Qual.-Nr.</th><th>Verfahren</th><th>Gültig bis</th><th></th></tr></thead>
              <tbody>${welders.map(w => {
                const expiring = w.gueltig_bis && new Date(w.gueltig_bis) < new Date(Date.now() + 90*86400000);
                const expired = w.gueltig_bis && new Date(w.gueltig_bis) < new Date();
                return `<tr class="${expired ? 'din1090-row-danger' : expiring ? 'din1090-row-warn' : ''}">
                  <td>${din1090Esc(w.name)}</td>
                  <td>${din1090Esc(w.stempel_nr)}</td>
                  <td>${din1090Esc(w.qualifikation_nr)}</td>
                  <td>${din1090Esc(w.verfahren)}</td>
                  <td>${din1090Fmt(w.gueltig_bis)} ${expired ? '❌ abgelaufen' : expiring ? '⚠️' : ''}</td>
                  <td>
                    ${din1090CanWrite() ? `<button class="btn btn-sm" onclick="din1090EditWelder(${w.id})">✏️</button><button class="btn btn-sm" onclick="din1090DeleteWelder(${w.id})">🗑️</button>` : ''}
                  </td>
                </tr>`;
              }).join('')}</tbody>
            </table>
          `}
        </div>

        <!-- WPS -->
        <div class="din1090-sub-section">
          <div class="din1090-section-header">
            <h3>WPS – Schweißanweisungen (EN ISO 15609)</h3>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
              ${din1090CanWrite() ? '<button class="btn btn-secondary btn-sm" onclick="din1090CopyFrom(\'wps\')" title="WPS aus anderem Projekt kopieren">📋 Kopieren</button><button class="btn btn-primary btn-sm" onclick="din1090EditWps(0)">+ WPS</button>' : ''}
            </div>
          </div>
          ${wpsList.length === 0 ? '<p class="din1090-empty-msg">Noch keine WPS erfasst.</p>' : `
            <table class="data-table">
              <thead><tr><th>WPS-Nr.</th><th>Verfahren</th><th>Grundwerkstoff</th><th>Zusatzwerkstoff</th><th>Nahtart</th><th>Dicke</th><th>WPQR</th><th>Status</th><th></th></tr></thead>
              <tbody>${wpsList.map(w => `<tr>
                <td><strong>${din1090Esc(w.wps_nr)}</strong></td>
                <td>${din1090Esc(w.verfahren)}</td>
                <td>${din1090Esc(w.grundwerkstoff)}</td>
                <td>${din1090Esc(w.zusatzwerkstoff)}</td>
                <td>${din1090Esc(w.nahtart)}</td>
                <td>${w.blechdicke_von}–${w.blechdicke_bis} mm</td>
                <td>${din1090Esc(w.wpqr_nr)}</td>
                <td>${w.status === 'freigegeben' ? '<span style="color:#27ae60">✓ Freigegeben</span>' : '<span style="color:#e74c3c">✗ Gesperrt</span>'}</td>
                <td>
                  ${din1090CanWrite() ? `<button class="btn btn-sm" onclick="din1090EditWps(${w.id})">✏️</button><button class="btn btn-sm" onclick="din1090DeleteWps(${w.id})">🗑️</button>` : ''}
                </td>
              </tr>`).join('')}</tbody>
            </table>
          `}
        </div>

        <!-- Schweißprotokoll -->
        <div class="din1090-sub-section">
          <div class="din1090-section-header">
            <h3>Schweißnahtprotokoll</h3>
            <div style="display:flex;gap:6px;flex-wrap:wrap;">
              ${din1090CanWrite() ? '<button class="btn btn-secondary btn-sm" onclick="din1090CopyFrom(\'weld_log\')" title="Schweißnähte aus anderem Projekt kopieren">📋 Kopieren</button><button class="btn btn-primary btn-sm" onclick="din1090EditWeldLog(0)">+ Schweißnaht</button>' : ''}
            </div>
          </div>
          ${weldLog.length === 0 ? '<p class="din1090-empty-msg">Noch keine Schweißnähte protokolliert.</p>' : `
            <div class="din1090-table-wrap">
              <table class="data-table">
                <thead><tr><th>Naht-Nr.</th><th>Bauteil</th><th>WPS</th><th>Schweißer</th><th>Datum</th><th>Nahtart</th><th>a-Maß</th><th>Länge</th><th>Status</th><th></th></tr></thead>
                <tbody>${weldLog.map(w => `<tr>
                  <td><strong>${din1090Esc(w.naht_nr)}</strong></td>
                  <td>${din1090Esc(w.bauteil)}</td>
                  <td>${din1090Esc(w.wps_nr || '–')}</td>
                  <td>${din1090Esc(w.schweisser_name || '–')}</td>
                  <td>${din1090Fmt(w.datum)}</td>
                  <td>${din1090Esc(w.nahtart)}</td>
                  <td>${w.a_mass || '–'} mm</td>
                  <td>${w.laenge || '–'} mm</td>
                  <td>${din1090PruefStatusBadge(w.pruef_status)}</td>
                  <td>
                    ${din1090CanWrite() ? `<button class="btn btn-sm" onclick="din1090EditWeldLog(${w.id})">✏️</button><button class="btn btn-sm" onclick="din1090DeleteWeldLog(${w.id})">🗑️</button>` : ''}
                  </td>
                </tr>`).join('')}</tbody>
              </table>
            </div>
          `}
        </div>
      </div>
    `;
  }

  // ── Schweißer CRUD ─────────────────────────────────────────
  window.din1090EditWelder = async function (id) {
    let item = { id: 0, name: '', stempel_nr: '', qualifikation_nr: '', norm: 'EN ISO 9606-1', verfahren: '135', position: '', werkstoff_gruppe: '', dicke_bereich: '', gueltig_bis: '', bemerkung: '' };
    if (id > 0) {
      const r = await din1090Api('list_welders');
      if (r.ok) { const f = r.data.find(w => w.id == id); if (f) item = f; }
    }

    din1090ShowFormModal('Schweißer erfassen', [
      { id: 'name', label: 'Name *', value: item.name },
      { id: 'stempel_nr', label: 'Stempel-Nr. / Kennzeichen', value: item.stempel_nr },
      { id: 'qualifikation_nr', label: 'Qualifikations-Nr.', value: item.qualifikation_nr },
      { id: 'norm', label: 'Prüfnorm', type: 'select', options: ['EN ISO 9606-1','EN ISO 9606-2','EN ISO 14732','Sonstige'], value: item.norm },
      { id: 'verfahren', label: 'Schweißverfahren', type: 'select', options: ['111 (E)','114 (FCAW-S)','121 (UP)','131 (MIG)','135 (MAG)','136 (FCAW)','141 (WIG/TIG)','15 (Plasma)','311 (Autogen)'], value: item.verfahren },
      { id: 'position', label: 'Positionen (z.B. PA, PB, PC, PF)', value: item.position },
      { id: 'werkstoff_gruppe', label: 'Werkstoffgruppe (z.B. 1.1, 1.2, 8)', value: item.werkstoff_gruppe },
      { id: 'dicke_bereich', label: 'Dickenbereich (z.B. 3–30 mm)', value: item.dicke_bereich },
      { id: 'gueltig_bis', label: 'Gültig bis', type: 'date', value: item.gueltig_bis },
      { id: 'bemerkung', label: 'Bemerkung', type: 'textarea', value: item.bemerkung },
    ], async (vals) => {
      if (!vals.name) { din1090Notify('Name erforderlich.', 'error'); return false; }
      vals.id = item.id;
      const r = await din1090Api('save_welder', vals);
      if (r.ok) { din1090Notify('Schweißer gespeichert.', 'success'); din1090SwitchTab('schweissen'); return true; }
      din1090Notify(r.error || 'Fehler', 'error'); return false;
    }, id > 0 ? () => din1090DeleteWelder(id) : null);
  };

  window.din1090DeleteWelder = async function (id) {
    if (!confirm('Schweißer löschen?')) return;
    await din1090Api('delete_welder', { id });
    din1090Notify('Gelöscht.', 'success');
    document.getElementById('din1090FormModal')?.remove();
    din1090SwitchTab('schweissen');
  };

  // ── WPS CRUD ───────────────────────────────────────────────
  window.din1090EditWps = async function (id) {
    let item = { id: 0, wps_nr: '', verfahren: '135 (MAG)', grundwerkstoff: din1090CurrentProject.werkstoff, zusatzwerkstoff: '', schutzgas: '', position: 'PA', nahtart: 'Stumpfnaht', blechdicke_von: '', blechdicke_bis: '', vorwaermung: '', wpqr_nr: '', status: 'freigegeben', bemerkung: '' };
    if (id > 0) {
      const r = await din1090Api('list_wps', { projectId: din1090CurrentProject.id });
      if (r.ok) { const f = r.data.find(w => w.id == id); if (f) item = f; }
    }

    din1090ShowFormModal('WPS – Schweißanweisung', [
      { id: 'wps_nr', label: 'WPS-Nr. *', value: item.wps_nr },
      { id: 'verfahren', label: 'Schweißverfahren', value: item.verfahren },
      { id: 'grundwerkstoff', label: 'Grundwerkstoff', value: item.grundwerkstoff },
      { id: 'zusatzwerkstoff', label: 'Zusatzwerkstoff', value: item.zusatzwerkstoff },
      { id: 'schutzgas', label: 'Schutzgas', value: item.schutzgas },
      { id: 'position', label: 'Schweißposition', type: 'select', options: ['PA','PB','PC','PD','PE','PF','PG','PH','PJ','PK','H-L045','J-L045'], value: item.position },
      { id: 'nahtart', label: 'Nahtart', type: 'select', options: ['Stumpfnaht','Kehlnaht','Ecknaht','Schlitznaht','Lochnaht','Sonstige'], value: item.nahtart },
      { id: 'blechdicke_von', label: 'Blechdicke von (mm)', type: 'number', value: item.blechdicke_von },
      { id: 'blechdicke_bis', label: 'Blechdicke bis (mm)', type: 'number', value: item.blechdicke_bis },
      { id: 'vorwaermung', label: 'Vorwärmung', value: item.vorwaermung },
      { id: 'wpqr_nr', label: 'WPQR-Nr. (Verfahrensprüfung)', value: item.wpqr_nr },
      { id: 'status', label: 'Status', type: 'select', options: ['freigegeben','gesperrt'], value: item.status },
      { id: 'bemerkung', label: 'Bemerkung', type: 'textarea', value: item.bemerkung },
    ], async (vals) => {
      if (!vals.wps_nr) { din1090Notify('WPS-Nr. erforderlich.', 'error'); return false; }
      vals.projectId = din1090CurrentProject.id;
      vals.id = item.id;
      const r = await din1090Api('save_wps', vals);
      if (r.ok) { din1090Notify('WPS gespeichert.', 'success'); din1090SwitchTab('schweissen'); return true; }
      din1090Notify(r.error || 'Fehler', 'error'); return false;
    }, id > 0 ? () => din1090DeleteWps(id) : null);
  };

  window.din1090DeleteWps = async function (id) {
    if (!confirm('WPS löschen?')) return;
    await din1090Api('delete_wps', { id, projectId: din1090CurrentProject.id });
    din1090Notify('Gelöscht.', 'success');
    document.getElementById('din1090FormModal')?.remove();
    din1090SwitchTab('schweissen');
  };

  // ── Schweißnaht CRUD ───────────────────────────────────────
  window.din1090EditWeldLog = async function (id) {
    const pid = din1090CurrentProject.id;
    const [rWelders, rWps] = await Promise.all([
      din1090Api('list_welders'),
      din1090Api('list_wps', { projectId: pid }),
    ]);
    const welders = rWelders.ok ? rWelders.data : [];
    const wpsList = rWps.ok ? rWps.data : [];

    let item = { id: 0, naht_nr: '', bauteil: '', zeichnung_nr: '', wps_id: '', schweisser_id: '', datum: new Date().toISOString().slice(0,10), position: 'PA', nahtart: 'Kehlnaht', a_mass: '', laenge: '', vorwaermung: '', zwischenlagen_temp: '', pruef_status: 'offen', vt_ergebnis: '', bemerkung: '' };
    if (id > 0) {
      const r = await din1090Api('list_weld_log', { projectId: pid });
      if (r.ok) { const f = r.data.find(w => w.id == id); if (f) item = f; }
    }

    din1090ShowFormModal('Schweißnaht protokollieren', [
      { id: 'naht_nr', label: 'Naht-Nr. *', value: item.naht_nr },
      { id: 'bauteil', label: 'Bauteil / Pos.', value: item.bauteil },
      { id: 'zeichnung_nr', label: 'Zeichnungs-Nr.', value: item.zeichnung_nr },
      { id: 'wps_id', label: 'WPS', type: 'select', options: [''].concat(wpsList.map(w => w.id + '|' + w.wps_nr)), value: item.wps_id, optionLabels: ['— Keine —'].concat(wpsList.map(w => w.wps_nr)) },
      { id: 'schweisser_id', label: 'Schweißer', type: 'select', options: [''].concat(welders.map(w => w.id + '|' + w.name)), value: item.schweisser_id, optionLabels: ['— Kein —'].concat(welders.map(w => w.name + (w.stempel_nr ? ' (' + w.stempel_nr + ')' : ''))) },
      { id: 'datum', label: 'Datum', type: 'date', value: item.datum },
      { id: 'position', label: 'Position', type: 'select', options: ['PA','PB','PC','PD','PE','PF','PG'], value: item.position },
      { id: 'nahtart', label: 'Nahtart', type: 'select', options: ['Kehlnaht','Stumpfnaht','Ecknaht','Schlitznaht','Sonstige'], value: item.nahtart },
      { id: 'a_mass', label: 'a-Maß (mm)', type: 'number', value: item.a_mass },
      { id: 'laenge', label: 'Länge (mm)', type: 'number', value: item.laenge },
      { id: 'vorwaermung', label: 'Vorwärmung (°C)', value: item.vorwaermung },
      { id: 'zwischenlagen_temp', label: 'Zwischenlagentemp. (°C)', value: item.zwischenlagen_temp },
      { id: 'pruef_status', label: 'Prüfstatus', type: 'select', options: ['offen','geprueft','abgelehnt'], value: item.pruef_status },
      { id: 'vt_ergebnis', label: 'VT-Ergebnis', type: 'select', options: ['','i.O.','n.i.O.','Nacharbeit'], value: item.vt_ergebnis },
      { id: 'bemerkung', label: 'Bemerkung', type: 'textarea', value: item.bemerkung },
    ], async (vals) => {
      if (!vals.naht_nr) { din1090Notify('Naht-Nr. erforderlich.', 'error'); return false; }
      // Pipe-getrennte Select-Werte aufbereiten
      if (vals.wps_id && String(vals.wps_id).includes('|')) vals.wps_id = vals.wps_id.split('|')[0];
      if (vals.schweisser_id && String(vals.schweisser_id).includes('|')) vals.schweisser_id = vals.schweisser_id.split('|')[0];
      vals.projectId = din1090CurrentProject.id;
      vals.id = item.id;
      const r = await din1090Api('save_weld_log', vals);
      if (r.ok) { din1090Notify('Schweißnaht gespeichert.', 'success'); din1090SwitchTab('schweissen'); return true; }
      din1090Notify(r.error || 'Fehler', 'error'); return false;
    }, id > 0 ? () => din1090DeleteWeldLog(id) : null);
  };

  window.din1090DeleteWeldLog = async function (id) {
    if (!confirm('Schweißnaht-Eintrag löschen?')) return;
    await din1090Api('delete_weld_log', { id, projectId: din1090CurrentProject.id });
    din1090Notify('Gelöscht.', 'success');
    document.getElementById('din1090FormModal')?.remove();
    din1090SwitchTab('schweissen');
  };

  // ═════════════════════════════════════════════════════════════
  //  PRÜFUNGEN
  // ═════════════════════════════════════════════════════════════
  async function din1090RenderPruefungen(el) {
    const pid = din1090CurrentProject.id;
    el.innerHTML = '<div class="din1090-loading">Lade Prüfungen…</div>';
    const r = await din1090Api('list_inspections', { projectId: pid });
    const items = r.ok ? r.data : [];

    el.innerHTML = `
      <div class="din1090-section-header">
        <h3>Prüfungen & Kontrollen</h3>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
          ${din1090CanWrite() ? '<button class="btn btn-secondary btn-sm" onclick="din1090CopyFrom(\'inspections\')" title="Prüfungen aus anderem Projekt kopieren">📋 Kopieren</button><button class="btn btn-primary btn-sm" onclick="din1090EditInspection(0)">+ Prüfung</button>' : ''}
        </div>
      </div>
      <p class="din1090-hint">Zerstörungsfreie Prüfungen (ZfP) nach EN 1090-2 Tabelle 24 — Prüfumfänge je nach Ausführungsklasse.</p>
      ${items.length === 0 ? '<p class="din1090-empty-msg">Noch keine Prüfungen dokumentiert.</p>' : `
        <div class="din1090-table-wrap">
          <table class="data-table">
            <thead><tr><th>Prüfart</th><th>Bauteil</th><th>Naht-Nr.</th><th>Datum</th><th>Prüfer</th><th>Norm</th><th>Ergebnis</th><th>Report</th><th></th></tr></thead>
            <tbody>${items.map(i => `<tr>
              <td><span class="din1090-pruef-badge pruef-${i.pruef_art}">${i.pruef_art}</span></td>
              <td>${din1090Esc(i.bauteil)}</td>
              <td>${din1090Esc(i.naht_nr)}</td>
              <td>${din1090Fmt(i.pruef_datum)}</td>
              <td>${din1090Esc(i.pruefer)}</td>
              <td>${din1090Esc(i.pruef_norm)}</td>
              <td>${din1090ErgebnisBadge(i.ergebnis)}</td>
              <td>${din1090Esc(i.report_nr)}</td>
              <td>
                ${din1090CanWrite() ? `<button class="btn btn-sm" onclick="din1090EditInspection(${i.id})">✏️</button><button class="btn btn-sm" onclick="din1090DeleteInspection(${i.id})">🗑️</button>` : ''}
              </td>
            </tr>`).join('')}</tbody>
          </table>
        </div>
      `}
    `;
  }

  function din1090ErgebnisBadge(e) {
    const c = { bestanden: '#27ae60', nacharbeit: '#f39c12', abgelehnt: '#e74c3c' };
    const l = { bestanden: '✓ Bestanden', nacharbeit: '⚠ Nacharbeit', abgelehnt: '✗ Abgelehnt' };
    return `<span style="color:${c[e] || '#666'};font-weight:600">${l[e] || e}</span>`;
  }

  window.din1090EditInspection = async function (id) {
    let item = { id: 0, pruef_art: 'VT', bauteil: '', naht_nr: '', pruef_datum: new Date().toISOString().slice(0,10), pruefer: '', pruef_norm: 'EN ISO 17637', ergebnis: 'bestanden', report_nr: '', umfang: '', bemerkung: '' };
    if (id > 0) {
      const r = await din1090Api('list_inspections', { projectId: din1090CurrentProject.id });
      if (r.ok) { const f = r.data.find(i => i.id == id); if (f) item = f; }
    }

    const normMap = { VT: 'EN ISO 17637', PT: 'EN ISO 3452-1', MT: 'EN ISO 17638', UT: 'EN ISO 17640', RT: 'EN ISO 17636-1' };

    din1090ShowFormModal('Prüfung dokumentieren', [
      { id: 'pruef_art', label: 'Prüfart *', type: 'select', options: ['VT','PT','MT','UT','RT','Maßkontrolle','Sonstige'], value: item.pruef_art, onChange: (v) => { const normF = document.getElementById('dm_pruef_norm'); if (normF && normMap[v]) normF.value = normMap[v]; } },
      { id: 'bauteil', label: 'Bauteil / Pos.', value: item.bauteil },
      { id: 'naht_nr', label: 'Naht-Nr. / Bereich', value: item.naht_nr },
      { id: 'pruef_datum', label: 'Prüfdatum', type: 'date', value: item.pruef_datum },
      { id: 'pruefer', label: 'Prüfer / Prüfstelle', value: item.pruefer },
      { id: 'pruef_norm', label: 'Prüfnorm', value: item.pruef_norm },
      { id: 'ergebnis', label: 'Ergebnis', type: 'select', options: ['bestanden','nacharbeit','abgelehnt'], value: item.ergebnis },
      { id: 'report_nr', label: 'Prüfbericht-Nr.', value: item.report_nr },
      { id: 'umfang', label: 'Prüfumfang', value: item.umfang },
      { id: 'bemerkung', label: 'Bemerkung', type: 'textarea', value: item.bemerkung },
    ], async (vals) => {
      vals.projectId = din1090CurrentProject.id;
      vals.id = item.id;
      const r = await din1090Api('save_inspection', vals);
      if (r.ok) { din1090Notify('Prüfung gespeichert.', 'success'); din1090SwitchTab('pruefungen'); return true; }
      din1090Notify(r.error || 'Fehler', 'error'); return false;
    }, id > 0 ? () => din1090DeleteInspection(id) : null);
  };

  window.din1090DeleteInspection = async function (id) {
    if (!confirm('Prüfung löschen?')) return;
    await din1090Api('delete_inspection', { id, projectId: din1090CurrentProject.id });
    din1090Notify('Gelöscht.', 'success');
    document.getElementById('din1090FormModal')?.remove();
    din1090SwitchTab('pruefungen');
  };

  // ═════════════════════════════════════════════════════════════
  //  OBERFLÄCHENBEHANDLUNG
  // ═════════════════════════════════════════════════════════════
  async function din1090RenderOberflaeche(el) {
    const pid = din1090CurrentProject.id;
    el.innerHTML = '<div class="din1090-loading">Lade Oberflächenbehandlung…</div>';
    const r = await din1090Api('list_surface', { projectId: pid });
    const items = r.ok ? r.data : [];

    el.innerHTML = `
      <div class="din1090-section-header">
        <h3>Oberflächenbehandlung & Korrosionsschutz</h3>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
          ${din1090CanWrite() ? '<button class="btn btn-secondary btn-sm" onclick="din1090CopyFrom(\'surface\')" title="Oberflächen aus anderem Projekt kopieren">📋 Kopieren</button><button class="btn btn-primary btn-sm" onclick="din1090EditSurface(0)">+ Oberfläche</button>' : ''}
        </div>
      </div>
      <p class="din1090-hint">Korrosionsschutzsystem nach EN ISO 12944 — Vorbehandlung, Beschichtung, Schichtdickenmessung.</p>
      ${items.length === 0 ? '<p class="din1090-empty-msg">Noch keine Oberflächenbehandlungen dokumentiert.</p>' : `
        <div class="din1090-table-wrap">
          <table class="data-table">
            <thead><tr><th>Bauteil</th><th>System</th><th>Vorbehandlung</th><th>Grundierung</th><th>Soll (µm)</th><th>Ist (µm)</th><th>Prüfdatum</th><th>Ergebnis</th><th></th></tr></thead>
            <tbody>${items.map(i => `<tr>
              <td>${din1090Esc(i.bauteil)}</td>
              <td>${din1090Esc(i.system)}</td>
              <td>${din1090Esc(i.vorbehandlung)}</td>
              <td>${din1090Esc(i.grundierung)}</td>
              <td>${i.soll_dicke || '–'}</td>
              <td>${i.ist_dicke || '–'}</td>
              <td>${din1090Fmt(i.pruef_datum)}</td>
              <td>${din1090ErgebnisBadge(i.ergebnis)}</td>
              <td>
                ${din1090CanWrite() ? `<button class="btn btn-sm" onclick="din1090EditSurface(${i.id})">✏️</button><button class="btn btn-sm" onclick="din1090DeleteSurface(${i.id})">🗑️</button>` : ''}
              </td>
            </tr>`).join('')}</tbody>
          </table>
        </div>
      `}
    `;
  }

  window.din1090EditSurface = async function (id) {
    let item = { id: 0, bauteil: '', system: '', vorbehandlung: 'Sa 2½', grundierung: '', schicht_1: '', schicht_2: '', soll_dicke: '', ist_dicke: '', pruef_datum: new Date().toISOString().slice(0,10), pruefer: '', ergebnis: 'bestanden', bemerkung: '' };
    if (id > 0) {
      const r = await din1090Api('list_surface', { projectId: din1090CurrentProject.id });
      if (r.ok) { const f = r.data.find(i => i.id == id); if (f) item = f; }
    }

    din1090ShowFormModal('Oberflächenbehandlung dokumentieren', [
      { id: 'bauteil', label: 'Bauteil / Pos.', value: item.bauteil },
      { id: 'system', label: 'Korrosionsschutzsystem (z.B. C3, C4)', value: item.system },
      { id: 'vorbehandlung', label: 'Vorbehandlung (EN ISO 8501)', type: 'select', options: ['Sa 1','Sa 2','Sa 2½','Sa 3','St 2','St 3','Fl','Be'], value: item.vorbehandlung },
      { id: 'grundierung', label: 'Grundierung', value: item.grundierung },
      { id: 'schicht_1', label: 'Zwischenbeschichtung', value: item.schicht_1 },
      { id: 'schicht_2', label: 'Deckbeschichtung', value: item.schicht_2 },
      { id: 'soll_dicke', label: 'Soll-Schichtdicke (µm)', type: 'number', value: item.soll_dicke },
      { id: 'ist_dicke', label: 'Ist-Schichtdicke (µm)', type: 'number', value: item.ist_dicke },
      { id: 'pruef_datum', label: 'Prüfdatum', type: 'date', value: item.pruef_datum },
      { id: 'pruefer', label: 'Prüfer', value: item.pruefer },
      { id: 'ergebnis', label: 'Ergebnis', type: 'select', options: ['bestanden','nacharbeit','abgelehnt'], value: item.ergebnis },
      { id: 'bemerkung', label: 'Bemerkung', type: 'textarea', value: item.bemerkung },
    ], async (vals) => {
      vals.projectId = din1090CurrentProject.id;
      vals.id = item.id;
      const r = await din1090Api('save_surface', vals);
      if (r.ok) { din1090Notify('Oberflächenbehandlung gespeichert.', 'success'); din1090SwitchTab('oberflaeche'); return true; }
      din1090Notify(r.error || 'Fehler', 'error'); return false;
    }, id > 0 ? () => din1090DeleteSurface(id) : null);
  };

  window.din1090DeleteSurface = async function (id) {
    if (!confirm('Eintrag löschen?')) return;
    await din1090Api('delete_surface', { id, projectId: din1090CurrentProject.id });
    din1090Notify('Gelöscht.', 'success');
    document.getElementById('din1090FormModal')?.remove();
    din1090SwitchTab('oberflaeche');
  };

  // ═════════════════════════════════════════════════════════════
  //  ABWEICHUNGEN (NCR)
  // ═════════════════════════════════════════════════════════════
  async function din1090RenderAbweichungen(el) {
    const pid = din1090CurrentProject.id;
    el.innerHTML = '<div class="din1090-loading">Lade Abweichungsberichte…</div>';
    const r = await din1090Api('list_ncr', { projectId: pid });
    const items = r.ok ? r.data : [];

    el.innerHTML = `
      <div class="din1090-section-header">
        <h3>Abweichungsberichte (NCR)</h3>
        ${din1090CanWrite() ? '<button class="btn btn-primary btn-sm" onclick="din1090EditNcr(0)">+ NCR</button>' : ''}
      </div>
      <p class="din1090-hint">Non-Conformity Reports — Dokumentation und Nachverfolgung aller Abweichungen gemäß DIN EN 1090.</p>
      ${items.length === 0 ? '<p class="din1090-empty-msg">Keine offenen Abweichungen — sehr gut!</p>' : `
        <div class="din1090-table-wrap">
          <table class="data-table">
            <thead><tr><th>NCR-Nr.</th><th>Datum</th><th>Bauteil</th><th>Beschreibung</th><th>Maßnahme</th><th>Verantw.</th><th>Frist</th><th>Status</th><th></th></tr></thead>
            <tbody>${items.map(n => `<tr class="${n.status === 'offen' ? 'din1090-row-warn' : n.status === 'geschlossen' ? '' : ''}">
              <td><strong>${din1090Esc(n.ncr_nr)}</strong></td>
              <td>${din1090Fmt(n.datum)}</td>
              <td>${din1090Esc(n.bauteil)}</td>
              <td>${din1090Esc(n.beschreibung).substring(0,60)}${n.beschreibung.length > 60 ? '…' : ''}</td>
              <td>${din1090Esc(n.massnahme).substring(0,40)}${n.massnahme.length > 40 ? '…' : ''}</td>
              <td>${din1090Esc(n.verantwortlicher)}</td>
              <td>${din1090Fmt(n.frist)}</td>
              <td>${din1090NcrStatusBadge(n.status)}</td>
              <td>
                ${din1090CanWrite() ? `<button class="btn btn-sm" onclick="din1090EditNcr(${n.id})">✏️</button><button class="btn btn-sm" onclick="din1090DeleteNcr(${n.id})">🗑️</button>` : ''}
              </td>
            </tr>`).join('')}</tbody>
          </table>
        </div>
      `}
    `;
  }

  function din1090NcrStatusBadge(s) {
    const c = { offen: '#e74c3c', in_bearbeitung: '#f39c12', geschlossen: '#27ae60' };
    const l = { offen: 'Offen', in_bearbeitung: 'In Bearbeitung', geschlossen: 'Geschlossen' };
    return `<span style="background:${c[s] || '#999'};color:#fff;padding:2px 8px;border-radius:4px;font-size:12px">${l[s] || s}</span>`;
  }

  window.din1090EditNcr = async function (id) {
    let item = { id: 0, ncr_nr: '', datum: new Date().toISOString().slice(0,10), bauteil: '', beschreibung: '', ursache: '', massnahme: '', verantwortlicher: '', frist: '', status: 'offen' };
    if (id > 0) {
      const r = await din1090Api('list_ncr', { projectId: din1090CurrentProject.id });
      if (r.ok) { const f = r.data.find(n => n.id == id); if (f) item = f; }
    }

    din1090ShowFormModal('Abweichungsbericht (NCR)', [
      { id: 'ncr_nr', label: 'NCR-Nr. *', value: item.ncr_nr },
      { id: 'datum', label: 'Datum', type: 'date', value: item.datum },
      { id: 'bauteil', label: 'Bauteil / Position', value: item.bauteil },
      { id: 'beschreibung', label: 'Beschreibung der Abweichung *', type: 'textarea', value: item.beschreibung },
      { id: 'ursache', label: 'Ursache', type: 'textarea', value: item.ursache },
      { id: 'massnahme', label: 'Korrekturmaßnahme', type: 'textarea', value: item.massnahme },
      { id: 'verantwortlicher', label: 'Verantwortlicher', value: item.verantwortlicher },
      { id: 'frist', label: 'Frist', type: 'date', value: item.frist },
      { id: 'status', label: 'Status', type: 'select', options: ['offen','in_bearbeitung','geschlossen'], value: item.status },
    ], async (vals) => {
      if (!vals.ncr_nr) { din1090Notify('NCR-Nr. erforderlich.', 'error'); return false; }
      vals.projectId = din1090CurrentProject.id;
      vals.id = item.id;
      const r = await din1090Api('save_ncr', vals);
      if (r.ok) { din1090Notify('NCR gespeichert.', 'success'); din1090SwitchTab('abweichungen'); return true; }
      din1090Notify(r.error || 'Fehler', 'error'); return false;
    }, id > 0 ? () => din1090DeleteNcr(id) : null);
  };

  window.din1090DeleteNcr = async function (id) {
    if (!confirm('NCR endgültig löschen?')) return;
    await din1090Api('delete_ncr', { id, projectId: din1090CurrentProject.id });
    din1090Notify('Gelöscht.', 'success');
    document.getElementById('din1090FormModal')?.remove();
    din1090SwitchTab('abweichungen');
  };

  // ═════════════════════════════════════════════════════════════
  //  CHECKLISTEN
  // ═════════════════════════════════════════════════════════════
  async function din1090RenderChecklisten(el) {
    const pid = din1090CurrentProject.id;
    el.innerHTML = '<div class="din1090-loading">Lade Checklisten…</div>';
    const r = await din1090Api('get_checklists', { projectId: pid });
    const items = r.ok ? r.data : [];

    if (items.length === 0) {
      el.innerHTML = `
        <div class="din1090-section-header">
          <h3>Checklisten nach DIN EN 1090-2</h3>
        </div>
        <div class="din1090-empty-msg">
          <p>Noch keine Checkliste vorhanden.</p>
          ${din1090CanWrite() ? `<button class="btn btn-primary" onclick="din1090GenerateChecklist()">Checkliste für ${din1090CurrentProject.ausfuehrungsklasse} generieren</button>` : ''}
        </div>
      `;
      return;
    }

    // Gruppieren nach Kategorie
    const groups = {};
    items.forEach(i => {
      if (!groups[i.kategorie]) groups[i.kategorie] = [];
      groups[i.kategorie].push(i);
    });

    const total = items.length;
    const done = items.filter(i => i.erledigt == 1).length;
    const pct = total > 0 ? Math.round(done / total * 100) : 0;

    el.innerHTML = `
      <div class="din1090-section-header">
        <h3>Checklisten nach DIN EN 1090-2 (${din1090CurrentProject.ausfuehrungsklasse})</h3>
        <div>
          ${din1090CanWrite() ? '<button class="btn btn-sm btn-secondary" onclick="din1090GenerateChecklist()" title="Checkliste neu generieren">🔄 Neu generieren</button>' : ''}
        </div>
      </div>
      <div class="din1090-checklist-progress">
        <div class="din1090-progress" style="height:20px;margin-bottom:10px">
          <div class="din1090-progress-bar" style="width:${pct}%">${pct}% (${done}/${total})</div>
        </div>
      </div>

      ${Object.entries(groups).map(([kat, list]) => {
        const katDone = list.filter(i => i.erledigt == 1).length;
        return `
          <div class="din1090-checklist-group">
            <h4 class="din1090-checklist-kat">${din1090Esc(kat)} <span class="din1090-checklist-counter">${katDone}/${list.length}</span></h4>
            <div class="din1090-checklist-items">
              ${list.map(i => `
                <div class="din1090-checklist-item ${i.erledigt == 1 ? 'done' : ''}">
                  <label class="din1090-check-label">
                    <input type="checkbox" ${i.erledigt == 1 ? 'checked' : ''} ${din1090CanWrite() ? '' : 'disabled'} onchange="din1090ToggleCheck(${i.id}, this.checked)">
                    <span>${din1090Esc(i.punkt)}</span>
                    <small class="din1090-exc-hint">ab EXC${i.erforderlich_ab}</small>
                  </label>
                  ${i.erledigt == 1 && i.erledigt_von ? `<small class="din1090-check-meta">✓ ${din1090Esc(i.erledigt_von)}, ${din1090Fmt(i.erledigt_am?.split(' ')[0])}</small>` : ''}
                  <input type="text" class="din1090-check-note" placeholder="Bemerkung…" value="${din1090Esc(i.bemerkung || '')}" ${din1090CanWrite() ? '' : 'readonly'} onchange="din1090UpdateCheckNote(${i.id}, this.value)">
                </div>
              `).join('')}
            </div>
          </div>
        `;
      }).join('')}
    `;
  }

  window.din1090ToggleCheck = async function (id, checked) {
    // Aktuelle Notiz aus DOM lesen, damit sie beim Toggle nicht überschrieben wird
    const noteEl = document.querySelector(`.din1090-checklist-item input[type="text"][onchange*="din1090UpdateCheckNote(${id},"]`);
    const bemerkung = noteEl ? noteEl.value : '';
    const r = await din1090Api('update_checklist', { id, erledigt: checked ? 1 : 0, bemerkung });
    if (!r.ok) return;

    // Inline-Update statt komplettes Re-Render (verhindert Scroll-nach-oben)
    const cb = document.querySelector(`.din1090-checklist-item input[type="checkbox"][onchange*="din1090ToggleCheck(${id},"]`);
    if (cb) {
      const item = cb.closest('.din1090-checklist-item');
      if (checked) {
        item.classList.add('done');
      } else {
        item.classList.remove('done');
        // Meta-Info entfernen
        const meta = item.querySelector('.din1090-check-meta');
        if (meta) meta.remove();
      }
    }

    // Fortschrittsbalken und Zähler aktualisieren
    const allBoxes = document.querySelectorAll('.din1090-checklist-item input[type="checkbox"]');
    const total = allBoxes.length;
    const done = [...allBoxes].filter(c => c.checked).length;
    const pct = total > 0 ? Math.round(done / total * 100) : 0;
    const bar = document.querySelector('.din1090-progress-bar');
    if (bar) { bar.style.width = pct + '%'; bar.textContent = `${pct}% (${done}/${total})`; }

    // Kategorie-Zähler aktualisieren
    document.querySelectorAll('.din1090-checklist-group').forEach(g => {
      const boxes = g.querySelectorAll('input[type="checkbox"]');
      const gDone = [...boxes].filter(c => c.checked).length;
      const counter = g.querySelector('.din1090-checklist-counter');
      if (counter) counter.textContent = `${gDone}/${boxes.length}`;
    });
  };

  window.din1090UpdateCheckNote = async function (id, bemerkung) {
    // Nur Bemerkung aktualisieren, Checkbox-Status beibehalten
    const allChecks = document.querySelectorAll('.din1090-checklist-item');
    // Einfach die Bemerkung speichern
    await din1090Api('update_checklist', { id, erledigt: -1, bemerkung }); // -1 = nicht ändern
  };

  window.din1090GenerateChecklist = async function () {
    const exc = parseInt(din1090CurrentProject.ausfuehrungsklasse.replace('EXC', ''));
    if (!confirm(`Checkliste für EXC${exc} neu generieren? Bestehende Einträge werden überschrieben.`)) return;
    await din1090Api('generate_checklist', { projectId: din1090CurrentProject.id, excLevel: exc });
    din1090Notify(`Checkliste für EXC${exc} generiert.`, 'success');
    din1090SwitchTab('checklisten');
  };

  // ═════════════════════════════════════════════════════════════
  //  AUDIT LOG / PROTOKOLL
  // ═════════════════════════════════════════════════════════════
  async function din1090RenderAudit(el) {
    const pid = din1090CurrentProject.id;
    el.innerHTML = '<div class="din1090-loading">Lade Protokoll…</div>';
    const r = await din1090Api('get_audit_log', { projectId: pid });
    const items = r.ok ? r.data : [];

    el.innerHTML = `
      <div class="din1090-section-header">
        <h3>Änderungsprotokoll (Audit Trail)</h3>
      </div>
      <p class="din1090-hint">Lückenlose Nachverfolgung aller Änderungen — Aufbewahrungsfrist mind. 10 Jahre gemäß DIN EN 1090.</p>
      ${items.length === 0 ? '<p class="din1090-empty-msg">Noch keine Einträge.</p>' : `
        <table class="data-table">
          <thead><tr><th>Zeitpunkt</th><th>Benutzer</th><th>Aktion</th><th>Details</th></tr></thead>
          <tbody>${items.map(a => `<tr>
            <td>${din1090Esc(a.zeitpunkt)}</td>
            <td>${din1090Esc(a.benutzer)}</td>
            <td>${din1090Esc(a.aktion)}</td>
            <td>${din1090Esc(a.details)}</td>
          </tr>`).join('')}</tbody>
        </table>
      `}
    `;
  }

  // ═════════════════════════════════════════════════════════════
  //  GENERISCHE FORMULAR-MODAL
  // ═════════════════════════════════════════════════════════════
  function din1090ShowFormModal(title, fields, onSave, onDelete) {
    document.getElementById('din1090FormModal')?.remove();

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'din1090FormModal';

    let fieldsHtml = '';
    for (const f of fields) {
      const inputId = 'dm_' + f.id;
      let inputHtml = '';

      if (f.type === 'select') {
        const opts = f.options || [];
        const labels = f.optionLabels || opts;
        inputHtml = `<select id="${inputId}" class="form-control">${opts.map((o, i) => {
          const optVal = String(o).includes('|') ? o.split('|')[0] : o;
          const selected = String(f.value) == optVal || String(f.value) == o;
          return `<option value="${din1090Esc(o)}" ${selected ? 'selected' : ''}>${din1090Esc(labels[i] || o)}</option>`;
        }).join('')}</select>`;
      } else if (f.type === 'textarea') {
        inputHtml = `<textarea id="${inputId}" class="form-control" rows="3">${din1090Esc(f.value || '')}</textarea>`;
      } else if (f.type === 'date') {
        inputHtml = `<input id="${inputId}" class="form-control" type="date" value="${din1090Esc(f.value || '')}">`;
      } else if (f.type === 'number') {
        inputHtml = `<input id="${inputId}" class="form-control" type="number" step="any" value="${din1090Esc(String(f.value || ''))}">`;
      } else {
        inputHtml = `<input id="${inputId}" class="form-control" type="text" value="${din1090Esc(f.value || '')}">`;
      }

      fieldsHtml += `<div class="form-group"><label class="form-label">${din1090Esc(f.label)}</label>${inputHtml}</div>`;
    }

    overlay.innerHTML = `<div class="modal-box" style="max-width:600px;max-height:90vh;overflow-y:auto">
      <div class="modal-header">
        <h3>${din1090Esc(title)}</h3>
        <button class="modal-close" onclick="document.getElementById('din1090FormModal').remove()">×</button>
      </div>
      <div class="modal-body">${fieldsHtml}</div>
      <div class="modal-footer">
        ${onDelete ? `<button class="btn btn-danger btn-sm" id="din1090FormDeleteBtn" style="margin-right:auto">Löschen</button>` : ''}
        <button class="btn btn-secondary" onclick="document.getElementById('din1090FormModal').remove()">Abbrechen</button>
        <button class="btn btn-primary" id="din1090FormSaveBtn">Speichern</button>
      </div>
    </div>`;

    document.body.appendChild(overlay);

    // Event-Listener statt inline onclick (sicherer)
    document.getElementById('din1090FormSaveBtn').addEventListener('click', async () => {
      const vals = {};
      for (const f of fields) {
        const el = document.getElementById('dm_' + f.id);
        if (el) vals[f.id] = el.value;
      }
      const ok = await onSave(vals);
      if (ok) overlay.remove();
    });

    if (onDelete) {
      document.getElementById('din1090FormDeleteBtn').addEventListener('click', () => onDelete());
    }

    // onChange-Handler für dynamische Felder
    for (const f of fields) {
      if (f.onChange) {
        const el = document.getElementById('dm_' + f.id);
        if (el) el.addEventListener('change', () => f.onChange(el.value));
      }
    }
  }

  // ═════════════════════════════════════════════════════════════
  //  AUS ANDEREM PROJEKT KOPIEREN
  // ═════════════════════════════════════════════════════════════
  window.din1090CopyFrom = async function (section) {
    if (!din1090CurrentProject) return;
    const labels = { wps: 'WPS', weld_log: 'Schweißnähte', inspections: 'Prüfungen', surface: 'Oberflächenbehandlungen' };
    const label = labels[section] || section;

    // Projekte laden (ohne aktuelles)
    const r = await din1090Api('list_projects');
    if (!r.ok || !r.data || r.data.length < 2) {
      din1090Notify('Keine anderen Projekte vorhanden.', 'info');
      return;
    }
    const others = r.data.filter(p => p.id != din1090CurrentProject.id);
    if (others.length === 0) {
      din1090Notify('Keine anderen Projekte vorhanden.', 'info');
      return;
    }

    // Modal aufbauen
    document.getElementById('din1090CopyModal')?.remove();
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'din1090CopyModal';

    overlay.innerHTML = `<div class="modal-box" style="max-width:500px;max-height:80vh;overflow-y:auto">
      <div class="modal-header">
        <h3>📋 ${din1090Esc(label)} aus Projekt kopieren</h3>
        <button class="modal-close" onclick="document.getElementById('din1090CopyModal').remove()">×</button>
      </div>
      <div class="modal-body">
        <p style="font-size:12px;color:#666;margin-bottom:12px;">Wählen Sie das Quellprojekt, aus dem die <strong>${din1090Esc(label)}</strong> in das aktuelle Projekt kopiert werden sollen. Bestehende Einträge bleiben erhalten.</p>
        <input type="text" id="din1090CopySearch" class="form-control" placeholder="🔍 Projekt suchen…" oninput="din1090FilterCopyList()" style="margin-bottom:10px;">
        <div id="din1090CopyList" style="max-height:300px;overflow-y:auto;border:1px solid #e0e0e0;border-radius:6px;">
          ${others.map(p => {
            const pnr = p.projektNr ? `<span style="color:#1565C0;font-weight:600;">${din1090Esc(p.projektNr)}</span> – ` : '';
            const bst = p.baustelle_name ? ` <span style="color:#888;font-size:11px;">| ${din1090Esc(p.baustelle_name)}</span>` : '';
            return `<div class="din1090-copy-item" data-id="${p.id}" data-search="${din1090Esc((p.projektNr || '') + ' ' + p.bezeichnung + ' ' + (p.baustelle_name || '')).toLowerCase()}"
              onclick="din1090DoCopy('${section}', ${p.id})"
              style="padding:10px 14px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:13px;"
              onmouseenter="this.style.background='#E3F2FD'" onmouseleave="this.style.background=''">
              ${pnr}<strong>${din1090Esc(p.bezeichnung)}</strong> <span style="color:#888;font-size:11px;">(${din1090Esc(p.ausfuehrungsklasse)})</span>${bst}
            </div>`;
          }).join('')}
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" onclick="document.getElementById('din1090CopyModal').remove()">Abbrechen</button>
      </div>
    </div>`;

    document.body.appendChild(overlay);
    document.getElementById('din1090CopySearch')?.focus();
  };

  window.din1090FilterCopyList = function () {
    const q = (document.getElementById('din1090CopySearch')?.value || '').toLowerCase().trim();
    document.querySelectorAll('#din1090CopyList .din1090-copy-item').forEach(el => {
      el.style.display = !q || el.dataset.search.includes(q) ? '' : 'none';
    });
  };

  window.din1090DoCopy = async function (section, sourceId) {
    const labels = { wps: 'WPS', weld_log: 'Schweißnähte', inspections: 'Prüfungen', surface: 'Oberflächenbehandlungen' };
    if (!confirm(`${labels[section] || section} aus dem gewählten Projekt kopieren?`)) return;

    document.getElementById('din1090CopyModal')?.remove();
    din1090Notify('Wird kopiert…');

    const r = await din1090Api('copy_from_project', {
      targetProjectId: din1090CurrentProject.id,
      sourceProjectId: sourceId,
      section: section
    });
    if (r.ok) {
      din1090Notify(`${r.count} ${labels[section] || 'Einträge'} kopiert.`, 'success');
      // Tab neu laden
      const tabMap = { wps: 'schweissen', weld_log: 'schweissen', inspections: 'pruefungen', surface: 'oberflaeche' };
      din1090SwitchTab(tabMap[section] || 'uebersicht');
    } else {
      din1090Notify(r.error || 'Fehler beim Kopieren.', 'error');
    }
  };

  // ═════════════════════════════════════════════════════════════
  //  PROTOKOLL PDF EXPORT
  // ═════════════════════════════════════════════════════════════
  window.din1090ExportPDF = async function () {
    if (!din1090CurrentProject) return;
    const pid = din1090CurrentProject.id;
    din1090Notify('Protokoll wird erstellt…');

    const r = await din1090Api('export_project', { projectId: pid });
    if (!r.ok) { din1090Notify(r.error || 'Export fehlgeschlagen.', 'error'); return; }

    const p = r.project;
    const esc = din1090Esc;
    const fmtD = din1090Fmt;

    // Logo laden (gleiche Funktion wie bei Rechnung/Angebot)
    const s = typeof appSettings !== 'undefined' ? appSettings : {};
    const logoUrl = s.firma_logo_url && typeof imgToBase64 === 'function' ? await imgToBase64(s.firma_logo_url) : '';
    const firmaBlock = [s.firma_name, s.firma_strasse, [s.firma_plz, s.firma_ort].filter(Boolean).join(' ')].filter(Boolean);
    const firmaLine = firmaBlock.join(', ');

    // ── Sektionshelfer ──
    function section(title) { return `<h2 style="font-size:14px;margin-top:22px;color:#1565C0;border-bottom:2px solid #1565C0;padding-bottom:4px;page-break-after:avoid;">${esc(title)}</h2>`; }
    function tbl(headers, rows) {
      if (rows.length === 0) return '<p style="color:#999;font-size:11px;">Keine Einträge.</p>';
      return `<table style="width:100%;border-collapse:collapse;font-size:10px;margin:6px 0;">
        <thead><tr style="background:#E3F2FD;">${headers.map(h => `<th style="text-align:left;padding:4px 6px;border-bottom:1px solid #ccc;white-space:nowrap;">${esc(h)}</th>`).join('')}</tr></thead>
        <tbody>${rows.map(row => `<tr style="page-break-inside:avoid;">${row.map(c => `<td style="padding:3px 6px;border-bottom:1px solid #eee;">${c}</td>`).join('')}</tr>`).join('')}</tbody>
      </table>`;
    }

    let html = `<div style="font-family:Arial,sans-serif;font-size:12px;max-width:900px;margin:0 auto;padding:20px;">
      <style>tr{page-break-inside:avoid}table{page-break-inside:auto}h2{page-break-after:avoid}</style>
      <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid #1565C0;padding-bottom:10px;margin-bottom:16px;">
        <div>
          <h1 style="font-size:18px;color:#1565C0;margin:0;">DIN EN 1090 – Projektprotokoll</h1>
          <p style="margin:2px 0 0;font-size:11px;color:#666;">${esc(p.bezeichnung)}</p>
          ${firmaLine ? `<p style="margin:2px 0 0;font-size:9px;color:#888;">${esc(firmaLine)}</p>` : ''}
        </div>
        <div style="text-align:right;">
          ${logoUrl ? `<img src="${logoUrl}" alt="Logo" style="max-width:60mm;max-height:25mm;object-fit:contain;margin-bottom:4px;display:block;margin-left:auto;">` : ''}
          <div style="font-size:10px;color:#888;">Exportiert: ${new Date().toLocaleDateString('de-DE')}</div>
          <div style="font-size:10px;color:#888;">${esc(p.ausfuehrungsklasse)}</div>
        </div>
      </div>

      ${section('1. Projektdaten')}
      <table style="width:100%;font-size:11px;border-collapse:collapse;">
        <tr><td style="width:180px;padding:4px 8px;font-weight:600;">Bezeichnung:</td><td style="padding:4px 8px;">${esc(p.bezeichnung)}</td></tr>
        <tr><td style="padding:4px 8px;font-weight:600;">Baustelle:</td><td style="padding:4px 8px;">${esc(p.baustelle_name || '–')}</td></tr>
        <tr><td style="padding:4px 8px;font-weight:600;">Ausführungsklasse:</td><td style="padding:4px 8px;">${esc(p.ausfuehrungsklasse)}</td></tr>
        <tr><td style="padding:4px 8px;font-weight:600;">Werkstoff:</td><td style="padding:4px 8px;">${esc(p.werkstoff)}</td></tr>
        <tr><td style="padding:4px 8px;font-weight:600;">Normen:</td><td style="padding:4px 8px;">${esc(p.normen)}</td></tr>
        <tr><td style="padding:4px 8px;font-weight:600;">Verantwortlicher:</td><td style="padding:4px 8px;">${esc(p.verantwortlicher)}</td></tr>
        <tr><td style="padding:4px 8px;font-weight:600;">Schweißaufsicht:</td><td style="padding:4px 8px;">${esc(p.schweissaufsicht)}</td></tr>
        <tr><td style="padding:4px 8px;font-weight:600;">Prüfstelle:</td><td style="padding:4px 8px;">${esc(p.pruefstelle || '–')}</td></tr>
        <tr><td style="padding:4px 8px;font-weight:600;">Status:</td><td style="padding:4px 8px;">${esc(p.status)}</td></tr>
        ${p.notizen ? `<tr><td style="padding:4px 8px;font-weight:600;">Notizen:</td><td style="padding:4px 8px;">${esc(p.notizen)}</td></tr>` : ''}
      </table>

      ${section('2. Materialverfolgung & Werkszeugnisse')}
      ${tbl(['Bezeichnung', 'Werkstoff', 'Abmessung', 'Charge-Nr.', 'Schmelz-Nr.', 'Zeugnis', 'Lieferant', 'Prüfstatus'],
        (r.materials || []).map(m => [esc(m.bezeichnung), esc(m.werkstoff), esc(m.abmessung), esc(m.charge_nr), esc(m.schmelz_nr), esc(m.zeugnis_typ) + ' ' + esc(m.zeugnis_nr), esc(m.lieferant), esc(m.pruef_status)])
      )}

      ${section('3. Schweißerqualifikationen')}
      ${tbl(['Name', 'Stempel-Nr.', 'Qualifikation', 'Norm', 'Verfahren', 'Position', 'Gültig bis'],
        (r.welders || []).map(w => [esc(w.name), esc(w.stempel_nr), esc(w.qualifikation_nr), esc(w.norm), esc(w.verfahren), esc(w.position), fmtD(w.gueltig_bis)])
      )}

      ${section('4. Schweißanweisungen (WPS)')}
      ${tbl(['WPS-Nr.', 'Verfahren', 'Grundwerkstoff', 'Zusatzwerkstoff', 'Schutzgas', 'Nahtart', 'Dicke', 'WPQR', 'Status'],
        (r.wps || []).map(w => [esc(w.wps_nr), esc(w.verfahren), esc(w.grundwerkstoff), esc(w.zusatzwerkstoff), esc(w.schutzgas), esc(w.nahtart), (w.blechdicke_von || '') + '–' + (w.blechdicke_bis || '') + ' mm', esc(w.wpqr_nr), esc(w.status)])
      )}

      ${section('5. Schweißnahtprotokoll')}
      ${tbl(['Naht-Nr.', 'Bauteil', 'Datum', 'Schweißer', 'WPS', 'Position', 'Nahtart', 'a-Maß', 'Länge', 'Prüf-Status'],
        (r.weldLog || []).map(w => [esc(w.naht_nr), esc(w.bauteil), fmtD(w.datum), esc(w.schweisser_name || ''), esc(w.wps_nr || ''), esc(w.position), esc(w.nahtart), w.a_mass || '', w.laenge || '', esc(w.pruef_status)])
      )}

      ${section('6. Prüfungen (ZfP / DT)')}
      ${tbl(['Prüfart', 'Bauteil', 'Naht-Nr.', 'Datum', 'Prüfer', 'Norm', 'Ergebnis', 'Report-Nr.'],
        (r.inspections || []).map(i => [esc(i.pruef_art), esc(i.bauteil), esc(i.naht_nr), fmtD(i.pruef_datum), esc(i.pruefer), esc(i.pruef_norm), esc(i.ergebnis), esc(i.report_nr)])
      )}

      ${section('7. Abweichungen / NCR')}
      ${tbl(['NCR-Nr.', 'Datum', 'Bauteil', 'Beschreibung', 'Ursache', 'Maßnahme', 'Status', 'Frist'],
        (r.ncr || []).map(n => [esc(n.ncr_nr), fmtD(n.datum), esc(n.bauteil), esc(n.beschreibung), esc(n.ursache), esc(n.massnahme), esc(n.status), fmtD(n.frist)])
      )}

      ${section('8. Oberflächenbehandlung')}
      ${tbl(['Bauteil', 'System', 'Vorbehandlung', 'Grundierung', 'Schicht 1', 'Schicht 2', 'Soll-Dicke', 'Ist-Dicke', 'Ergebnis'],
        (r.surface || []).map(s => [esc(s.bauteil), esc(s.system), esc(s.vorbehandlung), esc(s.grundierung), esc(s.schicht_1), esc(s.schicht_2), s.soll_dicke || '', s.ist_dicke || '', esc(s.ergebnis)])
      )}

      ${section('9. Checkliste')}`;

    // Checkliste nach Kategorie gruppiert
    const clGroups = {};
    (r.checklists || []).forEach(c => {
      if (!clGroups[c.kategorie]) clGroups[c.kategorie] = [];
      clGroups[c.kategorie].push(c);
    });
    for (const [kat, items] of Object.entries(clGroups)) {
      const done = items.filter(i => i.erledigt).length;
      html += `<h3 style="font-size:11px;margin:10px 0 4px;color:#333;">${esc(kat)} (${done}/${items.length})</h3>`;
      html += `<table style="width:100%;border-collapse:collapse;font-size:10px;">
        <tbody>${items.map(c => `<tr style="page-break-inside:avoid;">
          <td style="width:20px;padding:2px 4px;">${c.erledigt ? '✅' : '☐'}</td>
          <td style="padding:2px 6px;${c.erledigt ? 'color:#999;' : ''}">${esc(c.punkt)}</td>
          <td style="padding:2px 6px;font-size:9px;color:#888;">${c.erledigt_am ? fmtD(c.erledigt_am.split(' ')[0]) + ' – ' + esc(c.erledigt_von || '') : ''}</td>
          <td style="padding:2px 6px;font-size:9px;color:#666;">${esc(c.bemerkung || '')}</td>
        </tr>`).join('')}</tbody>
      </table>`;
    }

    html += `
      <div style="margin-top:30px;border-top:2px solid #1565C0;padding-top:12px;font-size:10px;color:#888;">
        <p>Dieses Dokument wurde automatisch aus der Baukalkulation-Software exportiert.<br>
        Aufbewahrungspflicht gemäß DIN EN 1090: mindestens 10 Jahre.</p>
      </div>
    </div>`;

    // PDF erzeugen via html2pdf
    function doPDF() {
      const container = document.createElement('div');
      container.innerHTML = html;
      document.body.appendChild(container);
      html2pdf().set({
        margin: [12, 10, 12, 10],
        filename: 'DIN_EN_1090_' + p.bezeichnung.replace(/[^a-zA-Z0-9äöüÄÖÜß_-]/g, '_') + '_' + new Date().toISOString().slice(0, 10) + '.pdf',
        image: { type: 'jpeg', quality: 0.95 },
        html2canvas: { scale: 2 },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' },
        pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
      }).from(container).save().then(() => {
        document.body.removeChild(container);
        din1090Notify('Protokoll PDF erstellt.', 'success');
      });
    }

    if (typeof html2pdf === 'undefined') {
      const s = document.createElement('script');
      s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
      s.onload = doPDF;
      document.head.appendChild(s);
    } else {
      doPDF();
    }
  };

  // ── DIN 1090 – Protokoll per E-Mail senden ─────────────────
  window.din1090EmailPDF = async function () {
    if (!din1090CurrentProject) return;
    const pid = din1090CurrentProject.id;

    // HTML für PDF erzeugen (gleicher Code wie Export, aber als Base64)
    din1090Notify('PDF wird für E-Mail vorbereitet…');
    // Volle Export-Logik wiederverwenden: zuerst API-Daten laden
    const r = await din1090Api('export_project', { projectId: pid });
    if (!r.ok) { din1090Notify(r.error || 'Export fehlgeschlagen.', 'error'); return; }

    const defaultTo = (typeof appSettings !== 'undefined' && appSettings.firma_email) ? appSettings.firma_email : '';
    const subject = 'DIN EN 1090 – Projektprotokoll: ' + (r.project?.bezeichnung || '');

    if (typeof openEmailDialog !== 'function') { din1090Notify('E-Mail-Funktion nicht verfügbar.'); return; }

    openEmailDialog(defaultTo, subject, async (to, subj) => {
      // PDF als base64 über html2pdf erzeugen
      async function buildBase64() {
        const p = r.project;
        const esc = din1090Esc;
        // Minimal-HTML für die E-Mail-PDF (gleiche Inhalte wie ExportPDF, aber kompakt)
        const container = document.createElement('div');
        container.style.display = 'none';
        container.innerHTML = document.querySelector('#din1090View')?.innerHTML || `<p>${p.bezeichnung}</p>`;
        document.body.appendChild(container);
        return new Promise((resolve, reject) => {
          html2pdf().set({
            margin: [12, 10, 12, 10],
            image: { type: 'jpeg', quality: 0.9 },
            html2canvas: { scale: 1.5 },
            jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' },
          }).from(container).outputPdf('datauristring').then(uri => {
            document.body.removeChild(container);
            resolve(uri.split(',')[1]);  // base64-Teil
          }).catch(reject);
        });
      }

      async function doSend() {
        const b64 = await buildBase64();
        try {
          const resp = await fetch('api.php?action=send_din1090', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pdf_base64: b64, to, subject: subj }),
          });
          const j = await resp.json();
          if (j.ok) din1090Notify('E-Mail gesendet.', 'success');
          else din1090Notify('Fehler: ' + (j.error || 'Unbekannter Fehler'), 'error');
        } catch (e) { din1090Notify('Netzwerkfehler: ' + e.message, 'error'); }
      }

      if (typeof html2pdf === 'undefined') {
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
        s.onload = doSend;
        document.head.appendChild(s);
      } else {
        await doSend();
      }
    });
  };


  window.hideDin1090 = function () {
    document.getElementById('din1090View')?.classList.add('hidden');
    document.getElementById('btnDin1090')?.classList.remove('active');
  };

  // ─── Show-Funktion (wird von script.js Desktop UND showDin1090Mobile() aufgerufen) ───
  window.showDin1090 = function () {
    const view = document.getElementById('din1090View');
    if (!view) return;
    // Sidebar-Buttons: Desktop-Kontext (optional)
    document.querySelectorAll('.sidebar-overview-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('btnDin1090')?.classList.add('active');
    if (typeof window.selectedId !== 'undefined') window.selectedId = null;
    if (typeof window.renderSidebar === 'function') window.renderSidebar();
    // Alle bekannten Detailansichten ausblenden (Desktop + Mobile)
    ['emptyState','baustelleDetail','materialKatalogView','stundenKatalogView',
     'offenesMaterialView','whatsappView','schnellnotizenView','kundenstammView',
     'dienstleisterView','allgemeinSettingsView','rechnungenView','auswertungView',
     'uebersichtView','lagerView','aufmassView','vde0100View','dashboardView'
    ].forEach(id => document.getElementById(id)?.classList.add('hidden'));
    view.classList.remove('hidden');
    window.renderDin1090();
  };

})();
