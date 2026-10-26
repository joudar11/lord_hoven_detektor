"""Pomocné funkce pro testy aplikace Detektor Lorda Hoven (jen standardní knihovna Pythonu).
Prostředí (databáze, servery) spustí tests/env.sh. Test končí kódem 0 jen tehdy, když všechny kontroly prošly."""
import os, re, json, html, subprocess, sys, time, urllib.request, urllib.parse, urllib.error, http.cookiejar, unicodedata, datetime as dt

HERE = os.path.dirname(os.path.abspath(__file__))
RUN = os.environ.get('LH_RUN', os.path.join(HERE, '.run'))
ENV = json.load(open(os.path.join(RUN, 'env.json'), encoding='utf-8'))
MAILS = ENV['mails']
PORT = {'full': 8081, 'admin': 8091, 'preview': 8092, 'user': 8093, 'texts': 8095, 'retention': 8096, 'iplogin': 8097, 'hashed': 8098, 'badconf': 8099}

def q(sql, db=True):
    """SQL přes příkazovou řádku mariadb; vrací výstup bez hlavičky (TSV)."""
    cmd = ['mariadb', '-S', ENV['db_socket'], '--default-character-set=utf8mb4', '-N', '-B', '-e', sql]
    if db: cmd.insert(3, ENV['db'])
    r = subprocess.run(cmd, capture_output=True, text=True)
    if r.returncode != 0: raise RuntimeError('SQL selhalo: %s\n%s' % (sql[:200], r.stderr[:300]))
    return r.stdout.rstrip('\n')

def norm(s):
    return re.sub(r'\s+', ' ', ''.join(c for c in unicodedata.normalize('NFD', s.lower()) if unicodedata.category(c) != 'Mn')).strip()

class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None

class Client:
    """HTTP klient s cookies a automatickým CSRF tokenem. `port`: klíč z PORT nebo číslo; `admin=True` se přihlásí heslem."""
    def __init__(self, port='full', admin=False, headers=None, password='TestAdminPw1'):
        self.port = PORT.get(port, port); self.base = 'http://127.0.0.1:%d/' % self.port
        self.cj = http.cookiejar.CookieJar(); self.h = dict(headers or {}); self.last = ''; self.code = 0; self.csrf = ''; self.resp_headers = {}
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cj), _NoRedirect())
        if admin: self.post({'action': 'admin_login', 'admin_password': password}); self.get()
    def _do(self, req):
        try:
            r = self.op.open(req); self.code = r.getcode(); self.resp_headers = r.headers; self.last = r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            self.code = e.code; self.resp_headers = e.headers; self.last = e.read().decode('utf-8', 'replace') if e.code != 302 else ''
        m = re.search(r'name="lh_csrf" value="([0-9a-f]+)"', self.last)
        if m: self.csrf = m.group(1)
        return self.last
    def get(self, path=''): return self._do(urllib.request.Request(self.base + path, headers=self.h))
    def post(self, d, csrf=True, headers=None):
        d = dict(d)
        if csrf is True and 'lh_csrf' not in d:
            if not self.csrf: self.get()
            d['lh_csrf'] = self.csrf
        elif isinstance(csrf, str): d['lh_csrf'] = csrf
        h = dict(self.h); h.update(headers or {})
        return self._do(urllib.request.Request(self.base, data=urllib.parse.urlencode(d, doseq=True).encode(), headers=h))
    def flash(self):
        """(typ, text) první hlášky na stránce; GET stránky hlášku spotřebuje."""
        t = self.get(); m = re.search(r'class="alert alert-(\w+)[^"]*"[^>]*>(.*?)</div>', t, re.S)
        return (m.group(1), re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', m.group(2)))).strip()) if m else (None, '')

def ranking(page):
    """{jméno: body} z řádků žebříčku (body = počet incidentů s některým zdrojem bodu, tj. masky 1 až 16)."""
    out = {}
    for n, inc in re.findall(r'<tr class="suspect-row[^"]*" data-name="([^"]*)" data-inc="([^"]*)"', page):
        out[html.unescape(n)] = sum(1 for m in inc.split(',') if m and int(m) & 31)
    return out

def mails():
    try: return [json.loads(l) for l in open(MAILS, encoding='utf-8').read().strip().split('\n') if l]
    except OSError: return []
def clear_mails():
    try: os.remove(MAILS)
    except OSError: pass
def wait_mail(n=1, timeout=8):
    for _ in range(int(timeout * 4)):
        if len(mails()) >= n: return True
        time.sleep(0.25)
    return False

def wait_for(subject_prefix, timeout=8):
    """Počká na e-mail, jehož předmět začíná daným textem (aplikace posílá i upozornění adminům, takže nestačí brát poslední zprávu)."""
    for _ in range(int(timeout * 4)):
        found = [m for m in mails() if m['subject'].startswith(subject_prefix)]
        if found: return found[-1]
        time.sleep(0.25)
    return None
def mails_with(subject_prefix): return [m for m in mails() if m['subject'].startswith(subject_prefix)]

def setting(key): return q("select ifnull((select setting_value from app_settings where setting_key='%s'),'')" % key.replace("'", ''))
def set_setting(key, value): q("INSERT INTO app_settings (setting_key,setting_value) VALUES ('%s','%s') ON DUPLICATE KEY UPDATE setting_value='%s'" % (key, value, value))
def del_setting(key): q("DELETE FROM app_settings WHERE setting_key='%s'" % key)

class Tests:
    """Sbírá výsledky kontrol; `finish()` vypíše souhrn a vrátí kód pro exit."""
    def __init__(self, title): self.title = title; self.ok = 0; self.bad = 0; print('=== %s ===' % title)
    def section(self, name): print('\n-- %s' % name)
    def check(self, label, cond, extra=''):
        cond = bool(cond); self.ok += cond; self.bad += (not cond)
        print('  %-100s %s%s' % (label[:100], 'OK' if cond else 'CHYBA', ('  ' + str(extra)[:200]) if (extra and not cond) else ''))
        return cond
    def finish(self):
        print('\n%s: %d kontrol OK, %d chyb' % (self.title, self.ok, self.bad)); return 0 if self.bad == 0 else 1
