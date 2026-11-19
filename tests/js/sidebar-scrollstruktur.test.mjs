import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const indexUrl = new URL('../../public/index.html', import.meta.url);
const styleUrl = new URL('../../public/style.css', import.meta.url);

const indexHtml = await readFile(indexUrl, 'utf8');
const styleCss = await readFile(styleUrl, 'utf8');

test('Sidebar in index.html trennt Menü und Projekte in eigene Bereiche', () => {
  assert.match(
    indexHtml,
    /<div class="sidebar-collapse-section sidebar-section-menu">[\s\S]*?<div class="sidebar-collapse-body" id="secMenu">/,
    'Menübereich mit eigenem Container secMenu fehlt',
  );

  assert.match(
    indexHtml,
    /<div class="sidebar-collapse-section sidebar-section-projekte">[\s\S]*?<div class="sidebar-collapse-body" id="secProjekte">[\s\S]*?<ul id="baustellenList" class="baustellen-list"/,
    'Projektbereich mit secProjekte und baustellenList fehlt',
  );
});

test('Sidebar-Wrapper scrollt nicht mehr als Ganzes', () => {
  assert.match(
    styleCss,
    /\.sidebar-scrollable\s*\{[^}]*overflow:\s*hidden;[^}]*\}/s,
    '.sidebar-scrollable muss overflow:hidden setzen',
  );
});

test('Menü ist höhenbegrenzt und nutzt eigenen Scrollbereich', () => {
  assert.match(
    styleCss,
    /\.sidebar-section-menu\s*\{[^}]*max-height:\s*45%;[^}]*\}/s,
    '.sidebar-section-menu muss max-height:45% setzen',
  );

  assert.match(
    styleCss,
    /\.sidebar-collapse-body\s*\{[^}]*overflow-y:\s*auto;[^}]*\}/s,
    '.sidebar-collapse-body muss overflow-y:auto setzen',
  );
});

test('Projektbereich behält Mindesthöhe und delegiert Scrollen an die Liste', () => {
  assert.match(
    styleCss,
    /\.sidebar-section-projekte\s*\{[^}]*min-height:\s*160px;[^}]*\}/s,
    '.sidebar-section-projekte muss min-height:160px setzen',
  );

  assert.match(
    styleCss,
    /\.sidebar-section-projekte\s+\.sidebar-collapse-body\s*\{[^}]*overflow:\s*hidden;[^}]*\}/s,
    'Projekt-Collapse-Body muss overflow:hidden setzen',
  );

  assert.match(
    styleCss,
    /\.baustellen-list\s*\{[^}]*overflow-y:\s*auto;[^}]*flex:\s*1;[^}]*\}/s,
    '.baustellen-list muss als eigener Scrollcontainer fungieren',
  );
});