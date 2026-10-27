"""Task 1 API checks inside integration.py, using its disposable database only."""

def browser_recovery_checks():
 import hashlib, re, socket, threading
 from concurrent.futures import ThreadPoolExecutor
 password='Owner-Test-Only-42';newpass='Browser-Recovered-Only-42';secrets=[password,newpass]
 route='/api/index.php?route=recovery%2Fowner'
 def q(statement):return sql('USE '+DB+';'+statement)
 def digest(value):return hashlib.sha256(value.encode()).hexdigest()
 def call(body=None,origin=BASE,headers=None,method='POST',raw=None,base=BASE,content_type='application/json',endpoint=route):
  hs={'Content-Type':content_type,**(headers or {})}
  if origin is not None:hs['Origin']=origin
  data=raw if raw is not None else json.dumps(body or {}).encode()
  req=urllib.request.Request(base+endpoint,data=data if method=='POST' else None,headers=hs,method=method)
  try:
   with urllib.request.urlopen(req,timeout=20) as r:return r.status,json.loads(r.read()),r.headers
  except urllib.error.HTTPError as e:return e.code,json.loads(e.read()),e.headers
 def payload(code,**changes):return dict(username='testowner',recovery_code=code,new_password=newpass,confirm_password=newpass,**changes)
 def issue(current=password):
  output=cli('recovery-code.php','testowner\n'+current+'\n')
  code=re.search(r'Recovery code: ([A-F0-9-]{71})',output).group(1);secrets.append(code);return code
 def clean():q('DELETE FROM login_limits')
 def private_state():return '\n'.join(q('SELECT * FROM '+table+' ORDER BY 1') for table in ['users','sessions','owner_recovery','login_limits','audit_events','audit_details','runtime_meta'])
 def assert_success(r):
  assert r[0]==200,(r[0],r[1]);data=r[1]['data']
  assert set(data)=={'reset','sessions_revoked','recovery_code'} and data['reset'] and data['sessions_revoked']
  code=data['recovery_code'];assert re.fullmatch(r'[A-F0-9]{8}(?:-[A-F0-9]{8}){7}',code)
  assert q('SELECT code_hash FROM owner_recovery WHERE user_id=1')==digest(code.replace('-',''))
  assert r[2]['Cache-Control']=='no-store' and r[2]['Referrer-Policy']=='no-referrer'
  assert r[2].get('Access-Control-Allow-Origin') is None
  secrets.append(code);return code
 clean();domain_before=snapshot();epoch_before=q('SELECT * FROM runtime_meta')
 code=issue();before=private_state()
 for origin,headers in [(None,{}),('null',{}),('http://untrusted.invalid',{}),(BASE+'/path',{}),(BASE,{'Sec-Fetch-Site':'cross-site'}),('http://untrusted.invalid',{'X-Forwarded-Host':'untrusted.invalid','X-Forwarded-Proto':'http'})]:
  assert call(payload(code),origin=origin,headers=headers)[0]==403
 assert call(payload(code),method='GET')[0]==405
 assert call(payload(code),method='OPTIONS')[0]==405
 for mime in ['text/plain','application/x-www-form-urlencoded','application/json-junk']:
  assert call(payload(code),content_type=mime)[0]==415
 for raw,status in [(b'{',400),(b'[]',400),(b'x'*8200,413)]:assert call(raw=raw)[0]==status
 for extra in [{'new_password':'é'*11,'confirm_password':'é'*11},{'new_password':'😀'*11,'confirm_password':'😀'*11},{'new_password':'a'*11,'confirm_password':'a'*11},{'new_password':'short','confirm_password':'short'},{'new_password':'x'*73,'confirm_password':'x'*73},{'new_password':'é'*37,'confirm_password':'é'*37},{'new_password':'Valid-Length\0Password','confirm_password':'Valid-Length\0Password'},{'new_password':None},{'confirm_password':'Mismatch-Only-42'},{'confirm_password':[]}]:
  b=payload(code);b.update(extra);assert call(b)[0]==422
 assert private_state()==before
 passed('browser recovery: origin/JSON/body/method guards and byte-length/confirmation validation make no credential changes')

 invalid=[]
 for name in ['doesnotexist','testbarista','testowner']:
  clean();b=payload('wrong');b['username']=name;r=call(b);assert r[0]==403;invalid.append((r[1]['error']['code'],r[1]['error']['message']))
 q('UPDATE users SET is_active=0 WHERE id=1');clean();r=call(payload(code));invalid.append((r[1]['error']['code'],r[1]['error']['message']));assert r[0]==403;q('UPDATE users SET is_active=1 WHERE id=1')
 clean();q('DELETE FROM owner_recovery WHERE user_id=1');r=call(payload(code));invalid.append((r[1]['error']['code'],r[1]['error']['message']));assert r[0]==403
 assert len(set(invalid))==1;code=issue();clean()
 for b in [payload(None),payload([]),payload('x'*101),{k:v for k,v in payload(code).items() if k!='recovery_code'},dict(payload(code),username=[])]:assert call(b)[0]==403
 clean()
 for _ in range(5):assert call(dict(payload('wrong'),username='unknownowner'))[0]==403
 r=call(dict(payload('wrong'),username='unknownowner'));assert r[0]==429 and 1<=int(r[2]['Retry-After'])<=900
 clean()
 for _ in range(5):assert call(payload('wrong'))[0]==403
 r=call(payload(code),headers={'X-Forwarded-For':'198.51.100.22'});assert r[0]==429 and int(r[2]['Retry-After'])>0
 assert 'RECOVERY_LIMIT' in cli('recover-owner.php',f'testowner\n{code}\n{newpass}\n{newpass}\n',ok=False)
 q('UPDATE login_limits SET window_started=DATE_SUB(NOW(),INTERVAL 16 MINUTE)')
 assert call(payload('wrong'))[0]==403
 clean()
 for _ in range(5):assert 'CREDENTIAL_INVALID' in cli('recover-owner.php',f'testowner\nwrong\n{newpass}\n{newpass}\n',ok=False)
 assert call(payload(code))[0]==429
 clean()
 for i in range(30):assert call(dict(payload('wrong'),username='spray'+str(i)))[0]==403
 assert call(dict(payload('wrong'),username='freshname'),headers={'X-Forwarded-For':'198.51.100.99'})[0]==429
 clean();bucket=digest('web-recovery:global');q("INSERT INTO login_limits VALUES('"+bucket+"',100,NOW())")
 assert call(payload(code))[0]==429
 clean();passed('browser recovery: generic credential failures; unknown-user, malformed-code, 5-attempt/shared-CLI, IP/global limits, expiry and proxy spoof rejection')

 oldtokens=[expect(login(),200)['data']['token'] for _ in range(2)]
 stafftoken=expect(login('testbarista','Staff-Test-Only-42'),200)['data']['token']
 before=private_state()
 q("CREATE TRIGGER recovery_task1_fault BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected recovery task1 failure'")
 r=call(payload(code));assert r[0]==500 and 'Injected' not in json.dumps(r[1]);assert private_state()==before
 assert call(payload('wrong'))[0]==500 and private_state()==before
 q('DROP TRIGGER recovery_task1_fault')
 for token in oldtokens:expect(request('session',token=token),200)
 # A submitted role/user id cannot change the recovered account or grant staff access.
 replacement=assert_success(call(dict(payload(code),role='BARISTA',user_id=2)))
 for token in oldtokens:expect(request('session',token=token),401)
 expect(request('session',token=stafftoken),200)
 assert q('SELECT COUNT(*) FROM sessions WHERE user_id=1')=='0'
 assert q('SELECT role FROM users WHERE id=1')=='OWNER'
 expect(login(),401);expect(login('testowner',newpass),200)
 beforehash=q('SELECT password_hash FROM users WHERE id=1');assert call(payload(code))[0]==403;assert q('SELECT password_hash FROM users WHERE id=1')==beforehash
 assert_success(call(dict(payload(replacement),new_password=password,confirm_password=password)))
 clean();passed('browser recovery: final-audit rollback preserves password/code/sessions/counters; success rotates code once, revokes all Owner sessions, preserves staff and grants no session')

 code=issue()
 for boundary in ['a'*12, 'é'*12, '😀'*12, 'é'*36, '😀'*18]:
  secrets.append(boundary)
  code=assert_success(call(dict(payload(code),new_password=boundary,confirm_password=boundary)))
  expect(login('testowner',boundary),200)
 code=assert_success(call(dict(payload(code),new_password=password,confirm_password=password)));clean()
 passed('browser recovery: valid 12-character and 72-byte Unicode passwords reset and sign in successfully')

 code=issue();audit_before=int(q("SELECT COUNT(*) FROM audit_events WHERE action='OWNER_RECOVERED'"));barrier=threading.Barrier(8)
 def concurrent(_):barrier.wait();return call(payload(code))
 with ThreadPoolExecutor(8) as pool:results=list(pool.map(concurrent,range(8)))
 assert sum(r[0]==200 for r in results)==1 and all(r[0] in [200,403,429] for r in results)
 replacement=assert_success(next(r for r in results if r[0]==200))
 assert all('data' not in r[1] for r in results if r[0]!=200)
 assert int(q("SELECT COUNT(*) FROM audit_events WHERE action='OWNER_RECOVERED'"))==audit_before+1
 clean();assert call(payload(code))[0]==403
 assert_success(call(dict(payload(replacement),new_password=password,confirm_password=password)));clean()
 code=issue();barrier=threading.Barrier(2);audit_before=int(q("SELECT COUNT(*) FROM audit_events WHERE action='OWNER_RECOVERED'"))
 def http_race():barrier.wait();return call(payload(code))
 def cli_race():
  barrier.wait();return subprocess.run([PHP,'tools/recover-owner.php'],input=f'testowner\n{code}\n{newpass}\n{newpass}\n',text=True,capture_output=True,env=ENV)
 with ThreadPoolExecutor(2) as pool:
  h=pool.submit(http_race);c=pool.submit(cli_race);r=h.result();p=c.result()
 assert (r[0],p.returncode) in [(200,1),(403,0)]
 assert int(q("SELECT COUNT(*) FROM audit_events WHERE action='OWNER_RECOVERED'"))==audit_before+1
 replacement=assert_success(r) if r[0]==200 else re.search(r'Recovery code: ([A-F0-9-]{71})',p.stdout).group(1)
 secrets.append(replacement);clean();assert_success(call(dict(payload(replacement),new_password=password,confirm_password=password)));clean()
 passed('browser recovery: 8 simultaneous resets and a CLI-versus-HTTP race each commit one reset with no replay of the replacement secret')

 # Commit with the response connection closed: new password works, old code cannot replay.
 code=issue();audit_before=int(q("SELECT COUNT(*) FROM audit_events WHERE action='OWNER_RECOVERED'"));data=json.dumps(payload(code)).encode()
 with socket.create_connection(('127.0.0.1',18081),timeout=5) as sock:
  sock.sendall((f'POST {route} HTTP/1.1\r\nHost: 127.0.0.1:18081\r\nOrigin: {BASE}\r\nContent-Type: application/json\r\nContent-Length: {len(data)}\r\nConnection: close\r\n\r\n').encode()+data)
 for _ in range(50):
  if int(q("SELECT COUNT(*) FROM audit_events WHERE action='OWNER_RECOVERED'"))==audit_before+1:break
  time.sleep(.1)
 else:raise AssertionError('Disconnected request did not commit')
 expect(login('testowner',newpass),200);assert call(payload(code))[0]==403
 code=issue(newpass);assert_success(call(dict(payload(code),new_password=password,confirm_password=password)));clean()
 # Exercise the real Vite proxy configuration, using isolated test ports.
 proxy_base='http://127.0.0.1:18574';proxy_config=ROOT/'.recovery-test-vite.mjs'
 proxy_config.write_text("import config from './vite.config.js';\nexport default {...config,server:{...config.server,host:'127.0.0.1',port:18574,proxy:{'/api':{...config.server.proxy['/api'],target:"+json.dumps(BASE)+"}}}};\n")
 proxy=None
 try:
  proxy_log=open(pathlib.Path(temp.name)/'vite-recovery.log','w')
  proxy=subprocess.Popen(['node','node_modules/vite/bin/vite.js','--config',str(proxy_config)],env=ENV,stdout=proxy_log,stderr=proxy_log,start_new_session=True)
  for _ in range(80):
   try:
    urllib.request.urlopen(proxy_base+'/api/index.php?route=health',timeout=1).close();break
   except (urllib.error.URLError,TimeoutError):time.sleep(.1)
  else:raise AssertionError('Recovery proxy test did not start')
  code=issue()
  assert call(payload(code),base=proxy_base,origin=BASE)[0]==403
  replacement=assert_success(call(payload(code),base=proxy_base,origin=proxy_base,headers={'Sec-Fetch-Site':'same-origin'}))
  assert_success(call(dict(payload(replacement),new_password=password,confirm_password=password)));clean()
  token=expect(login(),200)['data']['token']
  state=expect(request('management/recovery-code/status',token=token),200)['data']
  data=dict(owner_password=password,expected_version=state['version'],confirm_replace=True)
  endpoint='/api/index.php?route=management%2Frecovery-code%2Fgenerate'
  headers={'Authorization':'Bearer '+token,'Sec-Fetch-Site':'same-origin'}
  assert call(data,base=proxy_base,origin=BASE,headers=headers,endpoint=endpoint)[0]==403
  r=call(data,base=proxy_base,origin=proxy_base,headers=headers,endpoint=endpoint)
  assert r[0]==200 and r[2]['Cache-Control']=='no-store' and r[2]['Referrer-Policy']=='no-referrer'
  secrets.append(r[1]['data']['recovery_code'])
  replay=call(data,base=proxy_base,origin=proxy_base,headers=headers,endpoint=endpoint)
  assert replay[0]==409 and 'data' not in replay[1]
 finally:
  if proxy:os.killpg(proxy.pid,signal.SIGTERM);proxy.wait(timeout=10)
  proxy_log.close();proxy_config.unlink(missing_ok=True)
 passed('browser recovery: real Vite proxy preserves the public origin; wrong-origin requests fail; reset and preparation succeed for the public origin without secret replay')
 assert snapshot()==domain_before and q('SELECT * FROM runtime_meta')==epoch_before
 logs=(ROOT/'storage/app.log').read_text()+pathlib.Path(temp.name,'php.log').read_text()
 # Database fields, audits and logs never contain any plaintext recovery code/password.
 rows='\n'.join(q('SELECT * FROM '+t+' ORDER BY 1') for t in ['users','sessions','owner_recovery','login_limits','audit_events','audit_details'])
 for secret in secrets:
  assert secret not in logs and secret not in rows
  if re.fullmatch(r'[A-F0-9-]{71}',secret):assert secret.replace('-','') not in logs and secret.replace('-','') not in rows
 passed('browser recovery: lost response is recoverable with the new password; no plaintext secret persistence; stock/sales/VAT/restore epoch unchanged')

browser_recovery_checks()
