"""Prohlížečový test (Selenium): funkčnost stránky se zapnutou Content-Security-Policy, filtry, rozbalování, potvrzení, mobil.
Volitelný: potřebuje `pip install selenium` a ovladač prohlížeče. Výchozí je WebKitGTK (WebKitWebDriver); jiný prohlížeč: LH_BROWSER=chrome.
Spouští se s prostředím `tests/env.sh up demo`. Bez Seleniu test skončí s upozorněním (kód 0)."""
from lhtest import *
try:
    from selenium import webdriver
except ImportError:
    print('Selenium není nainstalované (pip install selenium): prohlížečový test přeskočen.'); sys.exit(0)
T = Tests('Prohlížeč (CSP zapnuté)')
def make_driver():
    if os.environ.get('LH_BROWSER', 'webkit') == 'chrome':
        o = webdriver.ChromeOptions(); o.add_argument('--headless=new'); o.add_argument('--no-sandbox'); return webdriver.Chrome(options=o)
    from selenium.webdriver.webkitgtk.options import Options
    o = Options(); o.binary_location = os.environ.get('LH_WEBKIT_BINARY', '/usr/lib/x86_64-linux-gnu/webkit2gtk-4.1/MiniBrowser'); o.add_argument('--automation')
    return webdriver.WebKitGTK(options=o)
try: d = make_driver()
except Exception as e:
    print('Prohlížeč se nepodařilo spustit (%s): prohlížečový test přeskočen.' % str(e)[:120]); sys.exit(0)
def load(port, w=1300, h=1000):
    d.set_window_size(w, h); d.get('http://127.0.0.1:%d/' % PORT[port]); time.sleep(3.2)
    d.execute_script("window.__errs = []; window.addEventListener('error', function (e) { window.__errs.push(e.message); }); window.confirm = function () { return true; }")
errs = lambda: d.execute_script('return window.__errs || []')
def row(name): return d.execute_script("const r=[...document.querySelectorAll('#pane-suspects tr.suspect-row')].find(x=>x.dataset.name===arguments[0]); return r?{vis:!r.classList.contains('d-none'),pts:Number(r.dataset.points),badge:!!(r.querySelector('.trusted-badge:not(.d-none)')),cul:!!r.querySelector('.culprit-badge')}:null", name)
def click(id_): d.execute_script("document.getElementById(arguments[0]).click()", id_); time.sleep(0.4)
try:
    for k in ('collect_disabled', 'culprit_name', 'ro_hidden'): del_setting(k)
    q("DELETE FROM incident_innocents WHERE reason='ui'")
    T.section('Stránky se skripty a záložkami (CSP zapnuté)')
    for port, label in (('full', 'plná verze'), ('admin', 'admin'), ('preview', 'náhled')):
        load(port); has_tab = d.execute_script("const b=document.getElementById('tab-history'); b.click(); return document.getElementById('pane-history').classList.contains('show') || document.getElementById('pane-history').classList.contains('active')"); time.sleep(0.5)
        T.check('%s: záložka „Historie“ se po kliknutí přepne (skripty a Bootstrap fungují) a bez chyb skriptu' % label, has_tab and not errs(), errs())
    T.section('Filtry „Skrýt důvěryhodné“ a „Skrýt nevinné“ (výchozí stav zapnuto)')
    load('full'); hana = row('Hana Modelová'); nina = row('Nina Plyšová'); adam = row('Adam Vzorový')
    T.check('důvěryhodná Hana (5 bodů) a nevinná Nina (3 body) jsou skryté, Adam (7) vidět', hana and not hana['vis'] and nina and not nina['vis'] and adam['vis'] and adam['pts'] == 7, (hana, nina, adam))
    T.check('oba filtry jsou po načtení zapnuté', d.execute_script("return document.getElementById('flt-hide-trust').checked && document.getElementById('flt-hide-inn').checked"))
    click('flt-hide-trust'); h2 = row('Hana Modelová'); T.check('po vypnutí „Skrýt důvěryhodné“ je Hana vidět s odznakem „Důvěryhodný“', h2['vis'] and h2['badge'], h2)
    click('flt-hide-inn'); T.check('po vypnutí „Skrýt nevinné“ je vidět i Nina', row('Nina Plyšová')['vis'])
    T.section('Detaily průchodů: sbalené, po kliknutí a všechny najednou')
    load('full'); st = lambda: d.execute_script("const x=[...document.querySelectorAll('#pane-suspects details.lh-detail')]; return {n:x.length, open:x.filter(e=>e.open).length}")
    s0 = st(); T.check('všech %d detailů je ve výchozím stavu sbaleno' % s0['n'], s0['n'] > 10 and s0['open'] == 0)
    d.execute_script("document.querySelector('#pane-suspects tr.suspect-row:not(.d-none) details.lh-detail > summary').click()"); time.sleep(0.3); T.check('kliknutí na nadpis rozbalí jeden detail', st()['open'] == 1)
    click('lh-details-toggle'); s1 = st(); T.check('tlačítko „Rozbalit všechny detaily“ rozbalí všechny a přepne popisek', s1['open'] == s1['n'] and 'Sbalit' in d.execute_script("return document.getElementById('lh-details-toggle').textContent"))
    click('lh-details-toggle'); T.check('druhé kliknutí je sbalí', st()['open'] == 0)
    T.section('Potvrzovací dialogy z atributů data-confirm (místo inline obsluh)')
    load('admin'); d.execute_script("bootstrap.Tab.getOrCreateInstance(document.getElementById('tab-admin')).show()"); time.sleep(0.5)
    d.execute_script("const h=document.querySelector('#collect-panel > h3.card-title'); if(h.getAttribute('aria-expanded')!=='true') h.click()"); time.sleep(0.4)
    d.execute_script("window.confirm = function () { window.__asked = true; return false; }; document.querySelector('#collect-panel button.btn-danger').click()"); time.sleep(1.5)
    T.check('zamítnutý dotaz „Vypnout sběr dat“: dotaz se zobrazil, nic se neodeslalo', d.execute_script('return !!window.__asked') and setting('collect_disabled') in ('', '0'))
    d.execute_script("window.confirm = function () { return true; }; document.querySelector('#collect-panel button.btn-danger').click()"); time.sleep(3)
    T.check('potvrzený dotaz sběr vypne', setting('collect_disabled') == '1')
    T.section('Označený pachatel zůstane v žebříčku, i když je důvěryhodný')
    set_setting('culprit_name', 'Hana Modelová'); load('full'); hc = row('Hana Modelová')
    T.check('Hana je důvěryhodná a filtr je zapnutý, přesto je vidět s odznakem PACHATEL', hc and hc['vis'] and hc['cul'], hc)
    del_setting('culprit_name'); del_setting('collect_disabled')
    T.section('Přihlášení heslem, časová osa a náhled bez průchodů')
    load('full'); d.execute_script("document.querySelector('details.lh-login > summary').click()"); time.sleep(0.4)
    T.check('klik na „Admin“ rozbalí přihlašovací formulář s polem hesla', d.execute_script("const f=document.querySelector('details.lh-login > form'); return !!f && f.getBoundingClientRect().height>0 && !!f.querySelector('input[type=password]')"))
    load('full'); d.get('http://127.0.0.1:8081/vizualizace.php'); time.sleep(3)
    chips = d.execute_script("return [...document.querySelectorAll('#chips *')].map(x=>x.textContent.trim()).filter(Boolean)")
    T.check('časová osa se načte a nabízí vrstvy i turnikety (%d prvků)' % len(chips), len(chips) > 5 and any('Turniket' in c for c in chips))
    set_setting('ro_hidden', 'passes'); d.get('http://127.0.0.1:8092/vizualizace.php'); time.sleep(3)
    chips = d.execute_script("return [...document.querySelectorAll('#chips *')].map(x=>x.textContent.trim()).filter(Boolean)")
    T.check('náhled s vypnutými průchody: časová osa bez ovládání turniketů', len(chips) > 3 and not any('Turniket' in c or 'IN dovnitř' in c for c in chips))
    del_setting('ro_hidden')
    T.section('Mobil (úzké okno) bez vodorovného posuvu')
    for port in ('full', 'admin', 'preview'):
        load(port, 390, 900); T.check('%s: stránka se vejde na šířku' % port, not d.execute_script("return document.documentElement.scrollWidth>window.innerWidth"))
finally:
    for k in ('collect_disabled', 'culprit_name', 'ro_hidden'): del_setting(k)
    d.quit()
sys.exit(T.finish())
