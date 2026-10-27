"""Run inside the isolated integration harness after Stage 2 cases."""
from concurrent.futures import ThreadPoolExecutor
import threading,re

# A populated Stage 2 database upgrades without losing prior sales or accounts.
prior=snapshot();dbq('DROP TABLE checkout_rejections;DROP TABLE owner_recovery;UPDATE app_state SET schema_version=2')
expect(request('health'),503);cli('migrate-stage3.php');cli('migrate-stage3.php');assert snapshot()==prior
assert dbq('SELECT schema_version FROM app_state')=='4'
cli('preflight.php');passed('populated Stage 2 upgrade preserves sales, accounts and inventory; repeat migration safe')

parallel_stock=ing('Concurrent milk');parallel_drink=variant('Concurrent drink','10.00')
save('recipe',variant_id=parallel_drink,rows=[dict(ingredient_id=parallel_stock,qty='100')]);receive(parallel_stock,'1000')
common=dict(transaction_key=key(),cash_received='20.00',items=[dict(variant_id=parallel_drink,qty=1,expected_price='10.00')])
barrier=threading.Barrier(8)
def send_same(_):barrier.wait();return request('checkout',common,st)
with ThreadPoolExecutor(max_workers=8) as pool: replies=list(pool.map(send_same,range(8)))
receipts=[expect(r,200)['data'] for r in replies]
assert len(set(r['id'] for r in receipts))==1 and sum(not r['duplicate'] for r in receipts)==1
assert dbq(f'SELECT current_stock FROM ingredients WHERE id={parallel_stock}')=='900.000'
invariants();passed('8 simultaneous identical submissions across PHP workers create one sale and one deduction')

limited=ing('Last stock');last=variant('Last drink','10.00');save('recipe',variant_id=last,rows=[dict(ingredient_id=limited,qty='100')]);receive(limited,'200')
barrier=threading.Barrier(8)
def compete(_):
 body=dict(transaction_key=key(),cash_received='20.00',items=[dict(variant_id=last,qty=1,expected_price='10.00')]);barrier.wait();return body,request('checkout',body,st)
with ThreadPoolExecutor(max_workers=8) as pool: competition=list(pool.map(compete,range(8)))
assert sum(r[0]==200 for _,r in competition)==2 and sum(r[0]==409 for _,r in competition)==6
assert dbq(f'SELECT current_stock FROM ingredients WHERE id={limited}')=='0.000'
rejected=next(body for body,r in competition if r[0]==409)
receive(limited,'1000');before=snapshot();expect(request('checkout',rejected,st),409);assert snapshot()==before
expect(request('checkout',{**rejected,'cash_received':'30.00'},st),409)
invariants();passed('8 competing sales cannot oversell; rejected UUID remains rejected after replenishment')

p=subprocess.run([PHP,'tests/time-boundaries.php'],env=ENV,capture_output=True,text=True);assert p.returncode==0,(p.stdout,p.stderr);print(p.stdout.strip(),flush=True)

# Replenishment also has concurrent idempotency and rollback at the required audit write.
_,receive_body=receive(parallel_stock,'100');before=snapshot();barrier=threading.Barrier(6)
def repeat_receive(_):barrier.wait();return request('management/save',receive_body,ot)
with ThreadPoolExecutor(max_workers=6) as pool: received=list(pool.map(repeat_receive,range(6)))
assert all(expect(r,200)['data']['duplicate'] for r in received) and snapshot()==before
dbq("CREATE TRIGGER stage3_fault BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected stage3 failure'")
expect(request('management/save',{**receive_body,'delivery_key':key()},ot),500);assert snapshot()==before
dbq('DROP TRIGGER stage3_fault');passed('parallel delivery replay and late replenishment rollback preserve all stock records')

staff_id=staff['user']['id'];owner_id=owner['user']['id']
reset=dict(user_id=staff_id,owner_password='Owner-Test-Only-42',new_password='Staff-Recovered-42',confirm_password='Staff-Recovered-42')
expect(request('management/staff',token=st),403);expect(request('management/reset-password',reset,st),403)
expect(request('management/reset-password',{**reset,'user_id':owner_id},ot),422)
expect(request('management/reset-password',{**reset,'new_password':None},ot),422)
for _ in range(5):expect(request('management/reset-password',{**reset,'owner_password':'wrong'},ot),403)
expect(request('management/reset-password',reset,ot),429)
dbq('DELETE FROM login_limits')
expect(request('session',token=st),200)
dbq("CREATE TRIGGER stage3_fault BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected reset failure'")
expect(request('management/reset-password',reset,ot),500)
expect(request('session',token=st),200)
dbq('DROP TRIGGER stage3_fault')
expect(request('management/reset-password',reset,ot),200)
expect(request('session',token=st),401);expect(login('testbarista','Staff-Test-Only-42'),401)
st=expect(login('testbarista','Staff-Recovered-42'),200)['data']['token']
expect(request('management/reset-password',{**reset,'new_password':'Staff-Test-Only-42','confirm_password':'Staff-Test-Only-42'},ot),200)
st=expect(login('testbarista','Staff-Test-Only-42'),200)['data']['token']
assert int(dbq("SELECT COUNT(*) FROM audit_events WHERE action='BARISTA_PASSWORD_RESET:"+str(staff_id)+"'"))==2
passed('owner reauthentication, reset rate limit, owner-target denial, transactional reset rollback and Barista session revocation')

def code_from(output):
 found=re.search(r'Recovery code: ([A-F0-9-]{71})',output);assert found,'Recovery code not emitted';return found.group(1)
assert 'CREDENTIAL_INVALID' in cli('recovery-code.php','testowner\nwrong\n',ok=False)
offline=code_from(cli('recovery-code.php','testowner\nOwner-Test-Only-42\n'))
assert offline.replace('-','') not in dbq('SELECT code_hash FROM owner_recovery')
bad='testowner\nwrong-code\nOwner-Recovered-42\nOwner-Recovered-42\n'
for _ in range(5):assert 'CREDENTIAL_INVALID' in cli('recover-owner.php',bad,ok=False)
assert 'RECOVERY_LIMIT' in cli('recover-owner.php',f'testowner\n{offline}\nOwner-Recovered-42\nOwner-Recovered-42\n',ok=False)
dbq('DELETE FROM login_limits')
dbq("CREATE TRIGGER stage3_fault BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected recovery failure'")
cli('recover-owner.php',f'testowner\n{offline}\nOwner-Recovered-42\nOwner-Recovered-42\n',ok=False)
expect(request('session',token=ot),200)
dbq('DROP TRIGGER stage3_fault')
replacement=code_from(cli('recover-owner.php',f'testowner\n{offline}\nOwner-Recovered-42\nOwner-Recovered-42\n'))
expect(request('session',token=ot),401);expect(login(),401)
assert 'CREDENTIAL_INVALID' in cli('recover-owner.php',f'testowner\n{offline}\nOwner-Test-Only-42\nOwner-Test-Only-42\n',ok=False)
ot=expect(login('testowner','Owner-Recovered-42'),200)['data']['token']
cli('recover-owner.php',f'testowner\n{replacement}\nOwner-Test-Only-42\nOwner-Test-Only-42\n')
ot=expect(login(),200)['data']['token']
for path in ['/tools/recover-owner.php','/tools/recovery-code.php']:
 try:urllib.request.urlopen(BASE+path);raise AssertionError('CLI recovery exposed')
 except urllib.error.HTTPError as e:assert e.code==404
logs=(ROOT/'storage/app.log').read_text();assert offline not in logs and replacement not in logs and 'Owner-Recovered-42' not in logs
invariants();passed('offline code hashing, throttling, recovery rollback, one-time rotation, session revocation, HTTP denial and secret-free diagnostics')
