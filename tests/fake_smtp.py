#!/usr/bin/env python3
"""Zachytávací SMTP server pro testy (STARTTLS + AUTH LOGIN jako opravdový server, samopodepsaný certifikát).
Přijaté zprávy ukládá po řádcích jako JSON do souboru LH_MAILS (výchozí .run/mails.jsonl). Port 2587, jen 127.0.0.1."""
import socket, ssl, threading, json, os, email, email.header, subprocess, sys
RUN = os.environ.get('LH_RUN', os.path.join(os.path.dirname(os.path.abspath(__file__)), '.run'))
MAILS = os.environ.get('LH_MAILS', os.path.join(RUN, 'mails.jsonl'))
os.makedirs(RUN, exist_ok=True)
CRT, KEY = os.path.join(RUN, 'smtp_cert.pem'), os.path.join(RUN, 'smtp_key.pem')
if not os.path.exists(CRT):
    subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', KEY, '-out', CRT, '-days', '3', '-subj', '/CN=localhost'], check=True, capture_output=True)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(CRT, KEY)

def handle(c):
    f = c.makefile('rwb', buffering=0)
    send = lambda x: f.write((x + '\r\n').encode())
    rl = lambda: f.readline().decode('utf-8', 'replace').rstrip('\r\n')
    try:
        send('220 fake'); to = None; tls = False
        while True:
            l = rl(); u = l.upper()
            if u.startswith('EHLO'): send('250-fake'); send('250-AUTH LOGIN' if tls else '250-STARTTLS'); send('250 OK')
            elif u.startswith('STARTTLS'): send('220 go'); c = ctx.wrap_socket(c, server_side=True); f = c.makefile('rwb', buffering=0); tls = True
            elif u.startswith('AUTH LOGIN'): send('334 VXNlcm5hbWU6'); rl(); send('334 UGFzc3dvcmQ6'); rl(); send('235 ok')
            elif u.startswith('MAIL FROM'): send('250 ok')
            elif u.startswith('RCPT TO'): to = l[l.find('<') + 1:l.find('>')]; send('250 ok')
            elif u == 'DATA':
                send('354 go'); lines = []
                while True:
                    x = rl()
                    if x == '.': break
                    lines.append(x)
                m = email.message_from_string('\r\n'.join(lines)); sj = email.header.decode_header(m['Subject'])[0]
                sj = sj[0].decode(sj[1] or 'utf-8') if isinstance(sj[0], bytes) else sj[0]
                open(MAILS, 'a').write(json.dumps({'to': to, 'subject': sj, 'body': m.get_payload(decode=True).decode('utf-8', 'replace')}, ensure_ascii=False) + '\n'); send('250 ok')
            elif u.startswith('QUIT'): send('221 bye'); break
            elif l == '': break
            else: send('250 ok')
    except Exception:
        pass
    try: c.close()
    except Exception: pass

s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1); s.bind(('127.0.0.1', 2587)); s.listen(5)
while True:
    c, _ = s.accept(); threading.Thread(target=handle, args=(c,), daemon=True).start()
