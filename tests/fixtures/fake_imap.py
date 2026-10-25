"""A tiny IMAP server for tests: serves the files in a folder as INBOX (UID = order)."""
import os, re, socket, sys

port, folder, password = int(sys.argv[1]), sys.argv[2], sys.argv[3]
msgs = [open(os.path.join(folder, f), 'rb').read() for f in sorted(os.listdir(folder))]
srv = socket.socket(); srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(('127.0.0.1', port)); srv.listen(5)
print('ready', flush=True)
while True:
    conn, _ = srv.accept()
    f = conn.makefile('rb')
    conn.sendall(b'* OK fake IMAP ready\r\n')
    for raw in f:
        line = raw.decode().rstrip('\r\n')
        tag, _, cmd = line.partition(' ')
        up = cmd.upper()
        if up.startswith('LOGIN'):
            ok = cmd.endswith('"%s"' % password)
            conn.sendall(('%s OK LOGIN done\r\n' % tag if ok else '%s NO [AUTHENTICATIONFAILED] bad\r\n' % tag).encode())
        elif up.startswith('EXAMINE'):
            conn.sendall(('* %d EXISTS\r\n* OK [UIDVALIDITY 7] ok\r\n%s OK [READ-ONLY] done\r\n' % (len(msgs), tag)).encode())
        elif up.startswith('UID SEARCH'):
            lo = int(re.search(r'UID (\d+):\*', cmd).group(1))
            uids = [i + 1 for i in range(len(msgs)) if i + 1 >= lo] or ([len(msgs)] if msgs else [])
            conn.sendall(('* SEARCH %s\r\n%s OK done\r\n' % (' '.join(map(str, uids)), tag)).encode())
        elif up.startswith('UID FETCH'):
            uid = int(cmd.split()[2]); body = msgs[uid - 1]
            conn.sendall(('* %d FETCH (UID %d BODY[]<0> {%d}\r\n' % (uid, uid, len(body))).encode() + body + (')\r\n%s OK done\r\n' % tag).encode())
        elif up.startswith('LOGOUT'):
            conn.sendall(('* BYE\r\n%s OK bye\r\n' % tag).encode()); break
        else:
            conn.sendall(('%s BAD unknown\r\n' % tag).encode())
    conn.close()
