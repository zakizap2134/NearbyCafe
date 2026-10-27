"""Linux-only real Apache/PHP integration fixture. Run with isolated test runtime paths.
See LAUNCHER_VERIFICATION.md. Never use a client database directory.
"""
from pathlib import Path
import os,subprocess,tempfile,time,urllib.request,urllib.error,json,shutil,re,uuid,http.client
source=Path(__file__).resolve().parents[1];runtime=Path(os.environ['VV_TEST_RUNTIME_USR']);apacheRoot=Path(os.environ['VV_TEST_APACHE_ROOT']);php=os.environ['PHP'];pwsh=os.environ['PWSH'];httpd=apacheRoot/'usr/sbin/apache2'
env={**os.environ,'LD_LIBRARY_PATH':str(apacheRoot/'usr/lib/x86_64-linux-gnu')+':'+str(runtime/'lib/x86_64-linux-gnu'),'VV_CHROMIUM_PATH':os.environ['VV_CHROMIUM_PATH']}
# Refuse occupied test ports before any database command.
import socket
for port in (3310,18091,18575):
 with socket.socket() as guard: guard.bind(('127.0.0.1',port))
base='http://127.0.0.1:18091';results=[]
def passed(s):results.append('PASS '+s);print(results[-1],flush=True)
with tempfile.TemporaryDirectory(prefix='nearby-apache-') as folder:
 home=Path(folder);home.chmod(0o755);app=home/'Shop Files/rebuild';app.parent.mkdir();shutil.copytree(source,app,ignore=shutil.ignore_patterns('node_modules','test-results','local.php','*.log','__pycache__'))
 (app/'storage/launcher').mkdir(exist_ok=True);(app/'storage').chmod(0o777);(app/'storage/launcher').chmod(0o777)
 data=home/'database';config=app/'config/local.php';config.write_text("<?php return ['db'=>['host'=>'127.0.0.1','port'=>3310,'name'=>'nearby_launcher_test','user'=>'root','password'=>''],'session_hours'=>8];")
 (app/'config/launcher.local.json').write_text(json.dumps({'InstallationId':'a'*32}));env['VV_REBUILD_CONFIG']=str(config)
 init=subprocess.run([str(runtime/'bin/mariadb-install-db'),'--basedir='+str(runtime),'--datadir='+str(data),'--auth-root-authentication-method=normal','--skip-test-db','--user=root'],env=env,capture_output=True,text=True);assert init.returncode==0,init.stderr
 db=subprocess.Popen([str(runtime/'sbin/mariadbd'),'--no-defaults','--user=root','--basedir='+str(runtime),'--datadir='+str(data),'--socket=','--pid-file='+str(data/'server.pid'),'--port=3310','--bind-address=127.0.0.1','--log-error='+str(data/'server.log')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 def sql(s):return subprocess.check_output([str(runtime/'bin/mariadb'),'--no-defaults','--protocol=tcp','-h127.0.0.1','-P3310','-uroot','-N','-B'],input=s,text=True,env=env,stderr=subprocess.PIPE).strip()
 def cli(name,body='',args=[]):return subprocess.run([php,'tools/'+name,*args],cwd=app,env=env,input=body,capture_output=True,text=True)
 def snapshot():return '\n'.join(sql('USE nearby_launcher_test;SELECT * FROM `'+t+'` ORDER BY 1') for t in sql('USE nearby_launcher_test;SHOW TABLES').splitlines())
 web=None;vite=None
 try:
  for _ in range(80):
   try:
    assert db.poll() is None,'Disposable database did not start'
    sql('SELECT 1');break
   except subprocess.CalledProcessError:time.sleep(.1)
  assert cli('install.php').returncode==0
  assert cli('create-owner.php','launcherowner\nLauncher Owner\nLauncher-Test-Only-42\nLauncher-Test-Only-42\n').returncode==0
  assert cli('create-barista.php','launcherowner\nLauncher-Test-Only-42\nlauncherbarista\nLauncher Barista\nBarista-Test-Only-42\nBarista-Test-Only-42\n').returncode==0
  before=snapshot()
  assert cli('preflight.php').returncode==0
  for cmd in ['config','database','ready']:
   result=cli('launcher-probe.php',args=[cmd]);assert result.returncode==0,result.stdout;assert json.loads(result.stdout)['ok'];assert 'password' not in result.stdout
  assert snapshot()==before;passed('real preflight and launcher probes are read-only and omit credentials')
  script=home/'render.ps1';script.write_text("Import-Module '"+str(source/'launcher/Setup.Helpers.psm1')+"'; New-NearbyApacheConfig '"+str(app)+"' '"+str(home/'xampp')+"' 18091 $true",encoding='utf-8-sig')
  conftext=subprocess.check_output([pwsh,'-NoProfile','-File',str(script)],text=True).replace(str(home/'xampp/apache'),str(apacheRoot/'usr/lib/apache2')).replace(str(home/'xampp/php/php.exe'),php)
  conftext=re.sub(r'^LoadFile .*\n','',conftext,flags=re.M)
  conftext=re.sub(r'^LoadModule php_module .*$',f'LoadModule php_module "{apacheRoot}/usr/lib/apache2/modules/libphp8.3.so"',conftext,flags=re.M)
  ini=home/'php';ini.mkdir();(ini/'php.ini').write_text('extension_dir="'+str(runtime/'lib/php/20230831')+'"\nextension=mysqlnd\nextension=pdo\nextension=pdo_mysql\ndisplay_errors=Off\nlog_errors=Off\n')
  conftext=re.sub(r'^PHPIniDir .*$',f'PHPIniDir "{ini}"',conftext,flags=re.M)
  (home/'mime.types').write_text('text/html html\napplication/javascript js\ntext/css css\nimage/png png\nimage/svg+xml svg\nimage/x-icon ico\nfont/woff2 woff2\n')
  conftext=re.sub(r'^TypesConfig .*$',f'TypesConfig "{home}/mime.types"',conftext,flags=re.M)
  conftext=re.sub(r'^(ThreadsPerChild|AcceptFilter) .*\n','',conftext,flags=re.M)
  conftext='LoadModule mpm_prefork_module "'+str(apacheRoot/'usr/lib/apache2/modules/mod_mpm_prefork.so')+'"\nUser nobody\nGroup nogroup\n'+conftext
  conf=home/'httpd.conf';conf.write_text(conftext)
  checked=subprocess.run([str(httpd),'-t','-f',str(conf)],env=env,capture_output=True,text=True);assert checked.returncode==0,checked.stderr
  logfile=open(home/'apache-console.log','w');web=subprocess.Popen([str(httpd),'-f',str(conf),'-DFOREGROUND'],env=env,stdout=logfile,stderr=logfile,start_new_session=True)
  def request(path,body=None,token=None,headers=None):
   hs={'Content-Type':'application/json',**(headers or {})}
   if token:hs['Authorization']='Bearer '+token
   req=urllib.request.Request(base+path,data=json.dumps(body).encode() if body is not None else None,headers=hs)
   try:
    with urllib.request.urlopen(req,timeout=15) as r:return r.status,r.read(),r.headers
   except urllib.error.HTTPError as e:return e.code,e.read(),e.headers
  for _ in range(60):
   try:
    if request('/launcher-health.php')[0]==200:break
   except urllib.error.URLError:pass
   time.sleep(.1)
  else:raise AssertionError('Readiness failed: '+(home/'apache-console.log').read_text())
  status,body,headers=request('/launcher-health.php');ready=json.loads(body);assert ready['installation']=='a'*32 and ready['epoch'];assert headers['Cache-Control']=='no-store';assert snapshot()==before
  assert request('/')[0]==200
  passed('Apache serves root index and built assets from path with spaces; private instance/epoch readiness matches')
  for path in ['/config/local.php','/app/bootstrap.php','/tools/launcher-probe.php','/database/001_foundation.sql','/storage/app.log','/.git/config','/api/index.php/extra','/assets/','/api/']:
   status,_,_=request(path);assert status in [403,404],(path,status)
  for path in ['accidental.php','assets/accidental.php']:
   (app/'public'/path).write_text('<?php echo "DO_NOT_SERVE";');status,body,_=request('/'+path);assert status==403 and b'DO_NOT_SERVE' not in body
  c=http.client.HTTPConnection('127.0.0.1',18091,source_address=('127.0.0.2',0),timeout=5);c.request('GET','/launcher-health.php');res=c.getresponse();assert res.status in [403,404];res.read();c.close()
  passed('private files, directory listings, stray PHP and unapproved readiness source blocked')
  def api(route,body=None,token=None):
   status,raw,_=request('/api/index.php?route='+route,body,token,{'Origin':base});assert status==200,(route,status,raw);return json.loads(raw)['data']
  owner=api('login',{'username':'launcherowner','password':'Launcher-Test-Only-42'});staff=api('login',{'username':'launcherbarista','password':'Barista-Test-Only-42'})
  assert api('session',token=owner['token'])['user']['role']=='OWNER'
  def save(action,**extra):return api('management/save',{'action':action,**extra},owner['token'])['id']
  ingredient=save('ingredient',name='Launcher milk',unit='mL',minimum_stock='10',opened_shelf_life_hours=48,is_active=1)
  product=save('product',name='Launcher latte',category='Test',is_active=1)
  variant=save('variant',product_id=product,size='M',temperature='HOT',sku='LAUNCHER-M',price='112.00',is_active=1)
  save('recipe',variant_id=variant,rows=[{'ingredient_id':ingredient,'qty':'100'}]);save('receive',ingredient_id=ingredient,delivery_key=str(uuid.uuid4()),expected_epoch=ready['epoch'],container_count=1,quantity_per_container='1000',expiration_date='2099-12-31',reference='Disposable fixture')
  sale={'transaction_key':str(uuid.uuid4()),'expected_epoch':ready['epoch'],'cash_received':'200.00','items':[{'variant_id':variant,'qty':1,'expected_price':'112.00'}]}
  receipt=api('checkout',sale,staff['token']);assert (receipt['total_gross'],receipt['total_net'],receipt['total_vat'])==('112.00','100.00','12.00');assert api('checkout',sale,staff['token'])['duplicate'];assert sql('USE nearby_launcher_test;SELECT current_stock FROM ingredients')=='900.000'
  prepared=api('management/recovery-code/status',token=owner['token']);generated=api('management/recovery-code/generate',{'owner_password':'Launcher-Test-Only-42','expected_version':prepared['version'],'confirm_replace':False},owner['token']);assert 'recovery_code' in generated
  passed('Apache: existing Owner/Barista sign-in, bearer authorization, same-origin recovery preparation, checkout VAT and exactly-once stock deduction')
  (app/'node_modules').symlink_to(source/'node_modules',target_is_directory=True)
  def browser(url):
   p=subprocess.run(['node','tests/launcher-browser.mjs',url],cwd=app,env={**env,'VV_LAUNCHER_LOGIN':'1'},capture_output=True,text=True);assert p.returncode==0,p.stdout+p.stderr;results.extend(p.stdout.strip().splitlines());print(p.stdout,flush=True)
  browser(base)
  # Real Vite configuration/plugins, proxy override only for this isolated backend port.
  (app/'launcher-vite-test.mjs').write_text("import {createServer} from 'vite'; const s=await createServer({server:{host:'127.0.0.1',port:18575,proxy:{'/api':{target:'"+base+"',changeOrigin:false}}}});await s.listen();")
  vite=subprocess.Popen(['node','launcher-vite-test.mjs'],cwd=app,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  for _ in range(60):
   try:urllib.request.urlopen('http://127.0.0.1:18575',timeout=1);break
   except urllib.error.URLError:time.sleep(.2)
  browser('http://127.0.0.1:18575');passed('favicon and frontend work through actual Vite dev server as well as production Apache')
  vite.terminate();vite.wait(timeout=10);vite=None
  domain=snapshot();stop=subprocess.run([str(httpd),'-f',str(conf),'-k','graceful-stop'],env=env,capture_output=True,text=True);assert stop.returncode==0,stop.stderr;web.wait(timeout=15);web=None;assert snapshot()==domain
  # Explicit secret-containing synthetic Apache message must become code-only output.
  subprocess.run([php,'tools/launcher-log.php'],cwd=app,env=env,input='[error] AH01630 synthetic-secret-token /?password=secret\n',text=True,check=True)
  logs=''.join(p.read_text() for p in (app/'storage').rglob('*.log'))
  for secret in ['synthetic-secret-token','password=secret','Launcher-Test-Only-42',staff['token'],generated['recovery_code']]:assert secret not in logs
  assert 'AH01630' in logs
  passed('graceful Apache shutdown preserves all database records; diagnostics filter passwords/tokens/codes/URLs')
 finally:
  if vite:vite.terminate();vite.wait(timeout=10)
  if web:subprocess.run([str(httpd),'-f',str(conf),'-k','graceful-stop'],env=env,capture_output=True);web.wait(timeout=15)
  try:sql('SHUTDOWN')
  except subprocess.CalledProcessError:pass
  db.wait(timeout=15)
(source/'tests/LAUNCHER_APACHE_RESULTS.txt').write_text('\n'.join(results)+'\n')
