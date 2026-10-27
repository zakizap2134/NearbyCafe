# Only called by integration.py on its disposable database.
def preparation_checks():
 import threading,hashlib
 from concurrent.futures import ThreadPoolExecutor
 def q(s):return sql('USE '+DB+';'+s)
 def call(route,body=None,token=None):
  h={'Origin':BASE,'Content-Type':'application/json'}
  if token:h['Authorization']='Bearer '+token
  r=urllib.request.Request(BASE+'/api/index.php?route='+route,headers=h,data=None if body is None else json.dumps(body).encode())
  try:
   with urllib.request.urlopen(r) as x:return x.status,json.loads(x.read())
  except urllib.error.HTTPError as e:return e.code,json.loads(e.read())
 def state():return expect(call('management/recovery-code/status',token=token),200)['data']
 def body(s):return dict(owner_password='Owner-Test-Only-42',expected_version=s['version'],confirm_replace=True)
 def generate(b):return call('management/recovery-code/generate',b,token)
 def snapshot_security():return '\n'.join(q('SELECT * FROM '+t+' ORDER BY 1') for t in ['users','sessions','owner_recovery','audit_events','audit_details','login_limits'])
 q('DELETE FROM login_limits');token=expect(login(),200)['data']['token'];staff=expect(login('testbarista','Staff-Test-Only-42'),200)['data']['token'];domain=snapshot()
 assert call('management/recovery-code/status')[0]==401 and call('management/recovery-code/generate',{},staff)[0]==403
 q('UPDATE users SET is_active=0 WHERE id=1')
 assert call('management/recovery-code/status',token=token)[0]==401
 assert generate(dict(owner_password='Owner-Test-Only-42',expected_version='a'*64,confirm_replace=True))[0]==401
 q('UPDATE users SET is_active=1 WHERE id=1')
 assert call('management/recovery-code/status',token=staff)[0]==403
 passed('Task 5: inactive Owner cannot prepare/read status; Barista cannot read or replace Owner codes')
 q('DELETE FROM owner_recovery WHERE user_id=1');s=state();assert not s['prepared'] and set(s)=={'prepared','version'}
 for _ in range(5):assert generate(body(s)|{'owner_password':'wrong'})[0]==403
 assert generate(body(s))[0]==429
 assert 'RECOVERY_LIMIT' in cli('recovery-code.php','testowner\nOwner-Test-Only-42\n',ok=False)
 q('UPDATE login_limits SET window_started=DATE_SUB(NOW(),INTERVAL 16 MINUTE)')
 original=expect(generate(body(s)),200)['data']['recovery_code'];s=state();assert s['prepared']
 assert q('SELECT code_hash FROM owner_recovery WHERE user_id=1')==hashlib.sha256(original.replace('-','').encode()).hexdigest()
 before=snapshot_security();assert generate(body(s)|{'confirm_replace':False})[0]==422 and snapshot_security()==before
 q("CREATE TRIGGER prep_fault BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected fault'")
 assert generate(body(s))[0]==500 and snapshot_security()==before;q('DROP TRIGGER prep_fault')
 barrier=threading.Barrier(8)
 def parallel(_):barrier.wait();return generate(body(s))
 with ThreadPoolExecutor(8) as pool:results=list(pool.map(parallel,range(8)))
 assert sum(r[0]==200 for r in results)==1 and sum(r[0]==409 for r in results)==7
 replacement=next(r[1]['data']['recovery_code'] for r in results if r[0]==200)
 reset=dict(username='testowner',recovery_code=original,new_password='Owner-Test-Only-42',confirm_password='Owner-Test-Only-42')
 assert call('recovery/owner',reset)[0]==403
 expect(call('recovery/owner',reset|{'recovery_code':replacement}),200);assert call('management/recovery-code/status',token=token)[0]==401
 token=expect(login(),200)['data']['token'];s=state()
 racing=expect(generate(body(s)),200)['data']['recovery_code'];s=state();barrier=threading.Barrier(2)
 def race_reset():barrier.wait();return call('recovery/owner',reset|{'recovery_code':racing})
 def race_prepare():barrier.wait();return generate(body(s))
 with ThreadPoolExecutor(2) as pool:
  r=pool.submit(race_reset);g=pool.submit(race_prepare);r=r.result();g=g.result()
 assert (r[0],g[0]) in [(200,401),(403,200)],(r[0],g[0])
 winning=(r if r[0]==200 else g)[1]['data']['recovery_code']
 assert q('SELECT code_hash FROM owner_recovery WHERE user_id=1')==hashlib.sha256(winning.replace('-','').encode()).hexdigest()
 passed('Task 5: reset versus replacement serializes; revoked session or superseded code cannot win twice')
 assert snapshot()==domain
 for code in [original,replacement,racing,winning]:assert code not in snapshot_security() and code not in (ROOT/'storage/app.log').read_text()
 q('DELETE FROM login_limits')
 passed('Task 2: authorization, shared attempt limits, hash-only storage, confirmation, audit rollback, eight concurrent submissions, no duplicate rotation, Task 1 compatibility and unchanged domain data')
 if os.environ.get('VV_RECOVERY_UI')=='1':
  r=subprocess.run(['node','tests/recovery-live.mjs',BASE],env=ENV);assert r.returncode==0
  r=subprocess.run(['node','tests/recovery-release.mjs',BASE],env=ENV);assert r.returncode==0
preparation_checks()
