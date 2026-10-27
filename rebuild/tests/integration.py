"""Rebuild Stages 1–5 gates. Uses ONLY venuevue_rebuild_test on a disposable MySQL server.
Set MYSQL / PHP executable paths and VV_TEST_DB_PORT (default 3307).
VV_TEST_START_DB=1 optionally starts the isolated runtime specified by MYSQLD,
MYSQL_BASE and MYSQL_DATA. Never point these at a client database directory.
"""
import os, pathlib, subprocess, tempfile, json, time, urllib.request, urllib.error, signal
ROOT=pathlib.Path(__file__).resolve().parents[1]
os.chdir(ROOT)
PHP=os.environ.get('PHP','php'); MYSQL=os.environ.get('MYSQL','mysql')
PORT=os.environ.get('VV_TEST_DB_PORT','3307'); BASE='http://127.0.0.1:18081'
DB='venuevue_rebuild_test'; processes=[]; temp=tempfile.TemporaryDirectory(prefix='vv-stage1-')
SCOPE=os.environ.get('VV_TEST_SCOPE','all')
if SCOPE not in ('all','regression','recovery'):raise ValueError('VV_TEST_SCOPE must be all, regression or recovery')
ENV=os.environ.copy()
if os.name=='posix':ENV['PHP_CLI_SERVER_WORKERS']='4'
config=pathlib.Path(temp.name)/'config.php'
config.write_text("<?php return ['db'=>['host'=>'127.0.0.1','port'=>"+PORT+",'name'=>'"+DB+"','user'=>'root','password'=>''],'session_hours'=>8];")
ENV['VV_REBUILD_CONFIG']=str(config)
def sql(text):
 return subprocess.check_output([MYSQL,'--no-defaults','--protocol=tcp','-h127.0.0.1','-P'+PORT,'-uroot','-N','-B'],input="SET time_zone='+08:00';"+text,text=True,env=ENV,stderr=subprocess.PIPE).strip()
def cli(file,body='',ok=True,env=None):
 p=subprocess.run([PHP,'tools/'+file],input=body,text=True,capture_output=True,env=env or ENV)
 assert (p.returncode==0)==ok,(file,p.stdout,p.stderr)
 return p.stdout+p.stderr
def request(route,body=None,token=None,method=None,raw=None):
 headers={}
 if token:headers['Authorization']='Bearer '+token
 data=None
 if body is not None:headers['Content-Type']='application/json';data=json.dumps(body).encode()
 if raw is not None:headers['Content-Type']='application/json';data=raw
 req=urllib.request.Request(BASE+'/api/index.php?route='+route,data=data,headers=headers,method=method)
 try:
  with urllib.request.urlopen(req,timeout=20) as r:return r.status,json.loads(r.read())
 except urllib.error.HTTPError as e:return e.code,json.loads(e.read())
def expect(result,status):assert result[0]==status,result;return result[1]
def passed(message):print('PASS '+message,flush=True)
def login(name='testowner',password='Owner-Test-Only-42'):return request('login',{'username':name,'password':password})
try:
 if os.environ.get('VV_TEST_START_DB')=='1':
  proc=subprocess.Popen([os.environ['MYSQLD'],'--no-defaults','--user=root','--basedir='+os.environ['MYSQL_BASE'],'--datadir='+os.environ['MYSQL_DATA'],'--socket=','--pid-file='+os.environ['MYSQL_DATA']+'/stage1.pid','--port='+PORT,'--bind-address=127.0.0.1','--log-error='+os.environ['MYSQL_DATA']+'/stage1.log'],env=ENV,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);processes.append(proc)
 for _ in range(80):
  try:sql('SELECT 1');break
  except subprocess.CalledProcessError:time.sleep(.1)
 else:raise RuntimeError('Disposable database server did not start')
 sql('DROP DATABASE IF EXISTS '+DB)
 missing={**ENV,'VV_REBUILD_CONFIG':str(pathlib.Path(temp.name)/'missing.php')}
 assert 'CONFIG_MISSING' in cli('preflight.php',ok=False,env=missing)
 assert 'DB_NOT_INSTALLED' in cli('preflight.php',ok=False)
 cli('install.php');assert 'OWNER_MISSING' in cli('preflight.php',ok=False)
 if SCOPE!='recovery' and os.environ.get('VV_RELEASE_TEST')=='1':
  exec(compile((ROOT/'tests/setup-security.py').read_text(),str(ROOT/'tests/setup-security.py'),'exec'))
 cli('create-owner.php','testowner\nTest Owner\nOwner-Test-Only-42\nOwner-Test-Only-42\n')
 assert 'OWNER_EXISTS' in cli('create-owner.php','anotherowner\nOther\nOther-Test-Only-42\nOther-Test-Only-42\n',ok=False)
 assert 'OWNER_AUTH' in cli('create-barista.php','testowner\nwrong\ntestbarista\nTest Barista\nStaff-Test-Only-42\nStaff-Test-Only-42\n',ok=False)
 cli('create-barista.php','testowner\nOwner-Test-Only-42\ntestbarista\nTest Barista\nStaff-Test-Only-42\nStaff-Test-Only-42\n')
 cli('install.php');cli('preflight.php')
 assert sql('USE '+DB+';SELECT COUNT(*) FROM users')=='2'
 sql('USE '+DB+';CREATE TABLE legacy_sentinel(id INT)')
 assert 'DATABASE_NOT_EMPTY' in cli('install.php',ok=False)
 sql('USE '+DB+';DROP TABLE legacy_sentinel')
 passed('fresh setup, missing-config/database/owner diagnostics, safe rerun, no owner reprovision, owner-authorized staff creation')
 log=open(pathlib.Path(temp.name)/'php.log','w')
 ENV['VV_TEST_HTTP_LOG']=str(pathlib.Path(temp.name)/'php.log')
 server=subprocess.Popen([PHP,'-S','127.0.0.1:18081','-t','public','tools/router.php'],env=ENV,stdout=log,stderr=log,start_new_session=True);processes.append(server)
 for _ in range(40):
  try:expect(request('health'),200);break
  except urllib.error.URLError:time.sleep(.1)
 expect(request('session'),401);expect(request('management/status'),401)
 expect(request('login',method='GET'),405);expect(request('missing'),404)
 expect(request('login',raw=b'{'),400);expect(request('login',raw=b'[]'),400);expect(request('login',raw=b'x'*8200),413)
 owner=expect(login(),200)['data'];staff=expect(login('testbarista','Staff-Test-Only-42'),200)['data']
 assert owner['user']['role']=='OWNER' and staff['user']['role']=='BARISTA'
 assert 'password_hash' not in json.dumps(owner)
 for data in [owner,staff]:
  expect(request('session',token=data['token']),200);expect(request('register/status',token=data['token']),200)
 expect(request('management/status',token=staff['token']),403);expect(request('management/status',token=owner['token']),200)
 forged=expect(request('login',{'username':'testbarista','password':'Staff-Test-Only-42','role':'OWNER'}),200)['data']
 assert forged['user']['role']=='BARISTA'
 passed('authenticated login, server-authoritative roles, direct API denial and request validation')
 for path in ['/config/local.php','/app/bootstrap.php','/tools/create-owner.php','/database/001_foundation.sql','/storage/app.log']:
  try:urllib.request.urlopen(BASE+path);raise AssertionError('Private path served: '+path)
  except urllib.error.HTTPError as e:assert e.code==404
 passed('development server exposes only public assets and API')
 expect(request('logout',{},staff['token']),200);expect(request('session',token=staff['token']),401)
 sql("USE "+DB+";UPDATE sessions SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE user_id=1")
 expect(request('session',token=owner['token']),401)
 owner=expect(login(),200)['data']
 sql('USE '+DB+';UPDATE users SET is_active=0 WHERE id=1')
 expect(request('management/status',token=owner['token']),401)
 sql('USE '+DB+';UPDATE users SET is_active=1 WHERE id=1')
 passed('logout revokes token; expired and deactivated accounts cannot use APIs')
 for _ in range(5):expect(login('testbarista','wrong'),401)
 expect(login('testbarista','Staff-Test-Only-42'),429)
 sql('USE '+DB+';DELETE FROM login_limits')
 before=sql('USE '+DB+';SELECT COUNT(*) FROM sessions')
 sql("USE "+DB+";CREATE TRIGGER stage1_audit_fault BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected audit failure'")
 failed=expect(login(),500);assert failed['error']['reference'] and 'Injected' not in json.dumps(failed)
 assert sql('USE '+DB+';SELECT COUNT(*) FROM sessions')==before
 sql('USE '+DB+';DROP TRIGGER stage1_audit_fault')
 passed('login throttling and audit failure rolls back token creation without leaking SQL')
 sql('USE '+DB+';RENAME TABLE sessions TO sessions_test_hidden')
 expect(request('health'),503);assert 'SCHEMA_MISSING' in cli('preflight.php',ok=False)
 sql('USE '+DB+';RENAME TABLE sessions_test_hidden TO sessions')
 expect(request('health'),200)
 text=(ROOT/'storage/app.log').read_text()
 assert 'Owner-Test-Only-42' not in text and 'Staff-Test-Only-42' not in text and 'Injected audit failure' not in text
 passed('partial schema returns actionable diagnostics; logs omit secrets and SQL values')
 exec(compile((ROOT/'tests/stage2_cases.py').read_text(),str(ROOT/'tests/stage2_cases.py'),'exec'))
 if SCOPE!='recovery':
  exec(compile((ROOT/'tests/stage3_cases.py').read_text(),str(ROOT/'tests/stage3_cases.py'),'exec'))
  if os.environ.get('VV_BROWSER_TEST')=='1':
   p=subprocess.run(['node','tests/browser.mjs',BASE],env=ENV);assert p.returncode==0
   p=subprocess.run(['node','tests/stage2-browser.mjs',BASE],env=ENV);assert p.returncode==0
   p=subprocess.run(['node','tests/stage3-browser.mjs',BASE],env=ENV);assert p.returncode==0
  exec(compile((ROOT/'tests/stage4_cases.py').read_text(),str(ROOT/'tests/stage4_cases.py'),'exec'))
  if os.environ.get('VV_BROWSER_TEST')=='1':
   p=subprocess.run(['node','tests/stage4-browser.mjs',BASE],env=ENV);assert p.returncode==0
  if os.environ.get('VV_BROWSER_TEST')=='1':
   p=subprocess.run(['node','tests/stage5-browser.mjs',BASE],env=ENV);assert p.returncode==0
  if os.environ.get('VV_RELEASE_TEST')=='1':
   p=subprocess.run(['node','tests/accounts-browser.mjs',BASE],env=ENV);assert p.returncode==0
 if SCOPE!='regression' and os.environ.get('VV_BROWSER_RECOVERY_TEST')=='1':
  exec(compile((ROOT/'tests/browser_recovery_cases.py').read_text(),str(ROOT/'tests/browser_recovery_cases.py'),'exec'))
 if SCOPE!='regression' and os.environ.get('VV_RECOVERY_PREPARATION_TEST')=='1':
  exec(compile((ROOT/'tests/recovery_preparation_cases.py').read_text(),str(ROOT/'tests/recovery_preparation_cases.py'),'exec'))
 if os.environ.get('VV_PASSWORD_POLICY_TEST')=='1':
  exec(compile((ROOT/'tests/password_policy_cases.py').read_text(),str(ROOT/'tests/password_policy_cases.py'),'exec'))
 print('ALL REQUESTED AUTOMATED CHECKS PASSED — scope: '+SCOPE,flush=True)
finally:
 for p in reversed(processes):
  try:
   if p is locals().get('server'):os.killpg(p.pid,signal.SIGTERM)
   else:p.terminate()
   p.wait(timeout=8)
  except (ProcessLookupError,subprocess.TimeoutExpired):pass
 temp.cleanup()
