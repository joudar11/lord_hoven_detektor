/* Skript vizualizace (časové osy) aplikace Detektor Lorda Hoven (MIT). Data dostává z window.LH_BOOT (inline) a z adres ?ajax=... */
(() => {
'use strict';
const B = window.LH_BOOT;
const $ = (s, r = document) => r.querySelector(s);
const el = (tag, cls, txt) => { const e = document.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; };
const pad = n => String(n).padStart(2, '0');
const DOW = ['Ne', 'Po', 'Út', 'St', 'Čt', 'Pá', 'So'];
const D = t => new Date(t * 1000);
const fmtT = t => pad(D(t).getUTCHours()) + ':' + pad(D(t).getUTCMinutes());
const fmtD = t => D(t).getUTCDate() + '. ' + (D(t).getUTCMonth() + 1) + '.';
const fmtDT = t => pad(D(t).getUTCDate()) + '-' + pad(D(t).getUTCMonth() + 1) + '-' + D(t).getUTCFullYear() + ' ' + fmtT(t) + ':' + pad(D(t).getUTCSeconds());
const fmtDay = t => DOW[D(t).getUTCDay()] + ' ' + D(t).getUTCDate() + '. ' + (D(t).getUTCMonth() + 1) + '. ' + D(t).getUTCFullYear();
const dayStart = t => Math.floor(t / 86400) * 86400;
const iso = t => D(t).toISOString().slice(0, 10);
const fmtDur = s => { const m = Math.round(s / 60); return m >= 60 ? Math.floor(m / 60) + ' h ' + (m % 60) + ' min' : m + ' min'; };
const store = { get: k => { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} } };

const CUB = { leva: '#3b82f6', prava: '#f59e0b', invalide: '#14b8a6' };
const TSC = ['#7c3aed', '#6d28d9', '#ea580c', '#0d9488', '#64748b'];
const OUTC = '#475569';   // směr OUT na turniketech (IN má barvu turniketu); k tomu navíc tvar: IN plný bod, OUT kroužek
const dirOn = f => (f === 0 ? S.dir.in : S.dir.out);
// Průchod se zobrazí, když projde filtrem směru a (je-li zapnuto „První průchod dne“) je prvním průchodem osoby za den mezi vybranými směry
const nrm = x => String(x).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
const tsColor = key => { const k = B.turnstiles.findIndex(t => t.key === key); return TSC[(k < 0 ? TSC.length - 1 : k) % TSC.length]; };
const anyTs = () => Object.keys(S.ts).some(k => S.ts[k]);
const personOk = nm => !S.pf.size || S.pf.has(nrm(nm));
// Výběr incidentů jako v hlavní aplikaci: ve výchozím stavu platí všechny počítané (nevyřazené) incidenty, štítky vyberou sjednocení nebo průnik (i vyřazených),
// zaškrtávátko u incidentu výběr upraví ručně. Vybrané incidenty se zobrazují na ose i v seznamu událostí.
const incVisible = i => S.incSel.has(i.id);
// „První průchod dne“: první průchod osoby za den mezi průchody, které projdou filtrem směru a zapnutých turniketů (data jsou seřazená podle času)
function firstFlags() {
  const d = S.dat, key = (S.dir.in ? 1 : 0) + (S.dir.out ? 2 : 0) + ':' + d.tsk.map(k => (S.ts[k] === false ? 0 : 1)).join('');
  let f = d.fc.get(key); if (f) { return f; }
  f = new Uint8Array(d.pt.length); const seen = new Set();
  for (let i = 0; i < d.pt.length; i++) {
    if (!dirOn(d.pd[i]) || S.ts[d.tsk[d.pts[i]]] === false) { continue; }
    const k = d.pn[i] * 100000 + Math.floor(d.pt[i] / 86400);
    if (!seen.has(k)) { seen.add(k); f[i] = 1; }
  }
  d.fc.set(key, f); return f;
}
const passOn = i => {
  const d = S.dat, f = d.pd[i];
  if (!dirOn(f)) { return false; }
  if (S.ts[d.tsk[d.pts[i]]] === false) { return false; }   // vypnutý turniket
  if (S.pf.size && !S.pf.has(d.pk[d.pn[i]])) { return false; }   // filtr osob
  if (!S.layers.first) { return true; }
  return firstFlags()[i] === 1;
};
const DIRC = { vychazel: '#dc2626', vchazel: '#f59e0b', poblizu: '#94a3b8' };
const WARN = {
  nostart: 'Chybí předchozí hlášení „čisto“, začátek okna není znám.',
  virt: 'Hlášení „čisto“ na začátku okna bylo zadáno zpětně (virtuální), skutečný začátek okna není znám.',
  nodata: 'Za některý den okna nejsou naimportované průchody.',
  cov_start: 'Docházka za den začátku okna začíná až po začátku okna.',
  cov_end: 'Docházka za den nálezu končí dřív než tolerance po nálezu, takže mohou chybět pozdější průchody.'
};
const MAX_SPAN = 28 * 86400, MIN_SPAN = 600;
const BINS = [60, 120, 300, 600, 900, 1800, 3600, 7200, 14400, 28800, 86400];

const S = { meta: null, dat: null, loaded: null, v0: 0, v1: 0, mode: 'osa', sel: null, seq: 0,
  layers: { inc: true, kab: true, ms: true, poz: true, pozn: true, dov: true, gaps: false, qual: true, bl: false, first: false }, dir: { in: true, out: true },
  ts: {}, pf: new Map(), people: [], lab: { sel: new Set(), mode: 'union' }, incSel: new Set(), incOpen: new Set(), listN: 300 };
let W = 0, H = 0, dpr = 1, HIT = [], hatchPat = null, hatchAmber = null, C = {};
const cv = $('#cv'), ctx = cv.getContext('2d'), stage = $('#stage');
const mobile = () => stage.clientWidth < 700;
const LW = () => (mobile() ? 50 : 118);
const PW = () => Math.max(50, W - LW() - 10);
const X = t => LW() + (t - S.v0) / (S.v1 - S.v0) * PW();
const TT = x => S.v0 + (x - LW()) / PW() * (S.v1 - S.v0);

function fitText(t, maxW) { if (ctx.measureText(t).width <= maxW) return t; let s = t; while (s.length > 1 && ctx.measureText(s + '…').width > maxW) s = s.slice(0, -1); return s + '…'; }
function colors() {
  const cs = getComputedStyle(document.documentElement), g = n => cs.getPropertyValue(n).trim();
  C = { bg: g('--card'), row: g('--row'), row2: g('--row2'), grid: g('--grid'), text: g('--text'), muted: g('--muted'), ok: g('--ok'), bad: g('--bad'), warn: g('--warn'), accent: g('--accent'), hatch: g('--hatch') };
  const mk = col => { const c = document.createElement('canvas'); c.width = c.height = 8; const x = c.getContext('2d'); x.strokeStyle = col; x.lineWidth = 1.4; x.beginPath(); x.moveTo(-1, 9); x.lineTo(9, -1); x.moveTo(-1, 1); x.lineTo(1, -1); x.moveTo(7, 9); x.lineTo(9, 7); x.stroke(); return ctx.createPattern(c, 'repeat'); };
  hatchPat = mk(C.hatch); hatchAmber = mk('rgba(217,119,6,.75)');
}

// ---------- data ----------
function packIncidents() {
  const tol = S.meta.tol * 60, lanes = [];
  const list = S.meta.inc.filter(incVisible).sort((a, b) => (a.s != null ? a.s : a.e - 1200) - (b.s != null ? b.s : b.e - 1200));
  for (const i of list) {
    const a = i.s != null ? i.s : i.e - 1200, e = i.e + tol + 300;
    let k = lanes.findIndex(end => end <= a);
    if (k < 0) { k = lanes.length; lanes.push(0); }
    lanes[k] = e; i.lane = k;
  }
  S.nLanes = Math.max(1, lanes.length);
}
async function api(q) {
  const r = await fetch(location.pathname + '?' + q + (S.layers.bl ? '&bl=1' : ''), { credentials: 'same-origin' });
  const j = await r.json();
  if (!r.ok) throw new Error(j.error || 'Chyba');
  return j;
}
function prepare(d) {
  const n = d.pass.length;
  d.pt = new Float64Array(n); d.pts = new Uint8Array(n); d.pn = new Uint16Array(n); d.pd = new Uint8Array(n);
  d.pass.forEach((r, i) => { d.pt[i] = r[0]; d.pts[i] = r[1]; d.pn[i] = r[2]; d.pd[i] = r[3]; });
  d.pass = null;
  d.pk = d.names.map(nrm);   // normalizovaná jména (filtr osob)
  d.fc = new Map();   // mezipaměť příznaků „první průchod osoby za den“ podle aktuálního filtru směru a turniketů
  d.repBy = {}; d.rep.forEach(r => (d.repBy[r.c] = d.repBy[r.c] || []).push(r));
  return d;
}
let fetchTimer = null;
function ensureData(now) {
  clearTimeout(fetchTimer);
  fetchTimer = setTimeout(async () => {
    const need0 = dayStart(S.v0) - 86400, need1 = dayStart(S.v1) + 2 * 86400 - 1;
    if (S.loaded && need0 >= S.loaded[0] && need1 <= S.loaded[1]) return;
    let a = need0, b = need1;
    if (b - a > 31 * 86400 - 1) { const mid = (S.v0 + S.v1) / 2; a = dayStart(mid) - 15 * 86400; b = a + 31 * 86400 - 1; }
    const seq = ++S.seq; $('#load').style.display = 'block';
    try {
      const d = prepare(await api('ajax=data&od=' + iso(a) + '&do=' + iso(b)));
      if (seq !== S.seq) return;
      S.dat = d; S.loaded = [d.from, d.to]; msg('');
      if (d.trunc) $('#foot').textContent = 'Průchodů je v rozsahu příliš mnoho, zobrazeno je jen prvních 30 000. Zkrať rozsah.';
      draw(); renderList();
    } catch (e) { msg('Data se nepodařilo načíst: ' + e.message); }
    finally { if (seq === S.seq) $('#load').style.display = 'none'; }
  }, now ? 0 : 220);
}
function msg(t) { $('#msg').textContent = t; }
// Po přepnutí „Vyřazené osoby“: znovu se stáhnou meta (počty v oknech incidentů) i data osy
async function reloadAll() {
  S.dat = null; S.loaded = null; S.sel = null; sheet.style.display = 'none'; $('#load').style.display = 'block';
  try { S.meta = await api('ajax=meta'); const keep = S.incSel; S.incSel = new Set(S.meta.inc.filter(i => keep.has(i.id)).map(i => i.id)); packIncidents(); buildLabels(); renderIncCard(); loadPeople(); } catch (e) { msg('Data se nepodařilo načíst: ' + e.message); $('#load').style.display = 'none'; return; }
  $('#load').style.display = 'none'; draw(); ensureData(true); if (S.mode === 'seznam') { renderList(); }
}

// ---------- vykreslení ----------
function rowsLayout() {
  const m = mobile(), rh = m ? 36 : 42, rows = [];
  if (S.layers.inc) rows.push({ k: 'inc', label: 'Incidenty', short: 'Inc.', h: S.nLanes * 24 + 12 });
  if (S.layers.ms && S.meta.ms && S.meta.ms.some(msVisible)) rows.push({ k: 'ms', label: 'Milníky', short: 'Mil.', h: msLayout().n * 24 + 6 });
  if (!B.ro && S.layers.pozn && S.dat && (S.dat.pozn || []).some(n => n.t >= S.v0 && n.t <= S.v1 && poznOk(n))) rows.push({ k: 'pozn', label: 'Poznatky', short: 'Pozn.', h: 28 });
  if (S.layers.kab) B.cubicles.forEach(c => rows.push({ k: 'kab', key: c.key, label: c.name, short: c.short, h: rh }));
  (S.dat ? S.dat.tsk : B.turnstiles.map(t => t.key)).forEach((key, i) => { if (S.ts[key] === false) { return; } const t = B.turnstiles.find(x => x.key === key); rows.push({ k: 'tn', i, key, label: t ? t.name : key, short: t ? t.short : key.slice(0, 5), h: rh }); });
  if (S.layers.poz) rows.push({ k: 'poz', label: 'Pozorování', short: 'Poz.', h: rh });
  if (S.layers.dov && S.dat && S.dat.vac.length) rows.push({ k: 'dov', label: 'Dovolené IT', short: 'Dov.', h: Math.max(1, vacLanes()) * 22 + 10 });
  return rows;
}
// ---------- milníky ----------
const MSC = '#d97706';
const msVisible = m => (m.t2 != null ? m.t2 : m.t) >= S.v0 && m.t <= S.v1;
const dmy = t => fmtDT(t).slice(0, 10);
// krátký text (v řádku osy) a plný text (seznam, detail)
function msShort(m) {
  const w = m.who || '';
  if (m.k === 'left') return w + ' skončil(a)'; if (m.k === 'joined') return w + ' nastoupil(a)'; if (m.k === 'away') return w + ' pryč';
  return m.title || 'Milník';
}
function msText(m) {
  const w = m.who || '', base = m.k === 'left' ? w + ' skončil(a) v práci' : m.k === 'joined' ? w + ' nastoupil(a)' : m.k === 'away' ? w + ' je pryč' : (m.title || 'Milník');
  return base + (['left', 'joined', 'away'].includes(m.k) && m.title ? ': ' + m.title : '');
}
function msWhen(m) {
  if (m.k === 'left') return 'poslední den v práci ' + dmy(m.f);
  if (m.k === 'joined') return 'první den v práci ' + dmy(m.f);
  if (m.k === 'away') return dmy(m.f) + ' až ' + dmy(m.t2 != null ? m.t2 - 86400 : m.f);
  return dmy(m.f);
}
// přerušované svislé čáry přes všechny řádky na hranici milníku a pásmo u „pryč“
function drawMsLines(AX) {
  if (!S.meta.ms) { return; }
  S.meta.ms.filter(msVisible).forEach(m => {
    const x = X(m.t);
    if (m.t2 != null) { ctx.fillStyle = MSC; ctx.globalAlpha = .07; ctx.fillRect(x, AX - 6, Math.max(1, X(m.t2) - x), H - AX); ctx.globalAlpha = 1; }
    ctx.strokeStyle = MSC; ctx.globalAlpha = .75; ctx.lineWidth = 1.2; ctx.setLineDash([5, 4]);
    ctx.beginPath(); ctx.moveTo(x + .5, AX - 6); ctx.lineTo(x + .5, H - 6); ctx.stroke(); ctx.setLineDash([]); ctx.globalAlpha = 1;
  });
}
// Milníky v řádcích: milníky, které se nepřekrývají ani popiskem, sdílejí řádek; překrývající se (i začínající ve stejný den) mají každý svůj řádek
function msLayout() {
  const list = S.meta.ms.filter(msVisible).sort((a, b) => a.t - b.t || a.id - b.id), ends = [], items = [];
  ctx.font = (mobile() ? 10 : 11) + 'px system-ui,sans-serif';
  list.forEach(m => {
    const x0 = X(m.t), x1 = Math.max(m.t2 != null ? X(m.t2) : x0, x0 + 14 + ctx.measureText(msShort(m)).width);
    let lane = ends.findIndex(e => e + 6 <= x0 - 3); if (lane < 0) { lane = ends.length; }
    ends[lane] = x1; items.push({ m, lane, x0, x1 });
  });
  return { items, n: Math.max(1, ends.length) };
}
function drawMs(r) {
  ctx.font = (mobile() ? 10 : 11) + 'px system-ui,sans-serif'; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
  msLayout().items.forEach(({ m, lane, x0, x1 }) => {
    const y = r.y + 3 + lane * 24;
    if (m.t2 != null) { ctx.fillStyle = MSC; ctx.globalAlpha = .22; ctx.fillRect(x0, y + 1, Math.max(2, X(m.t2) - x0), 20); ctx.globalAlpha = 1; }
    ctx.fillStyle = MSC; ctx.beginPath(); ctx.moveTo(x0, y + 2); ctx.lineTo(x0 + 9, y + 6.5); ctx.lineTo(x0, y + 11); ctx.closePath(); ctx.fill(); ctx.fillRect(x0 - 1, y + 1, 2, 20);
    ctx.fillStyle = C.text; ctx.fillText(msShort(m), x0 + 13, y + 12);
    HIT.push({ k: 'ms', o: m, x: x0 - 3, y, w: Math.max(14, x1 - x0 + 3), h: 22 });
  });
}
// ---------- poznatky (jen s časem; nejsou v náhledové verzi) ----------
const PZC = '#db2777';
const poznOk = n => n.who == null ? !S.pf.size : personOk(n.who);
const poznShort = n => n.txt.length > 70 ? n.txt.slice(0, 67) + '…' : n.txt;
function drawPozn(r) {
  const d = S.dat; if (!d || !d.pozn) return;
  d.pozn.forEach(n => {
    if (n.t < S.v0 || n.t > S.v1 || !poznOk(n)) return;
    const x = X(n.t), cy = r.y + r.h / 2;
    ctx.fillStyle = PZC; ctx.beginPath(); ctx.arc(x, cy, 7, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = '#fff'; ctx.font = 'bold 10px system-ui,sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText('i', x, cy + .5);
    HIT.push({ k: 'pozn', o: n, cx: x, cy, r: 10 });
  });
}
function vacLanes() {
  const lanes = [];
  S.dat.vac.forEach(v => { let k = lanes.findIndex(e => e <= v.a); if (k < 0) { k = lanes.length; lanes.push(0); } lanes[k] = v.b; v.lane = k; });
  return lanes.length;
}
function lb(arr, t) { let lo = 0, hi = arr.length; while (lo < hi) { const m = (lo + hi) >> 1; if (arr[m] < t) lo = m + 1; else hi = m; } return lo; }
function tickStep() {
  const steps = [60, 300, 600, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400, 172800, 604800], span = S.v1 - S.v0;
  return steps.find(s => s / span * PW() >= 64) || 604800;
}
function draw() {
  if (!S.meta) return;
  W = stage.clientWidth; dpr = window.devicePixelRatio || 1;
  const rows = rowsLayout(), AX = 34;
  H = AX + rows.reduce((s, r) => s + r.h, 0) + 8;
  if (cv.width !== Math.round(W * dpr) || cv.height !== Math.round(H * dpr)) { cv.width = Math.round(W * dpr); cv.height = Math.round(H * dpr); }
  cv.style.height = H + 'px';
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  colors(); HIT = [];
  ctx.fillStyle = C.bg; ctx.fillRect(0, 0, W, H);
  const lw = LW(), pw = PW(), span = S.v1 - S.v0, small = mobile();
  let y = AX; rows.forEach((r, i) => { r.y = y; ctx.fillStyle = i % 2 ? C.row2 : C.row; ctx.fillRect(0, y, W, r.h); y += r.h; });
  // osa a mřížka
  const step = tickStep(); ctx.font = (small ? 10 : 11) + 'px system-ui,sans-serif'; ctx.textBaseline = 'middle';
  for (let t = Math.ceil(S.v0 / step) * step; t <= S.v1; t += step) {
    const x = X(t), midnight = t % 86400 === 0;
    ctx.strokeStyle = midnight ? C.muted : C.grid; ctx.globalAlpha = midnight ? .55 : 1; ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(x + .5, AX - 8); ctx.lineTo(x + .5, H - 6); ctx.stroke(); ctx.globalAlpha = 1;
    ctx.fillStyle = midnight ? C.text : C.muted; ctx.textAlign = 'center';
    ctx.fillText(step >= 86400 || midnight ? (step >= 86400 ? fmtD(t) : DOW[D(t).getUTCDay()] + ' ' + fmtD(t)) : fmtT(t), Math.min(W - 28, Math.max(lw + 20, x)), AX - 18);
  }
  // obsah řádků (ořez na graf)
  ctx.save(); ctx.beginPath(); ctx.rect(lw, AX - 6, pw + 10, H - AX + 6); ctx.clip();
  if (S.layers.ms) drawMsLines(AX);
  const bins = S.dat ? binCounts(span) : null;
  rows.forEach(r => {
    if (r.k === 'inc') drawInc(r); else if (r.k === 'kab') drawKab(r); else if (r.k === 'tn') drawTn(r, bins);
    else if (r.k === 'poz') drawPoz(r); else if (r.k === 'dov') drawDov(r); else if (r.k === 'ms') drawMs(r); else if (r.k === 'pozn') drawPozn(r);
  });
  if (S.sel) drawSel();
  ctx.restore();
  // popisky řádků
  ctx.textAlign = 'left'; ctx.textBaseline = 'middle'; ctx.font = (small ? 11 : 12) + 'px system-ui,sans-serif';
  rows.forEach(r => { ctx.fillStyle = C.bg; ctx.globalAlpha = .93; ctx.fillRect(0, r.y, lw - 2, r.h); ctx.globalAlpha = 1; ctx.fillStyle = C.text; ctx.fillText(fitText(small ? r.short : r.label, lw - (small ? 6 : 16)), small ? 4 : 10, r.y + r.h / 2); });
  ctx.strokeStyle = C.grid; ctx.beginPath(); ctx.moveTo(lw - .5, AX - 8); ctx.lineTo(lw - .5, H - 6); ctx.stroke();
  msg(S.dat && !S.dat.rep.length && !S.dat.pt.length && !S.dat.sig.length ? 'V tomto rozsahu nejsou žádná data.' : '');
  updateFoot();
}
function drawInc(r) {
  const tol = S.meta.tol * 60;
  S.meta.inc.forEach(i => {
    if (!incVisible(i)) return;
    const a = i.s != null ? i.s : i.e - 1200, e = i.e, te = e + tol;
    if (te < S.v0 || a > S.v1) return;
    const x0 = X(a), x1 = X(e), x2 = X(te), ly = r.y + 6 + i.lane * 24, h = 20;
    const col = i.ign ? '#94a3b8' : (CUB[i.c] || '#64748b');
    ctx.globalAlpha = i.ign ? .45 : .8; ctx.fillStyle = col; ctx.fillRect(x0, ly, Math.max(3, x1 - x0), h);
    ctx.globalAlpha = .28; ctx.fillRect(x1, ly, Math.max(1, x2 - x1), h); ctx.globalAlpha = 1;
    ctx.strokeStyle = col; ctx.lineWidth = 1.2; if (i.s == null || i.virt) ctx.setLineDash([4, 3]); ctx.strokeRect(x0 + .5, ly + .5, Math.max(3, x2 - x0), h - 1); ctx.setLineDash([]);
    ctx.fillStyle = '#fff'; ctx.font = 'bold 11px system-ui,sans-serif'; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
    const lbl = '#' + i.no + (x1 - x0 > 80 ? ' ' + (B.cubShort[i.c] || '') : '');
    if (x1 - x0 > 26) { ctx.save(); ctx.beginPath(); ctx.rect(x0, ly, x1 - x0, h); ctx.clip(); ctx.fillText(lbl, Math.max(x0, LW()) + 4, ly + h / 2 + .5); ctx.restore(); }
    if (S.layers.qual && i.warn.length) { ctx.fillStyle = C.warn; ctx.beginPath(); ctx.arc(Math.min(x2, W) - 9, ly + h / 2, 7, 0, 6.3); ctx.fill(); ctx.fillStyle = '#fff'; ctx.font = 'bold 11px system-ui'; ctx.textAlign = 'center'; ctx.fillText('!', Math.min(x2, W) - 9, ly + h / 2 + .5); }
    HIT.push({ k: 'inc', o: i, x: Math.min(x0, x1), y: ly, w: Math.max(8, x2 - Math.min(x0, x1)), h });
  });
}
function drawKab(r) {
  const d = S.dat; if (!d) return;
  const reps = d.repBy[r.key] || [], pts = []; const pv = d.prev[r.key];
  if (pv) pts.push({ t: pv.t, s: pv.s, pre: true }); reps.forEach(x => pts.push(x));
  const cy = r.y + r.h / 2;
  pts.forEach((p, i) => {
    const nt = i + 1 < pts.length ? pts[i + 1].t : d.to;
    if (nt < S.v0 || p.t > S.v1) return;
    const x0 = Math.max(LW(), X(p.t)), x1 = X(nt);
    ctx.globalAlpha = p.s ? .3 : .2; ctx.fillStyle = p.s ? C.bad : C.ok; ctx.fillRect(x0, r.y + 8, Math.max(0, x1 - x0), r.h - 16); ctx.globalAlpha = 1;
    if (S.layers.gaps && !p.s && nt - p.t > 3600) { ctx.fillStyle = hatchAmber; ctx.fillRect(x0, r.y + 8, Math.max(0, x1 - x0), r.h - 16); }
  });
  reps.forEach(p => {
    if (p.t < S.v0 || p.t > S.v1) return;
    const x = X(p.t); ctx.beginPath(); ctx.arc(x, cy, 5.5, 0, 6.3);
    if (p.v) { ctx.fillStyle = C.bg; ctx.fill(); ctx.strokeStyle = p.s ? C.bad : C.ok; ctx.lineWidth = 2; ctx.setLineDash([2, 2]); ctx.stroke(); ctx.setLineDash([]); }
    else { ctx.fillStyle = p.s ? C.bad : C.ok; ctx.fill(); }
    HIT.push({ k: 'rep', o: p, cx: x, cy, r: 7 });
  });
}
function binCounts(span) {
  const d = S.dat; if (!d) return null;
  const bin = BINS.find(b => b / span * PW() >= 4) || 86400, dots = span <= 7200;
  const res = { bin, dots, maps: d.tsk.map(() => [new Map(), new Map()]), max: 1 };   // maps[turniket][0 = IN, 1 = OUT]
  if (dots) return res;
  const i0 = lb(d.pt, S.v0 - bin), i1 = lb(d.pt, S.v1 + bin);
  for (let i = i0; i < i1; i++) {
    if (!passOn(i)) { continue; }
    const k = Math.floor(d.pt[i] / bin), m = res.maps[d.pts[i]][d.pd[i]], c = (m.get(k) || 0) + 1; m.set(k, c); if (c > res.max) res.max = c;
  }
  return res;
}
function hatchCoverage(r) {
  const d = S.dat; if (!d || !S.layers.qual) return;
  ctx.fillStyle = hatchPat;
  for (let t = dayStart(S.v0); t <= S.v1; t += 86400) {
    const cov = d.cov[iso(t)], a = t, b = t + 86400;
    if (b < d.from || a > d.to) continue;
    if (!cov) { ctx.fillRect(X(a), r.y + 2, X(b) - X(a), r.h - 4); continue; }
    ctx.fillRect(X(a), r.y + 2, Math.max(0, X(cov[0]) - X(a)), r.h - 4);
    ctx.fillRect(X(cov[1]), r.y + 2, Math.max(0, X(b) - X(cov[1])), r.h - 4);
  }
}
function drawTn(r, bins) {
  const d = S.dat; if (!d || !bins) return;
  hatchCoverage(r);
  const col = tsColor(r.key);
  if (bins.dots) {
    const i0 = lb(d.pt, S.v0 - 60), i1 = lb(d.pt, S.v1 + 60);
    for (let i = i0; i < i1; i++) {
      if (d.pts[i] !== r.i) continue;
      const f = d.pd[i]; if (!passOn(i)) { continue; }
      const x = X(d.pt[i]), y = f === 0 ? r.y + r.h * 0.12 + 5 + (i % 3) * 6 : r.y + r.h * 0.55 + 5 + (i % 3) * 6;   // IN nahoře (plný bod), OUT dole (kroužek)
      ctx.beginPath(); ctx.arc(x, y, 3, 0, 6.3);
      if (f === 0) { ctx.fillStyle = col; ctx.fill(); } else { ctx.strokeStyle = OUTC; ctx.lineWidth = 1.8; ctx.stroke(); }
      HIT.push({ k: 'pass', o: { t: d.pt[i], who: d.names[d.pn[i]], dir: d.pd[i], ts: r.label }, cx: x, cy: y, r: 7 });
    }
    return;
  }
  const bw = Math.max(1, bins.bin / (S.v1 - S.v0) * PW() - 1), mid = r.y + r.h / 2, half = r.h / 2 - 4;
  ctx.globalAlpha = .35; ctx.fillStyle = C.muted; ctx.fillRect(LW(), Math.round(mid), PW(), 1); ctx.globalAlpha = .85;   // IN roste nahoru od osy, OUT dolů
  [0, 1].forEach(f => {
    if (!dirOn(f)) { return; }
    ctx.fillStyle = f === 0 ? col : OUTC;
    bins.maps[r.i][f].forEach((c, k) => { const h = Math.max(2, c / bins.max * half), x = X(k * bins.bin); if (f === 0) { ctx.fillRect(x, mid - h, bw, h); } else { ctx.fillRect(x, mid + 1, bw, h); } });
  });
  ctx.globalAlpha = 1;
}
function drawPoz(r) {
  const d = S.dat; if (!d) return;
  d.sig.forEach(s => {
    if (s.t < S.v0 || s.t > S.v1 || !personOk(s.who)) return;
    const x = X(s.t), cy = r.y + r.h / 2; ctx.fillStyle = DIRC[s.dir] || '#94a3b8';
    ctx.beginPath(); ctx.moveTo(x, cy - 8); ctx.lineTo(x + 7, cy); ctx.lineTo(x, cy + 8); ctx.lineTo(x - 7, cy); ctx.closePath(); ctx.fill();
    HIT.push({ k: 'sig', o: s, cx: x, cy, r: 10 });
  });
}
function drawDov(r) {
  const d = S.dat; if (!d) return;
  d.vac.forEach(v => {
    if (v.b < S.v0 || v.a > S.v1) return;
    const x0 = X(v.a), x1 = X(v.b), ly = r.y + 5 + v.lane * 22;
    ctx.fillStyle = '#0ea5e9'; ctx.globalAlpha = .55; ctx.fillRect(x0, ly, Math.max(2, x1 - x0), 18); ctx.globalAlpha = 1;
    ctx.fillStyle = C.text; ctx.font = '11px system-ui'; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
    ctx.save(); ctx.beginPath(); ctx.rect(x0, ly, x1 - x0, 18); ctx.clip(); ctx.fillText(v.who, Math.max(x0, LW()) + 4, ly + 9.5); ctx.restore();
    HIT.push({ k: 'vac', o: v, x: x0, y: ly, w: Math.max(6, x1 - x0), h: 18 });
  });
}
function drawSel() {
  const h = HIT.find(x => x.o === S.sel.o);
  if (!h) return;
  ctx.strokeStyle = C.accent; ctx.lineWidth = 2.5;
  if (h.w != null) ctx.strokeRect(h.x - 1.5, h.y - 1.5, h.w + 3, h.h + 3); else { ctx.beginPath(); ctx.arc(h.cx, h.cy, h.r + 1, 0, 6.3); ctx.stroke(); }
}
function updateFoot() {
  const n = S.dat ? S.dat.pt.length : 0;
  $('#foot').textContent = 'Zobrazeno ' + fmtDT(S.v0).slice(0, 16) + ' až ' + fmtDT(S.v1).slice(0, 16) + ' · ' + fmtDur(S.v1 - S.v0) + (S.dat ? ' · načteno průchodů: ' + n : '') + (S.dat && S.dat.trunc ? ' (zkráceno)' : '') + ' · čas je v hodinách docházky, tolerance po nálezu ' + S.meta.tol + ' min' + (S.dat && S.dat.hid && (S.dat.hid.pass || S.dat.hid.sig || S.dat.hid.vac) ? ' · skryto (osoby vyřazené z hodnocení): ' + S.dat.hid.pass + ' průchodů' : '') + (S.layers.bl ? ' · zobrazeny i osoby vyřazené z hodnocení' : '') + (anyTs() && S.layers.first ? ' · jen první průchod osoby za den' + (S.dir.in && S.dir.out ? '' : (S.dir.in ? ' (mezi IN)' : ' (mezi OUT)')) : '')
    + (S.pf.size ? ' · filtr osob: ' + [...S.pf.values()].join(', ') : '') + (S.meta && S.meta.inc.some(i => incVisible(i) === !!i.ign) ? ' · zobrazeno ' + S.meta.inc.filter(incVisible).length + ' z ' + S.meta.inc.length + ' incidentů' + (S.lab.sel.size ? ' (štítky ' + (S.lab.mode === 'inter' ? 'průnik' : 'sjednocení') + ': ' + [...S.lab.sel].join(', ') + ')' : '') : '');
}

// ---------- pohled, posun a zoom ----------
function setView(a, b, now) {
  let span = Math.min(MAX_SPAN, Math.max(MIN_SPAN, b - a)); const mid = (a + b) / 2;
  S.v0 = mid - span / 2; S.v1 = mid + span / 2;
  const lo = (S.meta.first != null ? S.meta.first : S.v0) - 2 * 86400, hi = (S.meta.last != null ? S.meta.last : S.v1) + 2 * 86400;
  if (S.v0 < lo) { S.v1 += lo - S.v0; S.v0 = lo; } if (S.v1 > hi) { S.v0 -= S.v1 - hi; S.v1 = hi; }
  S.listN = 300; draw(); ensureData(now);
  if (S.mode === 'seznam') renderList();
}
function zoomAt(x, f) { const t = TT(x), a = t - (t - S.v0) * f, b = t + (S.v1 - t) * f; setView(a, b); }
function showIncident(no) {
  const i = S.meta.inc.find(x => x.no === +no); if (!i) return;
  if (!incVisible(i)) { S.incSel.add(i.id); packIncidents(); syncIncCard(); }   // incident mimo výběr se po přeskoku přidá do výběru
  const a = (i.s != null ? i.s : i.e - 3600) - 1500, b = i.e + S.meta.tol * 60 + 1500;
  setView(a, b, true); S.sel = { k: 'inc', o: i }; draw(); detail('inc', i);
}
function setDays(n) { const end = dayStart(S.meta.last != null ? S.meta.last : Date.now() / 1000) + 86400; setView(end - n * 86400, end, true); }

// ---------- detail a tooltip ----------
const sheet = $('#sheet'), body = $('#sheetBody');
function row(dl, k, v) { if (v == null || v === '') return; dl.appendChild(el('dt', null, k)); const dd = el('dd'); if (v instanceof Node) dd.appendChild(v); else dd.textContent = v; dl.appendChild(dd); }
function badge(txt, col) { const b = el('span', 'badge', txt); if (col) b.style.background = col; return b; }
// Podrobnosti incidentu (použité v dolním panelu i v rozbalené kartě incidentu)
function fillIncDetail(parent, o) {
  const dl = el('dl');
  row(dl, 'Okno', o.s != null ? fmtDT(o.s) + ' → ' + fmtDT(o.e) : 'začátek neznámý → ' + fmtDT(o.e));
  if (o.s != null) row(dl, 'Délka', fmtDur(o.e - o.s));
  row(dl, 'Tolerance', '+' + S.meta.tol + ' min po nálezu (do ' + fmtT(o.e + S.meta.tol * 60) + '), jen pro průchody');
  row(dl, 'Poslední „čisto“', o.okby); row(dl, 'Nález nahlásil', o.by);
  if (o.s != null && !B.nopass) { row(dl, 'Průchody v okně', o.np + ' (' + o.npp + ' osob)'); }
  row(dl, 'Poznámka k „čisto“', o.okn); row(dl, 'Poznámka k nálezu', o.n); row(dl, 'Důvod vyřazení', o.ignr);
  parent.appendChild(dl);
  if (o.warn.length) { const w = el('div', 'warnbox'); w.appendChild(el('b', null, 'Upozornění k datům')); const ul = el('ul'); o.warn.forEach(c => ul.appendChild(el('li', null, WARN[c] || c))); w.appendChild(ul); parent.appendChild(w); }
}
function detail(kind, o) {
  body.textContent = ''; const dl = el('dl');
  const cn = c => (B.cubicles.find(x => x.key === c) || {}).name || c;
  if (kind === 'inc') {
    body.appendChild(el('h2', null, 'Incident #' + o.no + ' · ' + cn(o.c)));
    const bs = el('div'); o.lab.forEach(l => bs.appendChild(badge(l.n, l.c))); if (o.ign) bs.appendChild(badge('vyřazen adminem', '#6b7280')); if (o.virt) bs.appendChild(badge('zpětně zadáno', '#d97706')); if (bs.childNodes.length) body.appendChild(bs);
    fillIncDetail(body, o);
    const a = el('a', null, 'Otevřít hlavní aplikaci'); a.href = 'index.php'; a.style.display = 'inline-block'; a.style.marginTop = '10px'; body.appendChild(a);
  } else if (kind === 'pozn') {
    body.appendChild(el('h2', null, 'Poznatek'));
    row(dl, 'Čas', fmtDT(o.t)); row(dl, 'Osoba', o.who); row(dl, 'Poznatek', o.txt); row(dl, 'Vložil', o.by); row(dl, 'Vloženo', fmtDT(o.at)); body.appendChild(dl);
  } else if (kind === 'ms') {
    body.appendChild(el('h2', null, 'Milník'));
    row(dl, 'Co', msText(o)); row(dl, 'Kdy', msWhen(o)); row(dl, 'Typ', { left: 'Osoba skončila', joined: 'Osoba nastoupila', away: 'Osoba je pryč', change: 'Změna provozu', other: 'Jiné' }[o.k] || o.k);
    row(dl, 'Osoba', o.who); row(dl, 'Název', o.title); row(dl, 'Poznámka', o.n); body.appendChild(dl);
  } else if (kind === 'rep') {
    body.appendChild(el('h2', null, (o.s ? 'ZESRANÁ' : 'ČISTO') + ' · ' + cn(o.c)));
    row(dl, 'Čas', fmtDT(o.t)); row(dl, 'Nahlásil', o.by); row(dl, 'Poznámka', o.n); if (o.v) row(dl, 'Pozn.', 'zpětně zadané (virtuální) hlášení');
    body.appendChild(dl);
  } else if (kind === 'pass') {
    body.appendChild(el('h2', null, 'Průchod turniketem'));
    row(dl, 'Čas', fmtDT(o.t)); row(dl, 'Turniket', o.ts); row(dl, 'Osoba', o.who); row(dl, 'Směr', o.dir ? 'OUT (ven)' : 'IN (dovnitř)'); body.appendChild(dl);
  } else if (kind === 'sig') {
    body.appendChild(el('h2', null, 'Pozorování osoby'));
    row(dl, 'Čas', fmtDT(o.t)); row(dl, 'Osoba', o.who); row(dl, 'Kde', B.locs[o.loc] || o.loc); row(dl, 'Co dělala', B.dirs[o.dir] || o.dir); row(dl, 'Nahlásil', o.by); body.appendChild(dl);
  } else if (kind === 'vac') {
    body.appendChild(el('h2', null, 'Dovolená člena IT'));
    row(dl, 'Osoba', o.who); row(dl, 'Od', fmtDay(o.a)); row(dl, 'Do', fmtDay(o.b - 86400)); body.appendChild(dl);
  }
  sheet.style.display = 'block';
}
function closeSheet() { sheet.style.display = 'none'; S.sel = null; draw(); }
$('#sheetX').addEventListener('click', closeSheet);
const tip = $('#tip');
function tipText(h) {
  const o = h.o;
  if (h.k === 'inc') return '#' + o.no + ' ' + ((B.cubicles.find(x => x.key === o.c) || {}).name || '') + ' · ' + (o.s != null ? fmtT(o.s) : '?') + '–' + fmtT(o.e) + (o.warn.length && S.layers.qual ? ' ⚠' : '');
  if (h.k === 'ms') return dmy(o.f) + ' · ' + msText(o);
  if (h.k === 'pozn') return fmtT(o.t) + ' · ' + (o.who ? o.who + ' · ' : '') + poznShort(o);
  if (h.k === 'rep') return (o.s ? 'ZESRANÁ ' : 'ČISTO ') + fmtT(o.t) + ' · ' + o.by;
  if (h.k === 'pass') return fmtT(o.t) + ' · ' + (o.dir ? 'OUT' : 'IN') + ' · ' + o.who + ' · ' + o.ts;
  if (h.k === 'sig') return fmtT(o.t) + ' · ' + o.who + ' · ' + (B.dirs[o.dir] || o.dir);
  return o.who;
}

// ---------- ovládání (myš, dotyk) ----------
const ptr = new Map(); let pan = null, pinch = null, moved = 0;
function hitAt(px, py, touch) {
  let best = null, bd = 1e9;
  for (const h of HIT) if (h.w == null) { const d = Math.hypot(px - h.cx, py - h.cy); if (d <= h.r + (touch ? 6 : 0) && d < bd) { best = h; bd = d; } }
  if (best) return best;
  const pad = touch ? 8 : 2;
  for (let i = HIT.length - 1; i >= 0; i--) { const h = HIT[i]; if (h.w != null && px >= h.x - pad && px <= h.x + h.w + pad && py >= h.y - pad && py <= h.y + h.h + pad) return h; }
  return null;
}
cv.addEventListener('pointerdown', e => {
  try { cv.setPointerCapture(e.pointerId); } catch (err) { /* ukazatel už nemusí existovat */ } const r = cv.getBoundingClientRect(); ptr.set(e.pointerId, { x: e.clientX - r.left, y: e.clientY - r.top, t: e.pointerType });
  moved = 0; tip.style.display = 'none';
  if (ptr.size === 1) { pan = { x: e.clientX - r.left, v0: S.v0, v1: S.v1 }; pinch = null; }
  else if (ptr.size === 2) { const [a, b] = [...ptr.values()]; pinch = { d: Math.hypot(a.x - b.x, a.y - b.y), v0: S.v0, v1: S.v1, cx: (a.x + b.x) / 2 }; pan = null; }
});
cv.addEventListener('pointermove', e => {
  const r = cv.getBoundingClientRect(), x = e.clientX - r.left, y = e.clientY - r.top;
  if (ptr.has(e.pointerId)) {
    ptr.get(e.pointerId).x = x; ptr.get(e.pointerId).y = y;
    if (pinch && ptr.size >= 2) {
      const [a, b] = [...ptr.values()], d = Math.hypot(a.x - b.x, a.y - b.y); moved = 99;
      if (pinch.d > 0 && d > 0) { const f = pinch.d / d, t = pinch.v0 + (pinch.cx - LW()) / PW() * (pinch.v1 - pinch.v0); setView(t - (t - pinch.v0) * f, t + (pinch.v1 - t) * f); }
    } else if (pan) {
      const dx = x - pan.x; moved = Math.max(moved, Math.abs(dx)); if (moved > 4) { const dt = -dx / PW() * (pan.v1 - pan.v0); setView(pan.v0 + dt, pan.v1 + dt); }
    }
  } else if (e.pointerType === 'mouse') {
    const h = hitAt(x, y, false);
    if (h) { tip.textContent = tipText(h); tip.style.display = 'block'; tip.style.left = Math.min(innerWidth - 300, e.clientX + 14) + 'px'; tip.style.top = (e.clientY + 14) + 'px'; cv.style.cursor = 'pointer'; }
    else { tip.style.display = 'none'; cv.style.cursor = ''; }
  }
});
const endPtr = e => {
  const p = ptr.get(e.pointerId); ptr.delete(e.pointerId);
  if (p && moved < 6 && ptr.size === 0 && e.type === 'pointerup') {
    const h = hitAt(p.x, p.y, p.t !== 'mouse');
    if (h) { S.sel = { k: h.k, o: h.o }; draw(); detail(h.k, h.o); } else if (sheet.style.display === 'block') closeSheet();
  }
  if (ptr.size < 2) pinch = null;
  if (ptr.size === 1) { const [q] = [...ptr.values()]; pan = { x: q.x, v0: S.v0, v1: S.v1 }; moved = 99; } else if (!ptr.size) pan = null;
};
cv.addEventListener('pointerup', endPtr); cv.addEventListener('pointercancel', endPtr);
cv.addEventListener('pointerleave', () => { tip.style.display = 'none'; });
cv.addEventListener('wheel', e => { e.preventDefault(); const r = cv.getBoundingClientRect(); if (e.shiftKey || Math.abs(e.deltaX) > Math.abs(e.deltaY)) { const dt = (e.deltaX || e.deltaY) / PW() * (S.v1 - S.v0); setView(S.v0 + dt, S.v1 + dt); } else zoomAt(e.clientX - r.left, e.deltaY > 0 ? 1.25 : 0.8); }, { passive: false });
cv.addEventListener('keydown', e => {
  const sp = S.v1 - S.v0;
  if (e.key === 'ArrowLeft') setView(S.v0 - sp * .2, S.v1 - sp * .2); else if (e.key === 'ArrowRight') setView(S.v0 + sp * .2, S.v1 + sp * .2);
  else if (e.key === '+' || e.key === '=') zoomAt(LW() + PW() / 2, .7); else if (e.key === '-') zoomAt(LW() + PW() / 2, 1.4); else if (e.key === 'Escape') closeSheet(); else return;
  e.preventDefault();
});
$('#zIn').addEventListener('click', () => zoomAt(LW() + PW() / 2, .6));
$('#zOut').addEventListener('click', () => zoomAt(LW() + PW() / 2, 1.6));

// ---------- seznam událostí (mobil) ----------
function renderList() {
  const box = $('#list'); if (S.mode !== 'seznam') return; box.textContent = '';
  if (!S.dat) { box.appendChild(el('div', 'empty', 'Načítám…')); return; }
  const d = S.dat, items = [], a = S.v0, b = S.v1;
  const cn = c => (B.cubicles.find(x => x.key === c) || {}).name || c;
  if (S.layers.inc) S.meta.inc.forEach(i => { if (i.e >= a && i.e < b && incVisible(i)) items.push({ t: i.e, k: 'inc', o: i, col: i.ign ? '#94a3b8' : CUB[i.c], title: 'Incident #' + i.no + ' · ' + cn(i.c), sub: (i.s != null ? 'okno ' + fmtT(i.s) + '–' + fmtT(i.e) + ' (' + fmtDur(i.e - i.s) + ')' : 'okno neznámé') + (i.ign ? ' · vyřazen' : '') + (i.warn.length && S.layers.qual ? ' · ⚠ ' + i.warn.length : '') }); });
  if (!B.ro && S.layers.pozn && d.pozn) d.pozn.forEach(n => { if (n.t >= a && n.t < b && poznOk(n)) items.push({ t: n.t, k: 'pozn', o: n, col: PZC, title: 'Poznatek: ' + poznShort(n), sub: (n.who ? n.who + ' · ' : '') + 'vložil ' + n.by }); });
  if (S.layers.ms && S.meta.ms) S.meta.ms.forEach(m => { if (m.t >= a && m.t < b) items.push({ t: m.t, k: 'ms', o: m, col: MSC, title: 'Milník: ' + msText(m), sub: msWhen(m) + (m.n ? ' · ' + m.n : '') }); });
  if (S.layers.kab) d.rep.forEach(r => { if (r.t >= a && r.t < b) items.push({ t: r.t, k: 'rep', o: r, col: r.s ? 'var(--bad)' : 'var(--ok)', title: (r.s ? 'ZESRANÁ' : 'ČISTO') + ' · ' + cn(r.c), sub: r.by + (r.n ? ' · ' + r.n : '') }); });
  if (S.layers.poz) d.sig.forEach(s => { if (s.t >= a && s.t < b && personOk(s.who)) items.push({ t: s.t, k: 'sig', o: s, col: DIRC[s.dir], title: 'Pozorování: ' + s.who, sub: (B.dirs[s.dir] || s.dir) + ' · ' + (B.locs[s.loc] || s.loc) + (s.by ? ' · hlásil ' + s.by : '') }); });
  if (S.layers.dov) d.vac.forEach(v => { if (v.a >= a && v.a < b && personOk(v.who)) items.push({ t: v.a, k: 'vac', o: v, col: '#0ea5e9', title: 'Dovolená IT: ' + v.who, sub: fmtD(v.a) + ' – ' + fmtD(v.b - 86400) }); });
  // průchody turnikety (podle zapnutých turniketů, směru, „prvního průchodu dne“ a filtru osob)
  let nPass = 0;
  if (anyTs() && d.pt.length) {
    for (let i = lb(d.pt, a), e = lb(d.pt, b); i < e; i++) {   // rozsah je zleva uzavřený a zprava otevřený, aby se událost o půlnoci nezobrazila u dvou sousedních pohledů
      if (!passOn(i)) { continue; }
      const key = d.tsk[d.pts[i]], tn = B.turnstiles.find(x => x.key === key), tname = tn ? tn.name : key, out = d.pd[i] === 1;
      items.push({ t: d.pt[i], k: 'pass', o: { t: d.pt[i], who: d.names[d.pn[i]], dir: d.pd[i], ts: tname }, col: out ? OUTC : tsColor(key), ring: out, title: (out ? 'OUT' : 'IN') + ' · ' + d.names[d.pn[i]], sub: tname });
      nPass++;
    }
  }
  items.sort((x, y) => x.t - y.t);
  if (!items.length) { box.appendChild(el('div', 'empty', 'V zobrazeném rozsahu nejsou žádné události. Posuň nebo zvětši rozsah, případně uprav filtry.')); return; }
  box.appendChild(el('div', 'sum', 'Událostí: ' + items.length + ' (z toho průchodů: ' + nPass + ')' + (items.length > S.listN ? ' · zobrazeno prvních ' + S.listN : '')));
  let lastDay = null, n = 0;
  for (const it of items) {
    if (n++ >= S.listN) { break; }
    const dy = dayStart(it.t); if (dy !== lastDay) { box.appendChild(el('div', 'dayh', fmtDay(it.t))); lastDay = dy; }
    const li = el('div', 'li'); li.appendChild(el('span', 'tm', fmtT(it.t))); const dot = el('span', 'dot' + (it.ring ? ' ring' : '')); if (it.ring) { dot.style.borderColor = it.col; } else { dot.style.background = it.col; } li.appendChild(dot);
    const tx = el('div', 'tx'); tx.appendChild(el('b', null, it.title)); if (it.sub) tx.appendChild(el('small', null, it.sub)); li.appendChild(tx);
    li.addEventListener('click', () => detail(it.k, it.o)); box.appendChild(li);
  }
  if (items.length > S.listN) {
    const more = el('button', 'btn moreb', 'Zobrazit dalších ' + Math.min(300, items.length - S.listN) + ' (zbývá ' + (items.length - S.listN) + ')'); more.type = 'button';
    more.addEventListener('click', () => { S.listN += 300; renderList(); }); box.appendChild(more);
  }
}
function setMode(m) {
  S.mode = m; $('#stage').style.display = m === 'osa' ? '' : 'none'; $('#list').style.display = m === 'seznam' ? 'block' : 'none';
  $('#modeBtn').textContent = m === 'osa' ? 'Seznam' : 'Osa'; if (m === 'seznam') renderList(); else draw();
  updateUrl();
}

// ---------- vrstvy, předvolby, URL ----------
const LAYERS_A = [['inc', 'Incidenty', '#ef4444'], ['kab', 'Kabinky', '#16a34a'], ['ms', 'Milníky', '#d97706']];
const LAYERS_B = [['poz', 'Pozorování', '#dc2626'], ...(B.ro ? [] : [['pozn', 'Poznatky', '#db2777']]), ['dov', 'Dovolené IT', '#0ea5e9'], ['gaps', 'Mezery > 60 min', '#d97706'], ['qual', 'Kvalita dat', '#64748b'], ['first', 'První průchod dne', '#7c3aed'], ['bl', 'Vyřazené osoby', '#6b7280']];
function filtersChanged() { S.listN = 300; draw(); renderList(); }
function mkChip(box, name, col, on, toggle, hint, ring) {
  const c = el('span', 'chip' + (on ? ' on' : '')); const i = el('i'); i.style.background = col; if (ring) { i.style.borderRadius = '50%'; } c.appendChild(i); c.appendChild(document.createTextNode(name)); if (hint) { c.title = hint; }
  c.setAttribute('role', 'button'); c.tabIndex = 0;
  const tg = () => { c.classList.toggle('on', toggle()); };
  c.addEventListener('click', tg); c.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); tg(); } }); box.appendChild(c);
}
function buildChips() {
  const box = $('#chips'); box.textContent = '';
  const layer = ([k, name, col]) => mkChip(box, name, col, S.layers[k], () => { S.layers[k] = !S.layers[k]; store.set('lhviz_layers', JSON.stringify(S.layers)); filtersChanged(); if (k === 'bl') { reloadAll(); } return S.layers[k]; });
  LAYERS_A.forEach(layer);
  // turnikety se zapínají a vypínají každý zvlášť
  if (!B.nopass) B.turnstiles.forEach(t => mkChip(box, t.name, tsColor(t.key), S.ts[t.key] !== false, () => { S.ts[t.key] = !S.ts[t.key]; store.set('lhviz_ts', JSON.stringify(S.ts)); filtersChanged(); return S.ts[t.key]; }, 'Průchody přes tento turniket'));
  LAYERS_B.forEach(layer);
  // směr průchodů na turniketech (jen zobrazení v prohlížeči)
  if (!B.nopass) [['in', 'IN dovnitř', '#7c3aed', 'Průchody dovnitř: nahoře v řádku turniketu (plný bod)'], ['out', 'OUT ven', OUTC, 'Průchody ven: dole v řádku turniketu (kroužek)']].forEach(([k, name, col, hint]) => {
    mkChip(box, name, col, S.dir[k], () => { S.dir[k] = !S.dir[k]; store.set('lhviz_dir', JSON.stringify(S.dir)); filtersChanged(); return S.dir[k]; }, hint, k === 'out');
  });
}

// ---------- filtr osob (našeptávač) ----------
async function loadPeople() { try { S.people = (await api('ajax=people')).people || []; } catch (e) { S.people = []; } }
function personPool() {
  const m = new Map(); (S.people || []).forEach(n => m.set(nrm(n), n));
  if (S.dat) { S.dat.names.forEach(n => { const k = nrm(n); if (!m.has(k)) { m.set(k, n); } }); S.dat.sig.forEach(s => { const k = nrm(s.who); if (!m.has(k)) { m.set(k, s.who); } }); }
  return m;
}
let sugItems = [], sugAct = -1;
function markSug() { [...$('#pSug').children].forEach((c, i) => c.classList.toggle('act', i === sugAct)); }
function showSug(items) {
  const box = $('#pSug'); box.textContent = ''; sugItems = items; sugAct = items.length ? 0 : -1;
  items.forEach(it => { const o = el('div', 'opt', it.name); o.setAttribute('role', 'option'); o.addEventListener('pointerdown', e => { e.preventDefault(); pickPerson(it); }); box.appendChild(o); });
  markSug(); box.style.display = items.length ? 'block' : 'none'; $('#pInp').setAttribute('aria-expanded', items.length ? 'true' : 'false');
}
function pickPerson(it) { S.pf.set(it.key, it.name); $('#pInp').value = ''; showSug([]); renderPersons(); filtersChanged(); }
function onPInput() {
  const q = nrm($('#pInp').value); if (!q) { showSug([]); return; }
  const tok = q.split(' '), a = [], b = [];
  personPool().forEach((name, key) => { if (S.pf.has(key) || !tok.every(t => key.includes(t))) { return; } (key.startsWith(q) ? a : b).push({ key, name }); });
  const cmp = (x, y) => x.name.localeCompare(y.name, 'cs'); a.sort(cmp); b.sort(cmp); showSug(a.concat(b).slice(0, 8));
}
function renderPersons() {
  const box = $('#pSel'); box.textContent = '';
  S.pf.forEach((nm, k) => {
    const c = el('span', 'pchip'); c.appendChild(document.createTextNode(nm));
    const x = el('button', null, '×'); x.type = 'button'; x.setAttribute('aria-label', 'Odebrat ' + nm); x.addEventListener('click', () => { S.pf.delete(k); renderPersons(); filtersChanged(); }); c.appendChild(x); box.appendChild(c);
  });
  if (S.pf.size > 1) { const all = el('button', 'btn sm', 'Zrušit filtr osob'); all.type = 'button'; all.addEventListener('click', () => { S.pf.clear(); renderPersons(); filtersChanged(); }); box.appendChild(all); }
}
$('#pInp').addEventListener('input', onPInput);
$('#pInp').addEventListener('keydown', e => {
  if (e.key === 'ArrowDown' && sugItems.length) { e.preventDefault(); sugAct = (sugAct + 1) % sugItems.length; markSug(); }
  else if (e.key === 'ArrowUp' && sugItems.length) { e.preventDefault(); sugAct = (sugAct - 1 + sugItems.length) % sugItems.length; markSug(); }
  else if (e.key === 'Enter' && sugItems.length) { e.preventDefault(); pickPerson(sugItems[Math.max(0, sugAct)]); }
  else if (e.key === 'Escape') { showSug([]); }
});
$('#pInp').addEventListener('blur', () => setTimeout(() => showSug([]), 120));
$('#pInp').addEventListener('focus', onPInput);

// ---------- výběr incidentů (karta Časová okna incidentů) ----------
const defaultSel = () => new Set(S.meta.inc.filter(i => !i.ign).map(i => i.id));
function incCount() { const vis = S.meta.inc.filter(incVisible).length; $('#icnt').textContent = '(zobrazeno ' + vis + ' z ' + S.meta.inc.length + ')'; return vis; }
function labInfo() {
  const vis = incCount();
  $('#labInfo').textContent = S.lab.sel.size ? 'Zobrazeno ' + vis + ' z ' + S.meta.inc.length + ' incidentů (včetně vyřazených, mají-li vybraný štítek). Jen filtr zobrazení v tvém prohlížeči.' : 'Bez vybraného štítku platí všechny počítané incidenty. Jen filtr zobrazení v tvém prohlížeči.';
}
// výběr podle štítků: bez štítku výchozí (počítané incidenty), jinak sjednocení nebo průnik; zaškrtávátka se synchronizují
function applyLabelSel() {
  const sel = [...S.lab.sel];
  S.incSel = !sel.length ? defaultSel() : new Set(S.meta.inc.filter(i => { const nm = i.lab.map(l => l.n); return S.lab.mode === 'inter' ? sel.every(n => nm.includes(n)) : sel.some(n => nm.includes(n)); }).map(i => i.id));
  packIncidents(); syncIncCard(); filtersChanged();
}
function syncIncCard() { document.querySelectorAll('#ilist .irow').forEach(r => { const cb = r.querySelector('input[type=checkbox]'); if (cb) { cb.checked = S.incSel.has(+r.dataset.id); } }); labInfo(); }
function renderIncCard() {
  const box = $('#ilist'); box.textContent = '';
  const cn = c => (B.cubicles.find(x => x.key === c) || {}).name || c;
  S.meta.inc.slice().sort((a, b) => b.no - a.no).forEach(i => {
    const r = el('div', 'irow' + (i.ign ? ' ign' : '') + (S.incOpen.has(i.id) ? ' open' : '')); r.dataset.id = i.id;
    const h = el('div', 'ihead'); h.setAttribute('role', 'button'); h.tabIndex = 0; h.setAttribute('aria-expanded', S.incOpen.has(i.id) ? 'true' : 'false');
    const lb = el('label', 'chk'); const cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = S.incSel.has(i.id);
    lb.appendChild(cb); lb.appendChild(document.createTextNode('Zobrazit na ose'));
    cb.addEventListener('change', () => { if (cb.checked) { S.incSel.add(i.id); } else { S.incSel.delete(i.id); } S.lab.sel.clear(); buildLabels(); packIncidents(); filtersChanged(); labInfo(); });   // ruční výběr ruší štítky
    lb.addEventListener('click', e => e.stopPropagation());
    h.appendChild(lb);
    h.appendChild(el('span', 'no', '#' + i.no)); h.appendChild(el('span', 'tmx', fmtDT(i.e))); h.appendChild(el('span', 'small', cn(i.c)));
    if (i.by) { h.appendChild(el('span', 'by', 'nahlásil ' + i.by)); }
    i.lab.forEach(l => h.appendChild(badge(l.n, l.c)));
    if (i.ign) { h.appendChild(badge('vyřazen adminem', '#6b7280')); }
    r.appendChild(h);
    const det = el('div', 'idet'); r.appendChild(det);
    const fill = () => { if (det.childNodes.length) { return; } fillIncDetail(det, i); const go = el('button', 'btn sm', 'Zobrazit na časové ose'); go.type = 'button'; go.style.marginTop = '6px'; go.addEventListener('click', () => { showIncident(i.no); updateUrl(); }); det.appendChild(go); };
    const toggle = () => { const on = !r.classList.contains('open'); r.classList.toggle('open', on); h.setAttribute('aria-expanded', on ? 'true' : 'false'); if (on) { S.incOpen.add(i.id); fill(); } else { S.incOpen.delete(i.id); } };
    h.addEventListener('click', toggle); h.addEventListener('keydown', e => { if (e.target === h && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); toggle(); } });
    if (S.incOpen.has(i.id)) { fill(); }
    box.appendChild(r);
  });
  labInfo();
}
function buildLabels() {
  const cnt = new Map(); S.meta.inc.forEach(i => i.lab.forEach(l => { const e = cnt.get(l.n) || { c: l.c, n: 0 }; e.n++; cnt.set(l.n, e); }));
  [...S.lab.sel].forEach(n => { if (!cnt.has(n)) { S.lab.sel.delete(n); } });
  const box = $('#labChips'); box.textContent = ''; $('#labRow').style.display = cnt.size ? '' : 'none';
  [...cnt.entries()].sort((a, b) => b[1].n - a[1].n || a[0].localeCompare(b[0], 'cs')).forEach(([name, e]) => {
    mkChip(box, name + ' ×' + e.n, e.c, S.lab.sel.has(name), () => { if (S.lab.sel.has(name)) { S.lab.sel.delete(name); } else { S.lab.sel.add(name); } applyLabelSel(); return S.lab.sel.has(name); }, 'Vybrat jen incidenty s tímto štítkem');
  });
  labInfo();
}
document.querySelectorAll('#labMode button').forEach(b => b.addEventListener('click', () => {
  S.lab.mode = b.dataset.m; document.querySelectorAll('#labMode button').forEach(x => x.classList.toggle('on', x === b));
  if (S.lab.sel.size) { applyLabelSel(); } else { labInfo(); }
}));
function updateUrl() { try { const p = new URLSearchParams(); p.set('od', iso(S.v0)); p.set('do', iso(S.v1)); if (S.mode === 'seznam') p.set('view', 'list'); history.replaceState(null, '', '?' + p.toString()); } catch (e) {} }
document.querySelectorAll('#presets [data-p]').forEach(b => b.addEventListener('click', () => { setDays(+b.dataset.p); updateUrl(); }));
$('#fGo').addEventListener('click', () => { const a = $('#fOd').value, b = $('#fDo').value || a; if (!a) return; const t0 = Date.parse(a + 'T00:00:00Z') / 1000, t1 = Date.parse(b + 'T00:00:00Z') / 1000 + 86400; if (t1 > t0) { setView(t0, t1, true); updateUrl(); } });
$('#modeBtn').addEventListener('click', () => setMode(S.mode === 'osa' ? 'seznam' : 'osa'));
$('#moreBtn').addEventListener('click', () => { const m = $('#more'); const open = !m.classList.contains('open'); m.classList.toggle('open', open); $('#filters').classList.toggle('open', open); $('#moreBtn').setAttribute('aria-expanded', open); $('#moreBtn').textContent = open ? 'Filtr ▴' : 'Filtr ▾'; });
function applyTheme(t) { document.documentElement.setAttribute('data-theme', t); $('#themeBtn').textContent = t === 'dark' ? 'Světlý' : 'Tmavý'; draw(); }
$('#themeBtn').addEventListener('click', () => { const t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'; store.set('lhviz_theme', t); applyTheme(t); });
let rz = null; window.addEventListener('resize', () => { clearTimeout(rz); rz = setTimeout(() => { draw(); }, 120); });

// ---------- start ----------
(async () => {
  const saved = store.get('lhviz_theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  document.documentElement.setAttribute('data-theme', saved); $('#themeBtn').textContent = saved === 'dark' ? 'Světlý' : 'Tmavý';
  try { const sv = JSON.parse(store.get('lhviz_layers') || 'null'); if (sv) Object.keys(S.layers).forEach(k => { if (typeof sv[k] === 'boolean') S.layers[k] = sv[k]; }); } catch (e) {}
  try { const sd = JSON.parse(store.get('lhviz_dir') || 'null'); if (sd) { ['in', 'out'].forEach(k => { if (typeof sd[k] === 'boolean') S.dir[k] = sd[k]; }); } } catch (e) {}
  B.turnstiles.forEach(t => { S.ts[t.key] = true; });
  try {   // uložené zapnutí turniketů; starší volba „Docházka“ vypnutá = všechny turnikety vypnuté
    const st = JSON.parse(store.get('lhviz_ts') || 'null');
    if (st) { Object.keys(S.ts).forEach(k => { if (typeof st[k] === 'boolean') S.ts[k] = st[k]; }); }
    else { const sv = JSON.parse(store.get('lhviz_layers') || 'null'); if (sv && sv.doch === false) { Object.keys(S.ts).forEach(k => { S.ts[k] = false; }); } }
  } catch (e) {}
  buildChips();
  try { S.meta = await api('ajax=meta'); } catch (e) { msg('Data se nepodařilo načíst: ' + e.message); return; }
  S.incSel = defaultSel(); packIncidents(); buildLabels(); renderIncCard(); loadPeople();
  const q = new URLSearchParams(location.search);
  if (S.meta.last == null && !S.meta.inc.length) { msg('Zatím tu nejsou žádná data.'); S.v0 = Date.now() / 1000 - 86400; S.v1 = Date.now() / 1000; draw(); return; }
  if (q.get('od') && /^\d{4}-\d{2}-\d{2}$/.test(q.get('od'))) { const a = Date.parse(q.get('od') + 'T00:00:00Z') / 1000, b = q.get('do') && /^\d{4}-\d{2}-\d{2}$/.test(q.get('do')) ? Date.parse(q.get('do') + 'T00:00:00Z') / 1000 + 86400 : a + 86400; setView(a, b, true); }
  else if (q.get('incident')) { showIncident(q.get('incident')); }
  else setDays(mobile() ? 1 : 3);
  if (q.get('incident') && q.get('od')) showIncident(q.get('incident'));
  $('#fOd').value = iso(S.v0); $('#fDo').value = iso(S.v1 - 1);
  if (q.get('view') === 'list' || (mobile() && !q.get('view') && false)) setMode('seznam');
})();
window.__viz = { S, setView, showIncident, draw, passOn, rowsLayout, renderList, hitAt: (x, y) => hitAt(x, y, false), get HIT() { return HIT; } };
})();

