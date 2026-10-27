"""Runs inside integration.py before its normal fixtures; disposable database only."""
from concurrent.futures import ThreadPoolExecutor
import re,hashlib

def setup_security():
 setupfile=pathlib.Path(temp.name)/'owner-setup.json';ENV['VV_OWNER_SETUP_FILE']=str(setupfile)
 log5=open(pathlib.Path(temp.name)/'setup-php.log','w')
 proc=subprocess.Popen([PHP,'-S','127.0.0.1:18087','-t','public','tools/router.php'],env=ENV,stdout=log5,stderr=log5,start_new_session=True)
 codes=[]
 def call(route,body=None,headers=None):
  hs=headers or {}
  if body is not None:hs={**hs,'Content-Type':'application/json'}
  req=urllib.request.Request('http://127.0.0.1:18087/api/index.php?route='+route,data=None if body is None else json.dumps(body).encode(),headers=hs)
  try:
   with urllib.request.urlopen(req,timeout=25) as r:return r.status,json.loads(r.read()),r.headers
  except urllib.error.HTTPError as e:return e.code,json.loads(e.read()),e.headers
 def q(text):return sql('USE '+DB+';'+text)
 def prepare():
  output=cli('prepare-owner-setup.php');found=re.search(r'([A-F0-9]{8}(?:-[A-F0-9]{8}){7})',output);assert found
  code=found.group(1);codes.append(code);data=json.loads(setupfile.read_text())
  assert data['code_hash']==hashlib.sha256(code.replace('-','').encode()).hexdigest()
  assert code.replace('-','') not in setupfile.read_text()
  return code
 def body(code,**extra):return dict(username='firstowner',display_name='First Owner',password='é'*12,confirm_password='é'*12,setup_code=code,**extra)
 def reset():sql('DROP DATABASE '+DB);cli('install.php')
 try:
  for _ in range(50):
   try:assert call('setup/status')[0]==200;break
   except urllib.error.URLError:time.sleep(.1)
  assert call('setup/status')[1]['data']==dict(needed=True,ready=False)
  code=prepare();assert call('setup/status')[1]['data']==dict(needed=True,ready=True)
  r=subprocess.run([PHP,'tools/preflight.php','--allow-owner-setup'],env=ENV,capture_output=True,text=True);assert r.returncode==0,r.stderr
  assert 'OWNER_MISSING' in cli('preflight.php',ok=False)
  saved=setupfile.read_text();c=json.loads(saved);c['expires_at']=1;setupfile.write_text(json.dumps(c))
  assert not call('setup/status')[1]['data']['ready'];assert call('setup/owner',body(code))[0]==403;setupfile.write_text(saved)
  mismatch=body(code);mismatch['confirm_password']='different';assert call('setup/owner',mismatch)[0]==422
  assert q('SELECT COUNT(*) FROM users')=='0' and q('SELECT COUNT(*) FROM login_limits')=='0'
  for value in ['a'*11,'é'*11,'😀'*11,'x'*73,'😀'*19,'Valid-Length\0Password']:
   candidate=body(code);candidate.update(password=value,confirm_password=value)
   assert call('setup/owner',candidate)[0]==422
   assert q('SELECT COUNT(*) FROM users')=='0' and q('SELECT COUNT(*) FROM login_limits')=='0'
  passed('password policy: initial Owner setup rejects short Unicode, oversized and NUL passwords without writes')
  for invalid in ['0'*64,None,[],'x'*101,'bad']:
   payload=body(code);payload['setup_code']=invalid;assert call('setup/owner',payload)[0] in [403,422]
  limited=call('setup/owner',body(code));assert limited[0]==429 and 1<=int(limited[2]['Retry-After'])<=900
  assert call('setup/owner',body(code),{'X-Forwarded-For':'203.0.113.9','X-Real-IP':'203.0.113.9'})[0]==429
  code=prepare();assert call('setup/owner',body(code))[0]==429
  q('UPDATE login_limits SET window_started=DATE_SUB(NOW(),INTERVAL 16 MINUTE)')
  bucket=hashlib.sha256(b'owner-setup:global').hexdigest()
  q(f"INSERT INTO login_limits(bucket,failures,window_started) VALUES('{bucket}',30,NOW()) ON DUPLICATE KEY UPDATE failures=30,window_started=NOW()")
  assert call('setup/owner',body(code))[0]==429;q('DELETE FROM login_limits')
  q("CREATE TRIGGER setup_late_failure BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test setup audit failure'")
  assert call('setup/owner',body(code))[0]==500;assert q('SELECT COUNT(*) FROM users')=='0';q('DROP TRIGGER setup_late_failure')
  with ThreadPoolExecutor(8) as ex:results=list(ex.map(lambda _:call('setup/owner',body(code,role='BARISTA')),range(8)))
  assert sorted(r[0] for r in results)==[201]+[409]*7
  assert q('SELECT role FROM users')=='OWNER' and q('SELECT COUNT(*) FROM sessions')=='0'
  assert q("SELECT COUNT(*) FROM audit_events WHERE action='OWNER_PROVISIONED'")=='1'
  assert call('setup/status')[1]['data']==dict(needed=False,ready=False)
  assert call('login',{'username':'firstowner','password':'é'*12})[0]==200
  assert 'OWNER_EXISTS' in cli('prepare-owner-setup.php',ok=False)
  q('UPDATE users SET is_active=0');assert not call('setup/status')[1]['data']['needed']
  q('DELETE FROM sessions;DELETE FROM users');assert not call('setup/status')[1]['data']['needed']
  assert 'OWNER_EXISTS' in cli('create-owner.php','otherowner\nOther Owner\nOther-Owner-Only-42\nOther-Owner-Only-42\n',ok=False)
  reset();code=prepare()
  with ThreadPoolExecutor(2) as ex:
   web=ex.submit(call,'setup/owner',body(code))
   console=ex.submit(subprocess.run,[PHP,'tools/create-owner.php'],input='consoleowner\nConsole Owner\nConsole-Owner-Only-42\nConsole-Owner-Only-42\n',text=True,capture_output=True,env=ENV)
   w=web.result();c=console.result()
  assert (w[0],c.returncode) in [(201,1),(409,0)] and q('SELECT COUNT(*) FROM users')=='1'
  q("UPDATE users SET role='BARISTA',is_active=0;DELETE FROM audit_events")
  assert call('setup/owner',body(code))[0]==409
  reset();code=prepare()
  if os.environ.get('VV_BROWSER_TEST')=='1':
   r=subprocess.run(['node','tests/setup-browser.mjs','http://127.0.0.1:18087'],env={**ENV,'VV_TEST_SETUP_CODE':code});assert r.returncode==0
  logs=(ROOT/'storage/app.log').read_text();assert all(c not in logs and c.replace('-','') not in logs for c in codes)
  assert 'Setup-Owner-Only-42' not in logs
  passed('Tasks 1–2: setup hash/expiry, confirmation, attempt/global limits, proxy spoof denial, atomic rollback, concurrent browser/CLI creation, server-assigned role and permanent closure')
 finally:
  os.killpg(proc.pid,signal.SIGTERM);proc.wait(timeout=10);log5.close();reset()
setup_security()
