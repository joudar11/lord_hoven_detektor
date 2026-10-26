/* Skripty aplikace Detektor Lorda Hoven (MIT). Soubor vznikl sloučením dřívějších inline skriptů v původním pořadí. */

// Obsluhy událostí z atributů data-* (místo inline onclick/onsubmit; kvůli Content-Security-Policy)
//   data-confirm="text"               formulář nebo tlačítko se před odesláním / kliknutím zeptá
//   data-confirm-if="pole"            ptát se jen když je zaškrtnuté zaškrtávátko „pole“
//   data-confirm-unless="pole:hodnota" neptat se, když má „pole“ danou hodnotu
//   data-autosubmit                   po změně pole se formulář odešle
//   data-check-all="1|0"              tlačítko zaškrtne / odškrtne všechna zaškrtávátka ve formuláři
//   .lh-stop                          klik na prvek se nešíří na rodiče (např. zaškrtávátko uvnitř <summary>)
document.addEventListener('submit', function (e) {
    const f = e.target;
    if (!(f instanceof HTMLFormElement) || !f.dataset.confirm) { return; }
    if (f.dataset.confirmIf) { const c = f.elements[f.dataset.confirmIf]; if (!(c && c.checked)) { return; } }
    if (f.dataset.confirmUnless) { const p = f.dataset.confirmUnless.split(':'); const c = f.elements[p[0]]; if (c && c.value === p[1]) { return; } }
    if (!window.confirm(f.dataset.confirm)) { e.preventDefault(); }
}, true);
document.addEventListener('click', function (e) {
    const b = e.target.closest ? e.target.closest('button[data-confirm], a[data-confirm], input[data-confirm]') : null;
    if (b && !window.confirm(b.dataset.confirm)) { e.preventDefault(); e.stopPropagation(); return; }
    const a = e.target.closest ? e.target.closest('[data-check-all]') : null;
    if (a && a.form) { a.form.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = a.dataset.checkAll === '1'; }); }
});
document.addEventListener('change', function (e) {
    if (e.target.matches && e.target.matches('[data-autosubmit]') && e.target.form) { e.target.form.submit(); }
});
document.querySelectorAll('.lh-stop').forEach(function (el) { el.addEventListener('click', function (ev) { ev.stopPropagation(); }); });

            (function () {   // zvýrazní v přehledu řádek turniketu vybraného ve formuláři
                const sel = document.querySelector('#last-pass-info') && document.querySelector('select[name="turnstile"]');
                if (!sel) { return; }
                const mark = () => document.querySelectorAll('#last-pass-info tr[data-ts]').forEach(r => { const on = r.dataset.ts === sel.value; r.classList.toggle('fw-bold', on); r.style.textDecoration = on ? 'underline' : ''; });
                sel.addEventListener('change', mark); mark();
            })();
        
;
                document.addEventListener('DOMContentLoaded', () => setTimeout(() => {   // až po inicializaci sbalování panelů
                    const tab = document.getElementById('tab-admin');
                    if (tab) { bootstrap.Tab.getOrCreateInstance(tab).show(); }
                    const h = document.querySelector('#pseudo-translator > h3.card-title');
                    if (h && h.getAttribute('aria-expanded') !== 'true') { h.click(); }
                    setTimeout(() => document.getElementById('pseudo-translator').scrollIntoView({ block: 'start' }), 150);
                }, 250));
            
;
document.addEventListener('click', (e) => {
    const ed = e.target.closest('.btn-sighting-edit');
    if (ed) {
        const f = document.getElementById('sighting-edit-form');
        f.elements['id'].value = ed.dataset.id;
        f.elements['person_name'].value = ed.dataset.name || '';
        f.elements['location'].value = ed.dataset.location || '';
        f.elements['direction'].value = ed.dataset.direction || '';
        f.elements['seen_at'].value = ed.dataset.seen || '';
        f.elements['reporter'].value = ed.dataset.reporter || '';
        if (f.elements['note']) { f.elements['note'].value = ed.dataset.note || ''; }
        bootstrap.Modal.getOrCreateInstance(document.getElementById('sighting-edit-modal')).show();
        return;
    }
    const rn = e.target.closest('.btn-sighting-rename');
    if (!rn) return;
    const name = prompt('Jméno osoby (musí odpovídat docházce):', rn.dataset.name || '');
    if (name === null || name.trim() === '') return;
    const f = document.getElementById('sighting-rename-form');
    f.elements['id'].value = rn.dataset.id;
    f.elements['person_name'].value = name;
    f.submit();
});
;
// Admin panely: při načtení sbalené, klepnutím na nadpis (nebo Enter / mezerník) se rozbalí
(function () {
    document.querySelectorAll('#pane-admin .lh-card-admin').forEach((card) => {
        const head = card.querySelector(':scope > h3.card-title');
        if (!head) { return; }
        const body = document.createElement('div');
        body.className = 'lh-panel-body';
        body.hidden = true;
        while (head.nextSibling) { body.appendChild(head.nextSibling); }
        card.appendChild(body);

        // Ve sbaleném panelu zůstane vidět, že se v něm něco čeká nebo je potřeba pozornost
        const pending = /Ke schválení \((\d+)\)/.exec(body.textContent);
        const addBadge = (text, cls) => { const b = document.createElement('span'); b.className = 'badge ' + cls; b.textContent = text; head.appendChild(b); };
        if (pending && Number(pending[1]) > 0) { addBadge(pending[1] + ' ke schválení', 'bg-warning text-dark'); }
        if (body.querySelector('.alert-danger')) { addBadge('chyba', 'bg-danger'); }
        else if (body.querySelector('.alert-warning')) { addBadge('upozornění', 'bg-warning text-dark'); }

        head.classList.add('lh-panel-head');
        head.setAttribute('role', 'button');
        head.setAttribute('tabindex', '0');
        head.setAttribute('aria-expanded', 'false');
        const toggle = () => {
            const open = body.hidden;
            body.hidden = !open;
            head.setAttribute('aria-expanded', open ? 'true' : 'false');
        };
        head.addEventListener('click', toggle);
        head.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
    });
})();

// Přepínač počítání incidentu: při vypnutí se zeptá na nepovinný důvod (vidí ho všichni)
document.addEventListener('change', (e) => {
    const sw = e.target.closest('.inc-switch');
    if (!sw) return;
    const form = sw.closest('form');
    if (!sw.checked) {
        const reason = prompt('Proč se incident nepočítá? (nepovinné, důvod uvidí všichni)', '');
        if (reason === null) { sw.checked = true; return; }
        form.elements['reason'].value = reason;
    }
    form.elements['counted'].value = sw.checked ? '1' : '0';
    form.submit();
});
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.btn-device-label');
    if (!btn) return;
    const label = prompt('Název zařízení (prázdné = smazat):', btn.dataset.label || '');
    if (label === null) return;
    const f = document.getElementById('device-label-form');
    f.elements['device_id'].value = btn.dataset.device;
    f.elements['label'].value = label;
    f.submit();
});
;
document.addEventListener('DOMContentLoaded', () => {
    // Proklik z tabulky časových oken incidentů: otevře záložku Podezřelí, pohled Podle incidentů a danou kartu
    const openIncident = (id) => {
        const tabBtn = document.getElementById('tab-suspects');
        if (tabBtn) bootstrap.Tab.getOrCreateInstance(tabBtn).show();
        const card = document.getElementById('inc-' + id);
        const viewInc = document.getElementById('view-incidents');
        if (!card || !viewInc) return;
        const incBox = document.getElementById('inc-in-' + id);   // nevybraný incident se zaškrtne, aby se jeho karta ukázala
        if (incBox && !incBox.checked) { incBox.checked = true; incBox.dispatchEvent(new Event('change', { bubbles: true })); }
        viewInc.checked = true;
        viewInc.dispatchEvent(new Event('change'));
        card.open = true;
        setTimeout(() => card.scrollIntoView({ behavior: 'smooth', block: 'start' }), 200);
    };
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.inc-open');
        if (btn) openIncident(btn.dataset.incident);
    });

    // Žebříček podezřelých: filtr zdrojů bodů (nahoře / dole / virtuální) + řazení + hlavní podezřelý
    const suspectsBody = document.querySelector('tbody.suspects-body');
    if (suspectsBody) {
        const rows = Array.from(suspectsBody.querySelectorAll('tr.suspect-row'));
        const emptyRow = document.getElementById('suspects-empty');
        const sortHeaders = document.querySelectorAll('th.sortable');
        const fHideInn = document.getElementById('flt-hide-inn');
        // Důvěryhodní (seznam admina): filtr „Skrýt důvěryhodné“ je výchozí zapnutý a skrývá je na stejných místech jako nevinné
        const fHideTrust = document.getElementById('flt-hide-trust');
        const hideTrustOn = () => !!(fHideTrust && fHideTrust.checked);
        let trustedNorm = null;
        const isTrusted = (n) => { if (!trustedNorm) { trustedNorm = new Set((window.LH_TRUSTED || []).map(x => x.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim())); } return trustedNorm.size > 0 && trustedNorm.has(String(n).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim()); };
        const trustBadge = () => { const t = document.createElement('span'); t.className = 'badge bg-secondary ms-1'; t.textContent = 'D'; t.title = 'Důvěryhodný (seznam admina)'; return t; };
        const fHideExcl = document.getElementById('flt-hide-excl');
    const fHideDay = document.getElementById('flt-hide-day');   // skrývá „Během dne ve firmě“ (informace bez bodu) a osoby jen s ní
    const hideDayOn = () => !!(fHideDay && fHideDay.checked);
        const hideExclOn = () => !!(fHideExcl && fHideExcl.checked);
        const fUp = document.getElementById('flt-up');
        const fDown = document.getElementById('flt-down');
        const fVirt = document.getElementById('flt-virt');
        const fWitX = document.getElementById('flt-witx');
        const fWitV = document.getElementById('flt-witv');
        let sortKey = 'points';

        // Přepínač pohledu: Podle osob / Podle incidentů
        const personsView = document.getElementById('persons-view');
        const incidentsView = document.getElementById('incidents-view');
        const viewIncidents = document.getElementById('view-incidents');
        const applyView = () => {
            personsView.classList.toggle('d-none', viewIncidents.checked);
            incidentsView.classList.toggle('d-none', !viewIncidents.checked);
        };
        document.querySelectorAll('input[name="susp-view"]').forEach(v => v.addEventListener('change', applyView));
        const pointsByName = {};

        // Maska zdrojů každého incidentu osoby: 1 = nahoře, 2 = dole, 4 = virtuální
        rows.forEach(r => {
            r._inc = r.dataset.inc ? r.dataset.inc.split(',').map(Number) : [];
            r._ids = r.dataset.incids ? r.dataset.incids.split(',').map(Number) : [];
        });

        // Výběr incidentů (jen pokud je zapnutý): výchozí stav = počítané incidenty, nic se neukládá
        const incBoxes = Array.from(document.querySelectorAll('.inc-include'));
        incBoxes.forEach(b => { b.checked = b.defaultChecked; });
        let includedSet = null;
        const isIn = (id) => !includedSet || includedSet.has(id);

        // Nevinní: seznam podle výběru incidentů (stejná logika jako výpočet podezřelých; bez výběru jen počítané incidenty)
        const innocentsBox = document.getElementById('innocents-box');
        // Příchod po incidentu: klasifikace podle posuvníku, režimu a vybraných incidentů (stejná logika výběru jako u podezřelých)
        const arrData = window.LH_ARR || null;
        const arrBox = document.getElementById('arrival-box');
        const arrState = { excluded: new Set() };   // normalizovaná jména vyloučená příchodem (značka P, skrývání)
        const normNm = x => x.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
        const arrStore = { get: k => { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} } };
        let arrX = arrData ? Math.max(1, Math.min(120, parseInt(arrStore.get('lh_arr_x'), 10) || arrData.tol || 1)) : 1;
        let arrMode = arrStore.get('lh_arr_mode') === 'all' ? 'all' : 'any';
        const ARR_LISTS = ['after', 'near', 'before', 'none', 'it'];
        const renderArrival = () => {
            arrState.excluded = new Set();
            if (!arrData || !arrBox) { return; }
            const tol = arrData.tol, thr = Math.max(arrX, tol);
            const sel = [];
            arrData.inc.forEach((inc, k) => { if (includedSet ? includedSet.has(inc.id) : inc.c === 1) { sel.push(k); } });
            const usable = sel.filter(k => arrData.inc[k].cov === 1);
            const eff = document.getElementById('arr-eff');
            eff.textContent = 'Práh příchodu po nálezu: ' + thr + ' min' + (arrX < tol ? ' (nastavená rezerva ' + arrX + ' min je kratší než tolerance ' + tol + ' min, která platí pro body, proto platí tolerance)' : ' (tolerance nastavená adminem: ' + tol + ' min)');
            const note = document.getElementById('arr-note');
            const skipped = sel.length - usable.length;
            note.classList.toggle('d-none', skipped === 0 && usable.length > 0);
            note.textContent = usable.length === 0 ? 'Pro vybrané incidenty chybí docházka (za den nálezu nebo začátku okna), příchody nelze vyhodnotit.' : (skipped > 0 ? 'U ' + skipped + ' z ' + sel.length + ' vybraných incidentů chybí docházka (nebo začátek okna), proto se do vyhodnocení nepočítají.' : '');
            // nevinní u vybraných incidentů (značka N)
            const innBy = new Map();
            (window.LH_INNOCENTS || []).forEach(e => {
                if (includedSet ? !includedSet.has(e.i) : e.c !== 1) { return; }
                const k = normNm(e.n);
                if (!innBy.has(k)) { innBy.set(k, []); }
                innBy.get(k).push(e.no);
            });
            const lists = { after: [], near: [], before: [], none: [], it: [] };
            const hideInnOn = !!(fHideInn && fHideInn.checked);
            arrData.p.forEach(p => {
                const key = normNm(p.n), inn = innBy.get(key) || null;
                if (p.it) { lists.it.push({ p, key, inn, st: [], margin: 0 }); return; }
                if (usable.length === 0) { return; }
                const st = usable.map(k => {
                    const v = p.v[k], no = arrData.inc[k].no;
                    if (v === null) { return { c: 'N', no }; }
                    if (v[1]) { return { c: 'V', no }; }
                    if (v[0] !== null && v[0] >= thr) { return { c: 'A', no, d: v[0] }; }
                    if (v[0] !== null && v[0] > 0) { return { c: 'B', no, d: v[0] }; }
                    return { c: 'D', no, d: v[0] };
                });
                const cls = st.map(x => x.c), hasA = cls.includes('A');
                const excluded = arrMode === 'any' ? hasA : (hasA && cls.every(c => c === 'A' || c === 'N'));
                let which;
                if (excluded) { which = 'after'; }
                else if (cls.every(c => c === 'N')) { which = 'none'; }
                else if (cls.includes('B') && cls.every(c => c === 'B' || c === 'N')) { which = 'near'; }
                else { which = 'before'; }
                const ds = st.filter(x => x.c === (which === 'after' ? 'A' : 'B')).map(x => x.d);
                lists[which].push({ p, key, inn, st, margin: ds.length ? Math.min(...ds) : 0 });
            });
            lists.after.forEach(x => arrState.excluded.add(x.key));
            const label = { A: 'po', B: 'těsně po', V: 'v okně', D: 'přišel dřív, v okně bez průchodu', N: 'bez průchodu' };
            ARR_LISTS.forEach(name => {
                const box = document.getElementById('arr-l-' + name), det = document.getElementById('arr-d-' + name);
                box.textContent = '';
                let arr = lists[name];
                if (hideInnOn) { arr = arr.filter(x => !x.inn); }
                if (hideTrustOn()) { arr = arr.filter(x => !isTrusted(x.p.n)); }
                if (name === 'after' || name === 'near') { arr = arr.slice().sort((a, b) => (a.margin - b.margin) || a.p.n.localeCompare(b.p.n, 'cs')); }
                else { arr = arr.slice().sort((a, b) => a.p.n.localeCompare(b.p.n, 'cs')); }
                arr.forEach(x => {
                    const row = document.createElement('div');
                    row.className = 'arr-person';
                    row.style.cursor = 'pointer';
                    const nm = document.createElement('span');
                    nm.textContent = x.p.n;
                    row.appendChild(nm);
                    if (x.inn) {
                        const t = document.createElement('span');
                        t.className = 'badge bg-success ms-1';
                        t.textContent = 'N';
                        t.title = 'Nevinný u ' + x.inn.slice().sort((a, b) => a - b).map(n => '#' + n).join(', ');
                        row.appendChild(t);
                    }
                    if (isTrusted(x.p.n)) { row.appendChild(trustBadge()); }
                    if (name === 'after' || name === 'near') {
                        const m = document.createElement('span');
                        m.className = 'small text-body-secondary ms-1';
                        m.textContent = '(nejméně +' + x.margin + ' min)';
                        row.appendChild(m);
                    }
                    if (x.st.length) {
                        const det2 = document.createElement('div');
                        det2.className = 'small text-body-secondary d-none ms-3';
                        det2.textContent = x.st.slice().sort((a, b) => a.no - b.no).map(s => '#' + s.no + ' ' + label[s.c] + (s.d != null && (s.c === 'A' || s.c === 'B') ? ' (+' + s.d + ' min)' : '')).join(' · ');
                        row.appendChild(det2);
                        row.addEventListener('click', () => det2.classList.toggle('d-none'));
                    }
                    box.appendChild(row);
                });
                document.getElementById('arr-n-' + name).textContent = arr.length;
                if (name === 'it') { det.classList.toggle('d-none', lists.it.length === 0); }
            });
        };
        const arrSync = () => {
            if (!arrData || !arrBox) { return; }
            ['arr-x', 'arr-x-num'].forEach(id => { const el = document.getElementById(id); if (el) { el.value = arrX; } });
            const ra = document.getElementById('arr-mode-' + arrMode); if (ra) { ra.checked = true; }
        };
        if (arrData && arrBox) {
            const setX = v => { arrX = Math.max(1, Math.min(120, parseInt(v, 10) || 1)); arrStore.set('lh_arr_x', String(arrX)); arrSync(); refresh(); };
            document.getElementById('arr-x').addEventListener('input', e => setX(e.target.value));
            document.getElementById('arr-x-num').addEventListener('change', e => setX(e.target.value));
            document.getElementById('arr-minus').addEventListener('click', () => setX(arrX - 1));
            document.getElementById('arr-plus').addEventListener('click', () => setX(arrX + 1));
            document.querySelectorAll('input[name="arr-mode"]').forEach(r => r.addEventListener('change', () => { arrMode = r.value; arrStore.set('lh_arr_mode', arrMode); refresh(); }));
            arrSync();
        }

        // Není člověk, není problém: osoba chyběla celý den (žádný průchod) a v takové dny se nestal žádný z vybraných incidentů; zároveň byla přítomna u každého vyhodnoceného incidentu
        const absData = window.LH_ABS || null;
        const absBox = document.getElementById('absence-box');
        let absFrom = arrStore.get('lh_abs_from') || '08:00', absTo = arrStore.get('lh_abs_to') || '12:00';
        const hhmmMin = x => { const m = /^(\d{1,2}):(\d{2})$/.exec(x || ''); return m ? Math.min(1439, (+m[1]) * 60 + (+m[2])) : null; };
        const absPresent = absData ? absData.p.map(() => new Set()) : [];
        if (absData) { absData.pres.forEach((arr, di) => arr.forEach(pi => absPresent[pi].add(di))); }
        const dmy = di => absData.days[di].d.slice(8, 10) + '-' + absData.days[di].d.slice(5, 7);
        const renderAbsence = () => {
            if (!absData || !absBox) { return; }
            const fm = hhmmMin(absFrom) ?? 480, tm = hhmmMin(absTo) ?? 720;
            const days = absData.days;
            const dayOk = days.map(d => d.m === 1 && d.f <= fm && d.l >= tm);   // úplný den: docházka pokrývá ráno i dopoledne a kabinky se kontrolovaly
            const usable = []; dayOk.forEach((ok, i) => { if (ok) { usable.push(i); } });
            const sel = absData.inc.filter(i => includedSet ? includedSet.has(i.id) : i.c === 1);
            const uinc = sel.filter(i => i.ok === 1 && i.days.length > 0 && i.days.every(k => dayOk[k]));
            const incDays = new Set(); uinc.forEach(i => i.days.forEach(k => incDays.add(k)));
            const evid = usable.filter(k => !incDays.has(k));   // úplné dny bez vybraného incidentu
            const discDays = new Set(uinc.map(i => i.e));
            const box = document.getElementById('abs-list'); box.textContent = '';
            const note = document.getElementById('abs-note'), info = document.getElementById('abs-info');
            const skippedInc = sel.length - uinc.length;
            note.classList.toggle('d-none', !(sel.length > 0 && (skippedInc > 0 || uinc.length === 0)));
            note.textContent = uinc.length === 0 ? 'Pro vybrané incidenty není úplná docházka (nebo žádný incident není vybrán), srovnání nelze udělat.' : (skippedInc > 0 ? 'U ' + skippedInc + ' z ' + sel.length + ' vybraných incidentů není úplná docházka, proto se nepočítají.' : '');
            info.textContent = 'Úplných dnů s kontrolou kabinek: ' + usable.length + ' z ' + days.length + ' (dnů s incidenty: ' + incDays.size + ', dnů bez incidentu: ' + evid.length + ').';
            const innBy = new Map();
            (window.LH_INNOCENTS || []).forEach(e => {
                if (includedSet ? !includedSet.has(e.i) : e.c !== 1) { return; }
                const k = normNm(e.n);
                if (!innBy.has(k)) { innBy.set(k, []); }
                innBy.get(k).push(e.no);
            });
            const hideInnOn = !!(fHideInn && fHideInn.checked);
            const res = [];
            if (uinc.length > 0 && evid.length > 0) {
                absData.p.forEach((name, pi) => {
                    const pr = absPresent[pi];
                    if (uinc.some(i => !i.days.some(k => pr.has(k)))) { return; }   // incident se stal v den, kdy osoba celý den chyběla
                    const absentEv = evid.filter(k => !pr.has(k));
                    if (absentEv.length === 0) { return; }   // bez chybějícího dne není co srovnávat
                    const f = usable.filter(k => pr.has(k)).length / usable.length;
                    const inn = innBy.get(normNm(name)) || null;
                    res.push({ name, absentEv, inn, chance: Math.pow(f, Math.max(1, discDays.size)) });
                });
            }
            let shownList = hideInnOn ? res.filter(x => !x.inn) : res;
            if (hideTrustOn()) { shownList = shownList.filter(x => !isTrusted(x.name)); }
            shownList = shownList.slice().sort((a, b) => (a.chance - b.chance) || a.name.localeCompare(b.name, 'cs'));
            shownList.forEach(x => {
                const row = document.createElement('div');
                row.className = 'arr-person';
                row.style.cursor = 'pointer';
                const nm = document.createElement('span'); nm.textContent = x.name; row.appendChild(nm);
                if (x.inn) {
                    const t = document.createElement('span'); t.className = 'badge bg-success ms-1'; t.textContent = 'N';
                    t.title = 'Nevinný u ' + x.inn.slice().sort((a, b) => a - b).map(n => '#' + n).join(', '); row.appendChild(t);
                }
                if (isTrusted(x.name)) { row.appendChild(trustBadge()); }
                const m = document.createElement('span'); m.className = 'small text-body-secondary ms-1';
                m.textContent = '(chyběl ' + x.absentEv.length + ' ' + (x.absentEv.length === 1 ? 'den' : (x.absentEv.length < 5 ? 'dny' : 'dní')) + ' bez incidentu)'; row.appendChild(m);
                const det = document.createElement('div'); det.className = 'small text-body-secondary d-none ms-3';
                const pct = x.chance * 100;
                det.textContent = 'Chyběl: ' + x.absentEv.map(dmy).join(', ') + ' · přítomen u všech ' + uinc.length + ' vyhodnocených incidentů (#' + uinc.map(i => i.no).sort((a, b) => a - b).join(', #') + ') · orientačně: náhodou by to nastalo s pravděpodobností ≈ ' + (pct < 1 ? '< 1' : Math.round(pct)) + ' %';
                row.appendChild(det);
                row.addEventListener('click', () => det.classList.toggle('d-none'));
                box.appendChild(row);
            });
            document.getElementById('abs-n').textContent = shownList.length;
            if (shownList.length === 0) { const e = document.createElement('div'); e.className = 'text-body-secondary'; e.textContent = uinc.length === 0 ? '' : (evid.length === 0 ? 'Není žádný úplný den bez vybraného incidentu, takže není s čím srovnávat. Nahraj docházku i za dny bez incidentů.' : 'Žádná osoba nesplňuje podmínku.'); box.appendChild(e); }
        };
        // Ve firmě u každého incidentu: osoby s aspoň jedním průchodem v den okna každého vybraného incidentu (s úplnou docházkou)
        const presBox = document.getElementById('present-box');
        const renderPresent = () => {
            if (!absData || !presBox) { return; }
            const sel = absData.inc.filter(i => includedSet ? includedSet.has(i.id) : i.c === 1);
            const ev = sel.filter(i => i.ok === 1 && i.days.length > 0 && absData.days[i.days[i.days.length - 1]].l >= i.em);   // docházka dne nálezu sahá aspoň do nálezu
            const note = document.getElementById('pres-note'), info = document.getElementById('pres-info'), box = document.getElementById('pres-list');
            box.textContent = '';
            const skipped = sel.length - ev.length;
            note.classList.toggle('d-none', !(sel.length > 0 && (skipped > 0 || ev.length === 0)));
            note.textContent = ev.length === 0 ? 'Pro vybrané incidenty není úplná docházka (nebo žádný incident není vybrán), seznam nelze sestavit.' : (skipped > 0 ? 'U ' + skipped + ' z ' + sel.length + ' vybraných incidentů není úplná docházka, proto se nepočítají.' : '');
            info.textContent = ev.length ? 'Vyhodnoceno ' + ev.length + ' ' + (ev.length === 1 ? 'incident' : (ev.length < 5 ? 'incidenty' : 'incidentů')) + ' (#' + ev.map(i => i.no).sort((a, b) => a - b).join(', #') + ').' : '';
            const innBy = new Map();
            (window.LH_INNOCENTS || []).forEach(e => {
                if (includedSet ? !includedSet.has(e.i) : e.c !== 1) { return; }
                const k = normNm(e.n);
                if (!innBy.has(k)) { innBy.set(k, []); }
                innBy.get(k).push(e.no);
            });
            const hideInnOn = !!(fHideInn && fHideInn.checked);
            let res = ev.length ? absData.p.map((name, pi) => ({ name, pi, inn: innBy.get(normNm(name)) || null })).filter(x => ev.every(i => i.days.some(k => absPresent[x.pi].has(k)))) : [];
            if (hideInnOn) { res = res.filter(x => !x.inn); }
            if (hideTrustOn()) { res = res.filter(x => !isTrusted(x.name)); }
            res.sort((a, b) => a.name.localeCompare(b.name, 'cs'));
            res.forEach(x => {
                const row = document.createElement('div');
                row.className = 'arr-person';
                const nm = document.createElement('span'); nm.textContent = x.name; row.appendChild(nm);
                if (x.inn) {
                    const t = document.createElement('span'); t.className = 'badge bg-success ms-1'; t.textContent = 'N';
                    t.title = 'Nevinný u ' + x.inn.slice().sort((a, b) => a - b).map(n => '#' + n).join(', '); row.appendChild(t);
                }
                if (isTrusted(x.name)) { row.appendChild(trustBadge()); }
                box.appendChild(row);
            });
            document.getElementById('pres-n').textContent = res.length;
            if (res.length === 0 && ev.length > 0) { const e = document.createElement('div'); e.className = 'text-body-secondary'; e.textContent = 'Žádná osoba nebyla ve firmě u všech vybraných incidentů.'; box.appendChild(e); }
        };
        if (absData && absBox) {
            const setT = (id, key, setter) => { const el = document.getElementById(id); if (el) { el.value = key === 'from' ? absFrom : absTo; el.addEventListener('change', () => { if (hhmmMin(el.value) === null) { el.value = key === 'from' ? absFrom : absTo; return; } setter(el.value); refresh(); }); } };
            setT('abs-from', 'from', v => { absFrom = v; arrStore.set('lh_abs_from', v); });
            setT('abs-to', 'to', v => { absTo = v; arrStore.set('lh_abs_to', v); });
        }

        const renderInnocents = () => {
            if (!innocentsBox) { return; }
            const byName = new Map();
            (window.LH_INNOCENTS || []).forEach(e => {
                if (includedSet ? !includedSet.has(e.i) : e.c !== 1) { return; }
                if (!byName.has(e.n)) { byName.set(e.n, []); }
                byName.get(e.n).push(e);
            });
            const list = document.getElementById('innocents-list');
            list.textContent = '';
            [...byName.keys()].sort((a, b) => a.localeCompare(b, 'cs')).forEach(name => {
                const line = document.createElement('div');
                const strong = document.createElement('strong');
                strong.textContent = name;
                line.appendChild(strong);
                line.appendChild(document.createTextNode(': '));
                byName.get(name).sort((a, b) => a.no - b.no).forEach((e, idx) => {
                    if (idx) { line.appendChild(document.createTextNode(', ')); }
                    const sp = document.createElement('span');
                    sp.className = 'lh-innoc-no';
                    sp.textContent = '#' + e.no;
                    sp.title = e.r || 'N/A';
                    sp.addEventListener('click', () => sp.classList.toggle('show-reason'));
                    line.appendChild(sp);
                });
                list.appendChild(line);
            });
            document.getElementById('innocents-n').textContent = byName.size;
            innocentsBox.classList.toggle('d-none', byName.size === 0);
            // Odznak „Nevinný“ u jmen v žebříčku (počet podle vybraných incidentů, v titulku čísla incidentů)
            const normName = (x) => x.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
            const byNorm = new Map();
            byName.forEach((arr, nm) => byNorm.set(normName(nm), arr));
            document.querySelectorAll('tr.suspect-row').forEach(r => {
                const badge = r.querySelector('.innocent-badge');
                if (!badge) { return; }
                const arr = byNorm.get(normName(r.dataset.name || ''));
                if (arr) {
                    badge.textContent = 'Nevinný' + (arr.length > 1 ? ' ×' + arr.length : '');
                    badge.title = 'Nevinný u ' + arr.map(e => e.no).sort((a, b) => a - b).map(n => '#' + n).join(', ');
                }
                badge.classList.toggle('d-none', !arr);
            });

            // Osoby bez alibi: zaměstnanci, kteří u vybraných incidentů nemají zadanou žádnou nevinnost (bez ohledu na body)
            const noAlibiBox = document.getElementById('noalibi-box');
            if (noAlibiBox) {
                const incidentsCount = includedSet ? includedSet.size : (window.LH_COUNTED_N || 0);
                const none = (window.LH_EMPLOYEES || []).filter(n => !byNorm.has(normName(n))).filter(n => !(hideExclOn() && arrState.excluded.has(normName(n)))).sort((a, b) => a.localeCompare(b, 'cs'));
                const nl = document.getElementById('noalibi-list');
                nl.textContent = '';
                none.forEach(n => {
                    const row = document.createElement('div');
                    row.textContent = n;
                    if (arrState.excluded.has(normName(n))) {   // vyloučen příchodem po incidentu: jen značka, nejde o alibi
                        const t = document.createElement('span');
                        t.className = 'badge bg-secondary ms-1';
                        t.textContent = 'P';
                        t.title = 'Vyloučen příchodem po incidentu (nejde o alibi)';
                        row.appendChild(t);
                    }
                    nl.appendChild(row);
                });
                document.getElementById('noalibi-n').textContent = none.length;
                noAlibiBox.classList.toggle('d-none', incidentsCount === 0 || none.length === 0);
            }
        };

        // Statistiky a předpověď podle výběru incidentů (dotáhne se ze serveru, výchozí stav se vrací bez dotazu)
        const statsBox = document.getElementById('stats-content');
        const statsInitial = statsBox ? statsBox.innerHTML : '';
        let statsLast = 'default';
        let statsSeq = 0;
        let statsTimer = null;
        const updateStats = () => {
            if (!statsBox || !includedSet) { return; }
            const isDefault = incBoxes.every(b => b.checked === b.defaultChecked);
            const ids = [...includedSet].sort((a, b) => a - b).join(',');
            const key = isDefault ? 'default' : (ids || 'none');
            if (key === statsLast) { return; }
            statsLast = key;
            clearTimeout(statsTimer);
            statsTimer = setTimeout(() => {
                const seq = ++statsSeq;
                if (key === 'default') { statsBox.innerHTML = statsInitial; return; }
                fetch(window.location.pathname + '?ajax=stats&ids=' + encodeURIComponent(key === 'none' ? '' : key))
                    .then(r => r.ok ? r.text() : Promise.reject())
                    .then(html => { if (seq === statsSeq) { statsBox.innerHTML = html; } })
                    .catch(() => {});
            }, 350);
        };

        // Pohled Podle incidentů: karty jen pro incidenty vybrané v časových oknech (bez výběru platí počítané incidenty, které server vykreslil)
        const incCards = Array.from(document.querySelectorAll('#incidents-view details.inc-card'));
        const incCardsEmpty = document.getElementById('inc-cards-empty');
        const updateCards = () => {
            incCards.forEach(c => { c.classList.toggle('d-none', !!includedSet && !includedSet.has(Number(c.id.replace('inc-', '')))); });
            if (incCardsEmpty) { incCardsEmpty.classList.toggle('d-none', !(includedSet && includedSet.size === 0)); }
        };

        // Počítadlo dnů od posledního incidentu: nejnovější incident z výběru (zaškrtnuté „Zahrnout do výpočtu“, štítky); bez výběru počítané incidenty
        const sinceBox = document.getElementById('days-since'), sinceData = window.LH_SINCE || null;
        const dayNum = x => Date.UTC(+x.slice(0, 4), +x.slice(5, 7) - 1, +x.slice(8, 10)) / 86400000;
        // Statistika období bez incidentu z vybraných incidentů: odstupy mezi incidenty (kalendářní dny) + probíhající období; nejdelší, průměr a mini graf
        const sinceStats = document.getElementById('days-stats');
        const dnu = n => n === 1 ? 'den' : (n >= 2 && n <= 4 ? 'dny' : 'dní');
        const dm = x => x.slice(8, 10) + '-' + x.slice(5, 7);
        const dmy2 = x => x.slice(8, 10) + '-' + x.slice(5, 7) + '-' + x.slice(0, 4);
        const renderSinceStats = (pool, days, last) => {
            if (!sinceStats) { return; }
            sinceStats.textContent = '';
            if (!pool || pool.length === 0) { sinceStats.classList.add('d-none'); sinceStats.classList.remove('d-flex'); return; }
            const asc = pool.slice().sort((a, b) => a.e.localeCompare(b.e) || a.id - b.id);
            const gaps = [];
            for (let k = 1; k < asc.length; k++) { gaps.push({ a: asc[k - 1].e.slice(0, 10), b: asc[k].e.slice(0, 10), d: dayNum(asc[k].e) - dayNum(asc[k - 1].e), cur: false }); }
            const all = gaps.concat([{ a: last.e.slice(0, 10), b: sinceData.today, d: days, cur: true }]);
            let longest = all[0]; all.forEach(g => { if (g.d > longest.d) { longest = g; } });
            const avg = gaps.length ? gaps.reduce((t, g) => t + g.d, 0) / gaps.length : null;
            const t1 = document.createElement('span');
            t1.textContent = 'Nejdelší období bez incidentu: ' + longest.d + ' ' + dnu(longest.d) + ' (' + (longest.cur ? 'od ' + dm(longest.a) + ', probíhá' : dm(longest.a) + ' až ' + dm(longest.b)) + ')';
            const t2 = document.createElement('span');
            t2.textContent = 'Průměr mezi incidenty: ' + (avg === null ? '–' : avg.toFixed(1).replace('.', ',') + ' ' + dnu(Math.round(avg)) + ' (z ' + gaps.length + ' ' + (gaps.length === 1 ? 'odstupu' : 'odstupů') + ')');
            sinceStats.appendChild(t1); sinceStats.appendChild(t2);   // oddělení položek zajišťuje mezera (gap), zalomí se čistě
            const shown = all.slice(-30), maxD = Math.max(1, ...shown.map(g => g.d)), W = 5, GAP = 1, H = 24;
            const ns = 'http://www.w3.org/2000/svg', svg = document.createElementNS(ns, 'svg');
            svg.setAttribute('width', shown.length * (W + GAP)); svg.setAttribute('height', H + 2); svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'Délka období bez incidentu mezi incidenty'); svg.classList.add('ms-1'); svg.id = 'days-chart';
            shown.forEach((g, k) => {
                const h = Math.max(1, Math.round(g.d / maxD * H)), r = document.createElementNS(ns, 'rect');
                r.setAttribute('x', k * (W + GAP)); r.setAttribute('y', H + 1 - h); r.setAttribute('width', W); r.setAttribute('height', h); r.setAttribute('data-d', g.d);
                r.setAttribute('fill', g.cur ? 'var(--bs-success, #198754)' : (g === longest ? 'var(--bs-warning, #ffc107)' : 'var(--bs-secondary-color, #6c757d)')); r.setAttribute('opacity', g.cur || g === longest ? '1' : '.65');
                const tt = document.createElementNS(ns, 'title'); tt.textContent = dmy2(g.a) + ' → ' + (g.cur ? 'dnes' : dmy2(g.b)) + ': ' + g.d + ' ' + dnu(g.d) + (g.cur ? ' (probíhá)' : '');
                r.appendChild(tt); svg.appendChild(r);
            });
            sinceStats.appendChild(svg);
            sinceStats.classList.remove('d-none'); sinceStats.classList.add('d-flex');
        };
        const renderSince = () => {
            if (!sinceBox || !sinceData) { return; }
            const all = sinceData.inc, dflt = all.filter(i => i.k === 1);
            const pool = includedSet ? all.filter(i => includedSet.has(i.id)) : dflt;
            const custom = !!includedSet && (pool.length !== dflt.length || pool.some(i => i.k !== 1));   // výběr se liší od výchozího (počítané incidenty)
            sinceBox.textContent = '';
            if (pool.length === 0) {
                sinceBox.textContent = custom ? 'Dnů od posledního incidentu: – (žádný incident není ve výběru)' : 'Zatím nebyl zaznamenán žádný incident.';
                renderSinceStats([], 0, null);
                return;
            }
            const last = pool.slice().sort((a, b) => b.e.localeCompare(a.e) || b.id - a.id)[0];
            const days = Math.max(0, dayNum(sinceData.today) - dayNum(last.e));
            sinceBox.appendChild(document.createTextNode('Dnů od posledního incidentu: '));
            const st = document.createElement('strong'); st.className = 'text-body'; st.textContent = days; sinceBox.appendChild(st);
            const sp = document.createElement('span'); sp.textContent = ' (#' + last.no + ' · ' + last.t + ' · ' + last.c + ')' + (custom ? ' · podle vybraných incidentů' : ''); sinceBox.appendChild(sp);
            renderSinceStats(pool, days, last);
        };

        const refresh = () => {
            const mask = (fUp.checked ? 1 : 0) | (fDown.checked ? 2 : 0) | (fVirt.checked ? 4 : 0) | (fWitX.checked ? 8 : 0) | (fWitV.checked ? 16 : 0);
            includedSet = incBoxes.length ? new Set(incBoxes.filter(b => b.checked).map(b => Number(b.dataset.incident))) : null;
            renderArrival();
            renderAbsence();
            renderPresent();
            renderSince();
            renderInnocents();
            updateStats();
            updateCards();

            // Bod za incident je jeden, bez ohledu na počet průchodů
            rows.forEach(r => {
                let pts = 0, up = 0, down = 0, virt = 0, wx = 0, wv = 0, n = 0, day = 0;
                r._inc.forEach((m, i) => {
                    if (!isIn(r._ids[i])) return;
                    n++;
                    if (m === 64) { day++; }   // jen „Během dne ve firmě“: informace bez bodu
                    if (m & mask) pts++;
                    if (m & 1) up++;
                    if (m & 2) down++;
                    if (m & 4) virt++;
                    if (m & 8) wx++;
                    if (m & 16) wv++;
                });
                Object.assign(r.dataset, { points: pts, up: up, down: down, virt: virt, wit: wx * 1000 + wv, day: day });
                pointsByName[r.dataset.name] = pts;
                r.querySelector('.points-badge').textContent = pts + ' B';
                r.querySelector('td[data-label="Nahoře"] .badge').textContent = up;
                r.querySelector('td[data-label="Dole"] .badge').textContent = down;
                r.querySelector('td[data-label="Virtuální"] .badge').textContent = virt;
                r.querySelector('td[data-label="Svědectví"] .src-wit').textContent = wx;
                r.querySelector('td[data-label="Svědectví"] .src-wit2').textContent = wv;
                r.querySelectorAll('[data-mask]').forEach(d => {
                    const isDay = Number(d.dataset.mask) === 64 && !d.dataset.innocent;   // záznam „Nevinný“ je údaj od admina, filtr ho neskrývá
                    d.classList.toggle('opacity-50', !d.dataset.innocent && !isDay && (Number(d.dataset.mask) & mask) === 0);
                    d.classList.toggle('d-none', !isIn(Number(d.dataset.incid)) || (isDay && hideDayOn()));
                });
                const detSum = r.querySelector('.lh-detail summary');   // v náhledu bez průchodů detail chybí
                if (detSum) { detSum.textContent = 'Detail průchodů (' + r.querySelectorAll('[data-mask]:not(.d-none)').length + ')'; }
                // Zvýraznění průchodů, za které osoba dostává bod podle aktivního filtru
                r.querySelectorAll('.pass-chip').forEach(c => {
                    c.classList.toggle('pass-active', (Number(c.dataset.src) & mask) !== 0);
                });
            });

            // Filtr „Skrýt nevinné“: skryje osoby s odznakem Nevinný (podle vybraných incidentů); štítky Podezřelý a jejich počet se pak počítají nad zbylými
            const hideInn = !!(fHideInn && fHideInn.checked);
            const isInnocentRow = r => { const b = r.querySelector('.innocent-badge'); return !!b && !b.classList.contains('d-none'); };
            const hideExcl = hideExclOn();
            // řádek se ukáže s body, nebo bez bodů jen kvůli informaci „Během dne ve firmě“ (u vybraných incidentů), pokud ji filtr neskrývá
            const hideTrust = hideTrustOn();
            rows.forEach(r => { const tb = r.querySelector('.trusted-badge'); if (tb) { tb.classList.toggle('d-none', !isTrusted(r.dataset.name || '')); } });
            // označený pachatel zůstává v žebříčku vždy (filtry nevinní, důvěryhodní a vyloučení příchodem ho neskrývají)
            const shown = r => (Number(r.dataset.points) > 0 || (!hideDayOn() && Number(r.dataset.day) > 0)) && (r.dataset.culprit === '1' || (!(hideInn && isInnocentRow(r)) && !(hideTrust && isTrusted(r.dataset.name || '')) && !(hideExcl && arrState.excluded.has(normNm(r.dataset.name || '')))));
            const visible = rows.filter(shown);
            const hidden = rows.filter(r => !shown(r));
            visible.sort((a, b) =>
                (b.dataset[sortKey] - a.dataset[sortKey]) ||
                (b.dataset.points - a.dataset.points) ||
                a.dataset.name.localeCompare(b.dataset.name, 'cs'));

            visible.forEach(r => { r.classList.remove('d-none'); suspectsBody.appendChild(r); });
            hidden.forEach(r => { r.classList.add('d-none'); suspectsBody.appendChild(r); });
            emptyRow.classList.toggle('d-none', visible.length > 0);
            suspectsBody.appendChild(emptyRow);

            // Štítek podle aktivního řazení: jediný vedoucí = Hlavní podezřelý, při shodě na prvním místě mají štítek Podezřelý všichni z nich
            rows.forEach(r => {
                r.classList.remove('suspect-rank-1', 'fw-bold');
                r.querySelector('.main-badge').classList.add('d-none');
            });
            const topVal = visible.length ? Number(visible[0].dataset[sortKey]) : 0;
            if (topVal > 0) {
                const leaders = visible.filter(r => Number(r.dataset[sortKey]) === topVal);
                leaders.forEach(r => {
                    r.classList.add('suspect-rank-1', 'fw-bold');
                    const badge = r.querySelector('.main-badge');
                    badge.querySelector('.badge').textContent = leaders.length === 1 ? 'HLAVNÍ PODEZŘELÝ' : 'PODEZŘELÝ';
                    badge.classList.remove('d-none');
                });
            }
            // Počet podezřelých v záhlaví žebříčku: stejné pravidlo jako štítky, ukáže se jen při shodě na prvním místě
            const countEl = document.getElementById('suspects-count');
            if (countEl) {
                const n = topVal > 0 ? visible.filter(r => Number(r.dataset[sortKey]) === topVal).length : 0;
                countEl.classList.toggle('d-none', n <= 1);
                countEl.querySelector('strong').textContent = n;
            }

            // Pohled Podle incidentů: osoby v kartách podle aktivního filtru a bodů
            document.querySelectorAll('details.inc-card:not(.inc-nowindow):not(.inc-ignored)').forEach(card => {
                const people = Array.from(card.querySelectorAll('.inc-person'));
                people.forEach(p => {
                    p._q = (Number(p.dataset.mask) & mask) !== 0;
                    p._pts = pointsByName[p.dataset.person] || 0;
                    p.classList.toggle('opacity-50', !p._q);
                    p.querySelector('.inc-person-pts').textContent = p._pts + ' B';
                    p.querySelectorAll('.pass-chip').forEach(c => {
                        c.classList.toggle('pass-active', (Number(c.dataset.src) & mask) !== 0);
                    });
                });
                const list = card.querySelector('.inc-people');
                people.sort((a, b) =>
                    (b._q - a._q) || (b._pts - a._pts) ||
                    a.dataset.person.localeCompare(b.dataset.person, 'cs')
                ).forEach(p => list.appendChild(p));

                const n = people.filter(p => p._q).length;
                card.querySelector('.inc-count').textContent = n + (n === 1 ? ' podezřelý' : (n >= 2 && n <= 4 ? ' podezřelí' : ' podezřelých'));
                card.querySelector('.inc-single').classList.toggle('d-none', n !== 1);
            });
        };

        [fUp, fDown, fVirt, fWitX, fWitV, ...incBoxes, ...(fHideInn ? [fHideInn] : []), ...(fHideTrust ? [fHideTrust] : []), ...(fHideExcl ? [fHideExcl] : []), ...(fHideDay ? [fHideDay] : [])].forEach(f => f.addEventListener('change', refresh));

        // Štítky incidentů: vybrané štítky zahrnou do výpočtu jen incidenty s nimi (i vyřazené adminem), bez výběru platí výchozí stav
        const labelBtns = Array.from(document.querySelectorAll('.lh-label-filter'));
        if (labelBtns.length && incBoxes.length) {
            // Výběr se řídí zvoleným režimem: sjednocení (výchozí) = incident má alespoň jeden z vybraných štítků, průnik = má všechny vybrané štítky
            const applyLabels = () => {
                const sel = labelBtns.filter(b => b.getAttribute('aria-pressed') === 'true').map(b => b.dataset.label);
                const inter = !!document.getElementById('label-mode-inter')?.checked;
                incBoxes.forEach(b => {
                    const has = (b.dataset.labels || '').split(',');
                    b.checked = sel.length === 0 ? b.defaultChecked : (inter ? sel.every(id => has.includes(id)) : sel.some(id => has.includes(id)));
                });
                refresh();
            };
            labelBtns.forEach(btn => btn.addEventListener('click', () => {
                btn.setAttribute('aria-pressed', btn.getAttribute('aria-pressed') === 'true' ? 'false' : 'true');
                applyLabels();
            }));
            document.querySelectorAll('input[name="label-mode"]').forEach(r => r.addEventListener('change', applyLabels));
            // ruční změna zaškrtávátka ruší výběr podle štítků, aby zvýraznění neklamalo
            incBoxes.forEach(b => b.addEventListener('change', (e) => {
                if (e.isTrusted) { labelBtns.forEach(x => x.setAttribute('aria-pressed', 'false')); }
            }));
        }
        sortHeaders.forEach(th => {
            th.style.cursor = 'pointer';
            th.addEventListener('click', () => {
                sortKey = th.dataset.sort;
                sortHeaders.forEach(h => { h.querySelector('.sort-arrow').textContent = ''; });
                th.querySelector('.sort-arrow').textContent = ' ▼';
                refresh();
            });
        });
        refresh();
    }

    const editForm = document.getElementById('edit-report-form');
    // Delegovaný handler (tlačítka vznikají i dynamicky po změně limitu hlášení)
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-edit-report');
        if (!btn || !editForm) return;
        editForm.elements['id'].value = btn.dataset.id;
        editForm.elements['reporter'].value = btn.dataset.reporter;
        editForm.elements['cubicle'].value = btn.dataset.cubicle;
        editForm.elements['status'].value = btn.dataset.status;
        editForm.elements['reported_at'].value = btn.dataset.reportedAt;
        if (editForm.elements['note']) {
            editForm.elements['note'].value = btn.dataset.note || '';
            editForm.elements['note'].dispatchEvent(new Event('input'));
        }
    });
    // Počítadlo znaků u poznámek
    document.querySelectorAll('textarea[data-note-counter]').forEach((ta) => {
        const out = document.getElementById(ta.dataset.noteCounter);
        const upd = () => { if (out) { out.textContent = ta.value.length + '/' + ta.maxLength; } };
        ta.addEventListener('input', upd);
        upd();
    });

    // Záložky: aktivní záložka se pamatuje v rámci relace (přežije obnovení stránky po akcích)
    const tabButtons = document.querySelectorAll('#main-tabs [data-bs-toggle="tab"]');
    if (tabButtons.length) {
        let wantedTab = null;
        try { wantedTab = sessionStorage.getItem('lh_tab'); } catch (err) {}
        const urlParams = new URLSearchParams(window.location.search);
        if (!wantedTab && (urlParams.has('date') || urlParams.has('cubicle'))) wantedTab = 'tab-history';
        const wantedEl = wantedTab ? document.getElementById(wantedTab) : null;
        if (wantedEl) bootstrap.Tab.getOrCreateInstance(wantedEl).show();
        tabButtons.forEach(b => b.addEventListener('shown.bs.tab', () => {
            try { sessionStorage.setItem('lh_tab', b.id); } catch (err) {}
        }));
    }

    // Potvrzení před odesláním hlášení:
    //  - nový incident: kabinka je nahlášena jako zesraná a poslední stav nebyl zesraná
    //  - oprava: poslední stav je zesraná a zadává se OK
    // Opakované "zesraná" (jen potvrzení stavu) se neptá.
    const lastStatus = window.LH_LASTSTATUS || {};
    const reportForm = document.getElementById('report-form');
    const confirmModalEl = document.getElementById('confirmIncidentModal');
    if (reportForm && confirmModalEl) {
        const confirmModal = bootstrap.Modal.getOrCreateInstance(confirmModalEl);
        const confirmTitle = document.getElementById('confirm-title');
        const confirmYes = document.getElementById('confirm-incident-yes');
        const confirmNo = document.getElementById('confirm-no');
        let pendingForm = reportForm;   // formulář, který se po potvrzení odešle

        reportForm.addEventListener('submit', (e) => {
            const status = reportForm.elements['status'].value;
            const cubicleInput = reportForm.querySelector('input[name="cubicle"]:checked');
            if (!cubicleInput) { return; }   // doplní se podle pravidel prohlížeče
            const prev = lastStatus[cubicleInput.value] || null;
            const cubicleName = (cubicleInput.dataset.full || '').trim().toLocaleLowerCase('cs');

            if (status === 'zesrana' && prev !== 'zesrana') {
                confirmTitle.textContent = 'Opravdu si přejete založit incident?';
                confirmYes.textContent = 'ANO, ' + cubicleName + ' je zesraná';
                confirmYes.className = 'btn btn-danger btn-lg m-0';
                confirmNo.textContent = 'NE, nezakládat incident';
            } else if (status === 'ok' && prev === 'zesrana') {
                confirmTitle.textContent = 'Kabinka je nahlášená jako zesraná. Opravdu ji chcete označit jako v pořádku?';
                confirmYes.textContent = 'ANO, ' + cubicleName + ' je v pořádku';
                confirmYes.className = 'btn btn-success btn-lg m-0';
                confirmNo.textContent = 'NE, ponechat stav zesraná';
            } else {
                return; // běžné hlášení, bez dotazu
            }

            e.preventDefault();
            pendingForm = reportForm;
            confirmYes.disabled = false;
            confirmModal.show();
        });

        // Pozorování osoby: stejný dialog, jiný text
        const sightingForm = document.getElementById('sighting-form');
        if (sightingForm) {
            const verbs = { vychazel: 'opouštěl', vchazel: 'vcházel do', poblizu: 'byl poblíž' };
            const accusative = { invalide: 'kabinku invalidé', leva: 'levou kabinku', prava: 'pravou kabinku', toalety_kabinka: 'toalety (levou nebo pravou kabinku)', toalety_nevim: 'toalety (kabinku nebo pisoár)', pisoar: 'pisoár' };
            const genitive = { invalide: 'kabinky invalidé', leva: 'levé kabinky', prava: 'pravé kabinky', toalety_kabinka: 'toalet (levé nebo pravé kabinky)', toalety_nevim: 'toalet (kabinky nebo pisoáru)', pisoar: 'pisoáru' };
            sightingForm.addEventListener('submit', (e) => {
                const who = sightingForm.elements['person_name'].value.trim();
                const loc = sightingForm.elements['location'].value;
                const dir = sightingForm.elements['direction'].value;
                if (!who || !loc || !dir) return;   // doplní se podle pravidel prohlížeče
                e.preventDefault();
                pendingForm = sightingForm;
                confirmTitle.textContent = 'Opravdu hlásíte, že ' + who + ' ' + verbs[dir] + ' ' + (dir === 'vychazel' ? accusative[loc] : genitive[loc]) + '?';
                confirmYes.textContent = 'ANO, odeslat pozorování';
                confirmYes.className = 'btn btn-primary btn-lg m-0';
                confirmNo.textContent = 'NE, neodesílat';
                confirmYes.disabled = false;
                confirmModal.show();
            });
        }

        confirmYes.addEventListener('click', () => {
            confirmYes.disabled = true; // proti dvojitému odeslání
            pendingForm.submit();       // submit() nespouští znovu událost submit, takže se nezeptá podruhé
        });
    }

    // Počet zobrazených hlášení: změna se projeví ihned (dotažení přes AJAX), nikam se neukládá
    const limitInput = document.getElementById('history-limit');
    if (limitInput) {
        const historyBody = document.getElementById('history-body');
        const historyCount = document.getElementById('history-count');
        const maxLimit = limitInput.max ? parseInt(limitInput.max, 10) : null;
        let timer = null;
        let controller = null;

        const loadHistory = () => {
            let n = parseInt(limitInput.value, 10);
            if (!(n >= 1)) n = 10;
            if (maxLimit && n > maxLimit) n = maxLimit;
            limitInput.value = n;

            const current = new URLSearchParams(window.location.search);
            const params = new URLSearchParams({ ajax: 'history', limit: n });
            ['date', 'cubicle', 'status'].forEach(k => { if (current.get(k)) params.set(k, current.get(k)); });

            if (controller) controller.abort();
            controller = new AbortController();
            fetch(window.location.pathname + '?' + params.toString(), { signal: controller.signal })
                .then(r => { if (!r.ok) throw new Error(r.status); return r.json(); })
                .then(d => {
                    historyBody.innerHTML = d.html;
                    historyCount.textContent = 'Zobrazeno ' + d.shown + ' z ' + d.total + ' hlášení';
                    limitInput.value = d.limit;
                })
                .catch(err => { if (err.name !== 'AbortError') historyCount.textContent = 'Načtení hlášení selhalo.'; });
        };

        limitInput.addEventListener('input', () => {
            if (limitInput.value === '') return; // uživatel právě přepisuje hodnotu
            clearTimeout(timer);
            timer = setTimeout(loadHistory, 300);
        });
        limitInput.addEventListener('change', () => { clearTimeout(timer); loadHistory(); });
    }

    const reporterInput = document.getElementById('reporter');
    if (reporterInput) {
        if (!reporterInput.value && localStorage.getItem('lh_reporter')) {
            reporterInput.value = localStorage.getItem('lh_reporter');
        }
        if (!reporterInput.readOnly) {
            reporterInput.addEventListener('input', () => {
                localStorage.setItem('lh_reporter', reporterInput.value);
            });
        }
    }

    const sightingReporter = document.getElementById('sighting-reporter');
    if (sightingReporter) {
        if (!sightingReporter.value && localStorage.getItem('lh_reporter')) { sightingReporter.value = localStorage.getItem('lh_reporter'); }
        if (!sightingReporter.readOnly) { sightingReporter.addEventListener('input', () => { localStorage.setItem('lh_reporter', sightingReporter.value); }); }
    }

    // Upozornění na rozpor v nevinnostech: prvních 6 rozporů je vidět, zbytek se rozbalí tlačítkem
    const innMore = document.getElementById('inn-more');
    if (innMore) { innMore.addEventListener('click', () => { document.querySelectorAll('#inn-conflicts .inn-extra').forEach(li => li.classList.remove('d-none')); innMore.remove(); }); }
    // Upozornění na rozpor v nevinnostech: „Otevřít incident“ rozbalí kartu incidentu a přejde na ni
    document.addEventListener('click', (e) => {
        const a = e.target.closest && e.target.closest('[data-open-inc]');
        if (!a) { return; }
        const it = document.getElementById('inc-item-' + a.dataset.openInc);
        if (!it) { return; }
        e.preventDefault();
        const card = document.getElementById('inc-card');
        if (card) { card.open = true; }   // karta incidentů je při načtení sbalená
        it.open = true;
        it.scrollIntoView({ behavior: 'smooth', block: 'start' });
        it.style.outline = '3px solid #dc3545';
        setTimeout(() => { it.style.outline = ''; }, 2500);
    });
    // Admin panel Nevinní: zaškrtnutí data vybere všechny incidenty z toho dne
    document.addEventListener('change', (e) => {
        if (e.target.classList && e.target.classList.contains('innocent-day')) {
            e.target.closest('.innocent-day-group').querySelectorAll('.innocent-inc').forEach(c => { c.checked = e.target.checked; });
        }
    });

    // Našeptávač jmen (u pozorování a v panelu Nevinní; dotahuje jen shody s napsaným textem)
    const attachSuggest = (input, list) => {
        if (!input || !list) { return; }
        let timer = null;
        let ctrl = null;
        const hide = () => { list.classList.add('d-none'); list.innerHTML = ''; };
        input.addEventListener('input', () => {
            clearTimeout(timer);
            const q = input.value.trim();
            if (q.length < 2) { hide(); return; }
            timer = setTimeout(() => {
                if (ctrl) { ctrl.abort(); }
                ctrl = new AbortController();
                fetch(window.location.pathname + '?ajax=names&q=' + encodeURIComponent(q), { signal: ctrl.signal })
                    .then(r => r.json())
                    .then(names => {
                        list.innerHTML = '';
                        names.forEach(n => {
                            const b = document.createElement('button');
                            b.type = 'button';
                            b.className = 'list-group-item list-group-item-action';
                            b.textContent = n;
                            b.addEventListener('click', () => { input.value = n; hide(); });
                            list.appendChild(b);
                        });
                        list.classList.toggle('d-none', names.length === 0);
                    })
                    .catch(() => {});
            }, 250);
        });
        document.addEventListener('click', (e) => { if (e.target !== input && !list.contains(e.target)) { hide(); } });
    };
    attachSuggest(document.getElementById('sighting-person'), document.getElementById('sighting-suggest'));
    attachSuggest(document.getElementById('note-person'), document.getElementById('note-suggest'));
    attachSuggest(document.getElementById('se-person'), document.getElementById('se-suggest'));
    attachSuggest(document.getElementById('culprit-person'), document.getElementById('culprit-suggest'));
    // Admin: aliasy jmen (náhled a návrhy) a záznam akcí (filtry, stránkování po 50)
    (() => {
        const GL = { reports: 'Hlášení', exclusions: 'Vyřazení', sightings_admin: 'Pozorování', it: 'IT', analysis: 'Analýza', announce: 'Oznámení', login: 'Přihlášení', backup: 'Záloha', import: 'Import', settings: 'Nastavení' };
        const TL = { turnstile_logs: 'průchody', sightings: 'pozorování', incident_innocents: 'nevinnosti', employees: 'zaměstnanci', blacklisted_suspects: 'vyřazení', trusted_persons: 'důvěryhodní', milestones: 'milníky', poznatky: 'poznatky', it_staff: 'členové IT' };
        const aform = document.getElementById('alias-form');
        if (aform) {
            attachSuggest(document.getElementById('alias-canon'), document.getElementById('alias-canon-suggest'));
            const box = document.getElementById('alias-preview'), sug = document.getElementById('alias-suggest');
            const j = async (params) => { const r = await fetch('index.php?' + new URLSearchParams(params)); return r.json(); };
            document.getElementById('alias-preview-btn').addEventListener('click', async () => {
                box.textContent = 'Načítám …';
                const d = await j({ ajax: 'alias_preview', canonical: document.getElementById('alias-canon').value, aliases: document.getElementById('alias-list').value });
                box.textContent = '';
                if (d.error || !d.items || !d.items.length) { box.textContent = d.error || 'Zadej hlavní jméno a aspoň jeden alias.'; return; }
                if (d.resolved) { const n = document.createElement('div'); n.className = 'text-warning-emphasis'; n.textContent = 'Zadané hlavní jméno je samo alias, použije se „' + d.canonical + '“.'; box.appendChild(n); }
                d.items.forEach(it => {
                    const row = document.createElement('div'); row.className = 'border-bottom py-1';
                    const parts = Object.keys(it.rows).map(t => (TL[t] || t) + ' ' + it.rows[t]);
                    const dup = Object.keys(it.dups).map(t => (TL[t] || t) + ' ' + it.dups[t]);
                    let txt = '„' + it.alias + '“ → „' + d.canonical + '“: ';
                    if (it.same) { txt += 'shodné s hlavním jménem, není co slučovat.'; }
                    else if (it.it_conflict) { txt += 'oba jsou členové IT, sloučení se odmítne (slouč je ručně v panelu IT).'; }
                    else { txt += it.total > 0 ? 'přepíše se ' + it.total + ' řádků (' + parts.join(', ') + ')' + (dup.length ? '; odstraní se duplicity: ' + dup.join(', ') : '') : 'v datech se nevyskytuje, uloží se jen pravidlo pro budoucí zadávání.'; }
                    row.textContent = txt; if (it.it_conflict || it.same) { row.classList.add('text-danger'); }
                    box.appendChild(row);
                });
            });
            document.getElementById('alias-suggest-btn').addEventListener('click', async () => {
                sug.textContent = 'Hledám …';
                const d = await j({ ajax: 'alias_suggest' });
                sug.textContent = '';
                if (d.error) { sug.textContent = d.error; return; }
                if (!d.pairs.length) { sug.textContent = 'Žádné možné duplicity jsem nenašel (prohlédnuto ' + d.names + ' jmen).'; return; }
                const h = document.createElement('div'); h.className = 'text-body-secondary mb-1'; h.textContent = 'Možné duplicity (' + d.total + (d.total > d.pairs.length ? ', ukázáno ' + d.pairs.length : '') + '):'; sug.appendChild(h);
                d.pairs.forEach(p => {
                    const row = document.createElement('div'); row.className = 'border-bottom py-1 d-flex flex-wrap align-items-center gap-2';
                    const t = document.createElement('span'); t.textContent = '„' + p.a + '“ (' + p.na + ' průchodů) a „' + p.b + '“ (' + p.nb + '): ' + p.why; row.appendChild(t);
                    const b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm btn-outline-secondary'; b.textContent = 'Použít';
                    b.addEventListener('click', () => { const keep = p.na >= p.nb ? p.a : p.b, al = p.na >= p.nb ? p.b : p.a; document.getElementById('alias-canon').value = keep; document.getElementById('alias-list').value = al; box.textContent = ''; document.getElementById('alias-preview-btn').click(); });
                    row.appendChild(b); sug.appendChild(row);
                });
            });
        }
        const apanel = document.getElementById('audit-panel'), alist = document.getElementById('audit-list');
        if (apanel && alist) {
            const q = document.getElementById('audit-q'), gs = document.getElementById('audit-group'), us = document.getElementById('audit-user'), more = document.getElementById('audit-more'), info = document.getElementById('audit-info');
            let oldest = 0, loaded = false, timer = null, total = 0;
            const addRows = (rows) => rows.forEach(r => {
                const dt = document.createElement('details'); dt.className = 'border-bottom py-1';
                const sm = document.createElement('summary'); sm.style.cursor = 'pointer';
                const t = document.createElement('span'); t.className = 'text-body-secondary me-2'; t.textContent = r.t; sm.appendChild(t);
                const u = document.createElement('strong'); u.className = 'me-2'; u.textContent = r.user !== '' ? r.user : '(heslo)'; sm.appendChild(u);
                sm.appendChild(document.createTextNode(r.label + ' '));
                const g = document.createElement('span'); g.className = 'badge bg-secondary-subtle text-secondary-emphasis'; g.textContent = GL[r.grp] || r.grp; sm.appendChild(g);
                dt.appendChild(sm);
                const body = document.createElement('div'); body.className = 'ps-3 pt-1 text-body-secondary'; body.style.whiteSpace = 'pre-wrap'; body.style.overflowWrap = 'anywhere';
                body.textContent = (r.result ? 'Výsledek: ' + r.result + '\n' : '') + 'IP: ' + (r.ip || '–') + ' · zařízení #' + (r.dev || '–') + ' · akce: ' + (r.act || '–') + (r.det ? '\n' + r.det : '');
                dt.appendChild(body); alist.appendChild(dt);
            });
            const fill = (sel, vals, all, map) => { const cur = sel.value; sel.textContent = ''; const o0 = document.createElement('option'); o0.value = ''; o0.textContent = all; sel.appendChild(o0); vals.forEach(v => { const o = document.createElement('option'); o.value = v === '' ? '(heslo)' : v; o.textContent = map ? (map[v] || v) : (v === '' ? '(přihlášení heslem)' : v); sel.appendChild(o); }); sel.value = cur; };
            const load = async (append) => {
                const p = new URLSearchParams({ ajax: 'audit', q: q.value, group: gs.value, user: us.value, limit: 50 });
                if (append && oldest) { p.set('before', oldest); }
                const d = await (await fetch('index.php?' + p)).json();
                if (!append) { alist.textContent = ''; total = 0; }
                if (d.error) { alist.textContent = d.error; return; }
                if (d.groups) { fill(gs, d.groups, 'Všechny skupiny', GL); fill(us, d.users, 'Všichni uživatelé', null); }
                addRows(d.rows); total += d.rows.length;
                if (d.rows.length) { oldest = d.rows[d.rows.length - 1].id; }
                more.classList.toggle('d-none', !d.more);
                info.textContent = total ? 'Zobrazeno ' + total + (d.more ? ' (jsou i starší)' : '') : 'Nic nenalezeno.';
            };
            const reload = () => { oldest = 0; load(false); };
            apanel.querySelector('h3').addEventListener('click', () => { if (!loaded) { loaded = true; reload(); } });
            q.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(reload, 300); });
            gs.addEventListener('change', reload); us.addEventListener('change', reload);
            document.getElementById('audit-reload').addEventListener('click', reload);
            more.addEventListener('click', () => load(true));
        }
    })();
    // Chci zaznamenat: přepínač formulářů; při načtení je vždy zvolen „Stav toalety“ (stav se nepamatuje)
    (() => {
        const box = document.getElementById('record-box');
        if (!box) { return; }
        const apply = () => {
            const v = (box.querySelector('input[name="rec-kind"]:checked') || {}).value || 'stav';
            box.querySelectorAll('.lh-rec-form').forEach((f) => f.classList.toggle('d-none', f.dataset.rec !== v));
        };
        box.querySelectorAll('input[name="rec-kind"]').forEach((r) => r.addEventListener('change', apply));
        document.getElementById('rec-stav').checked = true;
        apply();
    })();
    // Milníky: našeptávač osoby, pole podle typu, úprava v administraci a výběr všech osob mimo firmu u incidentu
    (() => {
        attachSuggest(document.getElementById('ms-person'), document.getElementById('ms-suggest'));
        const kind = document.getElementById('ms-kind');
        if (kind) {
            const labels = { left: 'Poslední den v práci', joined: 'První den v práci', away: 'Od', change: 'Datum', other: 'Datum' };
            const apply = () => {
                const k = kind.value, person = k === 'left' || k === 'joined' || k === 'away';
                document.getElementById('ms-person-box').classList.toggle('d-none', !person);
                document.getElementById('ms-to-box').classList.toggle('d-none', k !== 'away');
                document.getElementById('ms-from-label').textContent = labels[k] || 'Datum';
                document.getElementById('ms-title-label').textContent = person ? 'Název (nepovinný)' : 'Název';
                document.getElementById('ms-person').required = person;
                document.getElementById('ms-to').required = k === 'away';
                document.getElementById('ms-title').required = !person;
            };
            kind.addEventListener('change', apply); apply();
            const form = document.getElementById('ms-form');
            const reset = () => { form.reset(); document.getElementById('ms-id').value = '0'; document.getElementById('ms-submit').textContent = 'Uložit milník'; document.getElementById('ms-cancel').classList.add('d-none'); apply(); };
            document.getElementById('ms-cancel').addEventListener('click', reset);
            document.querySelectorAll('#ms-panel .ms-edit').forEach(b => b.addEventListener('click', () => {
                const d = JSON.parse(b.closest('tr').dataset.ms);
                kind.value = d.kind; apply();
                document.getElementById('ms-id').value = d.id; document.getElementById('ms-person').value = d.person; document.getElementById('ms-from').value = d.from;
                document.getElementById('ms-to').value = d.to; document.getElementById('ms-title').value = d.title; document.getElementById('ms-note').value = d.note;
                document.getElementById('ms-submit').textContent = 'Uložit změny'; document.getElementById('ms-cancel').classList.remove('d-none');
                form.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }));
        }
        document.querySelectorAll('.ms-grant-form .ms-toggle-all').forEach(b => b.addEventListener('click', () => {
            const boxes = [...b.closest('form').querySelectorAll('.ms-out-chk:not(:disabled)')];
            const on = boxes.some(c => !c.checked);
            boxes.forEach(c => { c.checked = on; });
        }));
    })();
    // Našeptávač v seznamu osob (jedna na řádek): doplňuje a nahrazuje jen řádek, na kterém je kurzor
    (() => {
      [['innocent-person', 'innocent-suggest'], ['trusted-person', 'trusted-suggest']].forEach(([taId, listId]) => {
        const ta = document.getElementById(taId);
        const list = document.getElementById(listId);
        if (!ta || !list) { return; }
        let timer = null;
        let ctrl = null;
        const hide = () => { list.classList.add('d-none'); list.innerHTML = ''; };
        const lineAt = () => {
            const v = ta.value, pos = ta.selectionStart;
            const s0 = v.lastIndexOf('\n', pos - 1) + 1;
            let e0 = v.indexOf('\n', pos);
            if (e0 === -1) { e0 = v.length; }
            return { s: s0, e: e0, text: v.slice(s0, e0) };
        };
        ta.addEventListener('input', () => {
            clearTimeout(timer);
            const ln = lineAt();
            const q = ln.text.trim();
            if (q.length < 2) { hide(); return; }
            timer = setTimeout(() => {
                if (ctrl) { ctrl.abort(); }
                ctrl = new AbortController();
                fetch(window.location.pathname + '?ajax=names&q=' + encodeURIComponent(q), { signal: ctrl.signal })
                    .then(r => r.json())
                    .then(names => {
                        list.innerHTML = '';
                        names.forEach(n => {
                            const b = document.createElement('button');
                            b.type = 'button';
                            b.className = 'list-group-item list-group-item-action';
                            b.textContent = n;
                            b.addEventListener('click', () => {
                                const cur = ta.value.slice(ln.s, ln.e) === ln.text ? ln : lineAt();
                                ta.value = ta.value.slice(0, cur.s) + n + ta.value.slice(cur.e);
                                const caret = cur.s + n.length;
                                ta.focus();
                                ta.setSelectionRange(caret, caret);
                                hide();
                            });
                            list.appendChild(b);
                        });
                        list.classList.toggle('d-none', names.length === 0);
                    })
                    .catch(() => {});
            }, 250);
        });
        document.addEventListener('click', (e) => { if (e.target !== ta && !list.contains(e.target)) { hide(); } });
      });
    })();

    const themeToggleBtn = document.getElementById('theme-toggle');
    const themeIcon = document.getElementById('theme-icon');
    const themeText = document.getElementById('theme-text');

    function getCurrentTheme() {
        return document.documentElement.getAttribute('data-bs-theme') || 'light';
    }

    const iconAttrs = 'class="lh-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
    const ICON_SUN = '<svg ' + iconAttrs + '><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>';
    const ICON_MOON = '<svg ' + iconAttrs + '><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';

    function updateBtnUI(theme) {
        if (theme === 'dark') {
            themeIcon.innerHTML = ICON_SUN;
            themeText.textContent = 'Světlý režim';
        } else {
            themeIcon.innerHTML = ICON_MOON;
            themeText.textContent = 'Tmavý režim';
        }
    }

    // Detaily průchodů u podezřelých jsou ve výchozím stavu sbalené (na všech zařízeních); tlačítko nad tabulkou je rozbalí nebo sbalí všechny najednou
    const detToggle = document.getElementById('lh-details-toggle');
    if (detToggle) {
        detToggle.addEventListener('click', () => {
            const all = Array.from(document.querySelectorAll('details.lh-detail'));
            const open = all.some(d => !d.open);   // je něco sbaleného → rozbalit vše; jinak sbalit vše
            all.forEach(d => { d.open = open; });
            detToggle.textContent = open ? 'Sbalit všechny detaily' : 'Rozbalit všechny detaily';
            detToggle.setAttribute('aria-pressed', open ? 'true' : 'false');
        });
    }

    updateBtnUI(getCurrentTheme());

    themeToggleBtn.addEventListener('click', () => {
        const currentTheme = getCurrentTheme();
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', newTheme);
        localStorage.setItem('theme', newTheme);
        updateBtnUI(newTheme);
    });
});
