from pathlib import Path
import subprocess,os,tempfile,time,signal,re,hashlib,urllib.request,urllib.error
# Run against an isolated unpacked MariaDB runtime, not the shop's database.
# Set MYSQL_BASE to its usr directory, PHP to php.exe/php, and MYSQLD/MYSQL
# if those executable paths differ from the Unix defaults below. Linux test harness.
app=Path(__file__).resolve().parents[1]
runtime=Path(os.environ['MYSQL_BASE']);php=os.environ.get('PHP','php')
env=os.environ.copy()
with tempfile.TemporaryDirectory(prefix='nearby-support-test-') as folder:
 data=Path(folder)/'db'
 p=subprocess.run([str(runtime/'bin/mariadb-install-db'),'--basedir='+str(runtime),'--datadir='+str(data),'--auth-root-authentication-method=normal','--skip-test-db','--user=root'],env=env,capture_output=True,text=True);assert p.returncode==0,'Disposable DB init failed'
 db=subprocess.Popen([os.environ.get('MYSQLD',str(runtime/'sbin/mariadbd')),'--no-defaults','--user=root','--basedir='+str(runtime),'--datadir='+str(data),'--socket=','--pid-file='+str(data/'server.pid'),'--port=3309','--bind-address=127.0.0.1','--log-error='+str(data/'server.log')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 name='nearby_owner_support_test';config=Path(folder)/'config.php';config.write_text("<?php return ['db'=>['host'=>'127.0.0.1','port'=>3309,'name'=>'"+name+"','user'=>'root','password'=>''],'session_hours'=>8];");env['VV_REBUILD_CONFIG']=str(config)
 def sql(q):return subprocess.check_output([os.environ.get('MYSQL',str(runtime/'bin/mariadb')),'--no-defaults','--protocol=tcp','-h127.0.0.1','-P3309','-uroot','-N','-B'],input=q,text=True,env=env,stderr=subprocess.PIPE).strip()
 def q(s):return sql('USE '+name+';'+s)
 def cli(file,body=''):return subprocess.run([php,'tools/'+file],cwd=app,input=body,capture_output=True,text=True,env=env)
 def snap():return '\n'.join(q('SELECT * FROM `'+t+'` ORDER BY 1') for t in q('SHOW TABLES').splitlines())
 def reset(body):return cli('support-reset-owner.php',body)
 try:
  for _ in range(80):
   try:sql('SELECT 1');break
   except subprocess.CalledProcessError:time.sleep(.1)
  else:raise RuntimeError('Disposable server startup failed')
  assert cli('install.php').returncode==0
  assert cli('create-owner.php','testowner\nTest Owner\nOld-Owner-Test-42\nOld-Owner-Test-42\n').returncode==0
  assert cli('create-barista.php','testowner\nOld-Owner-Test-42\ntestbarista\nTest Barista\nStaff-Test-Only-42\nStaff-Test-Only-42\n').returncode==0
  cli('recovery-code.php','testowner\nOld-Owner-Test-42\n')
  q("INSERT INTO sessions(token_hash,user_id,created_at,expires_at) VALUES(REPEAT('a',64),1,NOW(),DATE_ADD(NOW(),INTERVAL 1 HOUR)),(REPEAT('b',64),2,NOW(),DATE_ADD(NOW(),INTERVAL 1 HOUR))")
  q("INSERT INTO products(name,category,is_active,is_vat_exempt,created_at,updated_at) VALUES('Keep this product','Coffee',1,0,NOW(),NOW())")
  before=snap()
  for body in ['testbarista\n','unknownowner\n','testowner\nCANCEL\n','testowner\nRESET testowner\nshort\nshort\n','testowner\nRESET testowner\nA-Valid-Test-Password-42\nMismatch\n']:
   reset(body);assert snap()==before,'Validation/cancellation changed records'
  q('UPDATE users SET is_active=0 WHERE id=1');inactive=snap();assert reset('testowner\n').returncode!=0 and snap()==inactive;q('UPDATE users SET is_active=1 WHERE id=1')
  print('PASS wrong/missing/non-Owner/inactive target, cancellation, password validation and confirmation preserve records',flush=True)
  before=snap();q("CREATE TRIGGER support_audit_fault BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected support audit fault'")
  body='testowner\nRESET testowner\nNew-Owner-Test-Only-42\nNew-Owner-Test-Only-42\n'
  assert reset(body).returncode!=0 and snap()==before,'Audit failure did not roll back';q('DROP TRIGGER support_audit_fault')
  print('PASS injected audit failure rolls back password, code, sessions and limits',flush=True)
  staff_before=q('SELECT * FROM users WHERE id=2');domain=q('SELECT * FROM products');r=reset(body);assert r.returncode==0
  code=re.search(r'Recovery code: ([A-F0-9-]{71})',r.stdout).group(1)
  assert q('SELECT code_hash FROM owner_recovery WHERE user_id=1')==hashlib.sha256(code.replace('-','').encode()).hexdigest()
  assert q('SELECT COUNT(*) FROM sessions WHERE user_id=1')=='0' and q('SELECT COUNT(*) FROM sessions WHERE user_id=2')=='1'
  assert q('SELECT * FROM users WHERE id=2')==staff_before and q('SELECT * FROM products')==domain
  assert q("SELECT COUNT(*) FROM audit_events WHERE action='OWNER_SUPPORT_RECOVERED'")=='1'
  probe="require 'app/recovery.php'; $d=connection(); $u=recovery_owner($d,'testowner'); echo password_verify('New-Owner-Test-Only-42',$u['password_hash'])?'PASS':'FAIL';"
  p=subprocess.run([php,'-r',probe],cwd=app,env=env,capture_output=True,text=True);assert p.stdout=='PASS'
  rows=snap();logs=(app/'storage/app.log').read_text()
  for secret in ['New-Owner-Test-Only-42',code,code.replace('-','')]:assert secret not in rows and secret not in logs
  print('PASS new password verifies, code stored hash-only, Owner sessions revoked, staff preserved, product retained and support audit recorded; no secrets in DB/logs',flush=True)
  server=subprocess.Popen([php,'-S','127.0.0.1:18089','-t','tools'],cwd=app,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,start_new_session=True)
  try:
   for _ in range(30):
    try:urllib.request.urlopen('http://127.0.0.1:18089/support-reset-owner.php');raise AssertionError('Tool exposed over HTTP')
    except urllib.error.HTTPError as e:assert e.code==404;break
    except urllib.error.URLError:time.sleep(.1)
   else:raise AssertionError('HTTP guard test unavailable')
  finally:os.killpg(server.pid,signal.SIGTERM);server.wait()
  print('PASS HTTP access rejected with 404; all requested support-tool checks passed',flush=True)
 finally:
  db.terminate();db.wait(timeout=10)
