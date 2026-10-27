"""Runs last in integration.py; all data belongs to its disposable test database."""
def password_policy_checks():
 import re
 def q(s):return sql('USE '+DB+';'+s)
 def state():return '\n'.join(q('SELECT * FROM '+t+' ORDER BY 1') for t in ['users','sessions','owner_recovery','login_limits','audit_events','audit_details'])
 cli('support-reset-owner.php','testowner\nRESET testowner\nOwner-Test-Only-42\nOwner-Test-Only-42\n')
 q('DELETE FROM login_limits')
 domain=snapshot()
 owner_token=expect(login(),200)['data']['token']
 staff_id=int(q("SELECT id FROM users WHERE username='testbarista'"))
 original_staff=q('SELECT password_hash FROM users WHERE id='+str(staff_id))
 invalid=['a'*11,'é'*11,'😀'*11,'a'*73,'é'*37,'😀'*19,'Valid-Length\0Password']
 for value,confirmation in [(p,p) for p in invalid]+[('Password-Only-42','mismatch'),('Password-Only-42',None)]:
  before=state()
  body=dict(username='policybarista',display_name='Policy Barista',password=value,confirm_password=confirmation,owner_password='Owner-Test-Only-42',is_active=1)
  expect(request('management/staff-save',body,owner_token),422)
  expect(request('management/reset-password',dict(user_id=staff_id,owner_password='Owner-Test-Only-42',new_password=value,confirm_password=confirmation),owner_token),422)
  assert state()==before
 passed('password policy: direct staff creation/reset reject 11 code points, >72 bytes, NUL, missing/mismatched confirmation with no account/session/code/audit writes')
 for value in ['a'*12,'é'*12,'😀'*12,'😀'*18,'  spaced  12  ']:
  expect(request('management/reset-password',dict(user_id=staff_id,owner_password='Owner-Test-Only-42',new_password=value,confirm_password=value),owner_token),200)
  expect(login('testbarista',value),200)
 # CLI tools reject all invalid inputs without consuming recovery codes or sessions.
 code=re.search(r'Recovery code: ([A-F0-9-]{71})',cli('recovery-code.php','testowner\nOwner-Test-Only-42\n')).group(1)
 for value,confirmation in [(p,p) for p in invalid]+[('Password-Only-42','mismatch')]:
  before=state()
  for file,body in [
   ('create-owner.php',f'policyowner\nPolicy Owner\n{value}\n{confirmation}\n'),
   ('create-barista.php',f'testowner\nOwner-Test-Only-42\npolicycli\nPolicy CLI\n{value}\n{confirmation}\n'),
   ('recover-owner.php',f'testowner\n{code}\n{value}\n{confirmation}\n'),
   ('support-reset-owner.php',f'testowner\nRESET testowner\n{value}\n{confirmation}\n')]:
   cli(file,body,ok=False);assert state()==before,file
 passed('password policy: all four CLI new-password paths reject invalid passwords/mismatches and preserve records')
 # Real legacy hashes below the new minimum remain usable, including cost rehash.
 def makehash(value,cost):
  result=subprocess.run([PHP,'-r',"echo password_hash(stream_get_contents(STDIN),PASSWORD_BCRYPT,['cost'=>(int)$argv[1]]);",str(cost)],input=value,capture_output=True,text=True,env=ENV)
  assert result.returncode==0;return result.stdout
 for cost in [10,4]:
  legacy='é'*6
  hashed=makehash(legacy,cost)
  q("UPDATE users SET password_hash='"+hashed+"' WHERE id="+str(staff_id))
  legacy_token=expect(login('testbarista',legacy),200)['data']['token']
  expect(request('session',token=legacy_token),200)
  if cost==10:qhash=q('SELECT password_hash FROM users WHERE id='+str(staff_id));assert qhash==hashed
  else:assert q('SELECT password_hash FROM users WHERE id='+str(staff_id))!=hashed
 q("UPDATE users SET password_hash='"+original_staff+"' WHERE id="+str(staff_id))
 expect(request('session',token=owner_token),200)
 passed('password policy: legacy six-character Unicode password signs in with unchanged current-cost hash and successfully rehashes old cost; existing Owner session remains valid')
 # Both recovery CLI success paths accept a 12-code-point password.
 value='😀'*12
 output=cli('recover-owner.php',f'testowner\n{code}\n{value}\n{value}\n')
 expect(login('testowner',value),200)
 replacement=re.search(r'Recovery code: ([A-F0-9-]{71})',output).group(1)
 cli('recover-owner.php',f'testowner\n{replacement}\nOwner-Test-Only-42\nOwner-Test-Only-42\n')
 cli('support-reset-owner.php',f'testowner\nRESET testowner\n{value}\n{value}\n')
 expect(login('testowner',value),200)
 cli('support-reset-owner.php','testowner\nRESET testowner\nOwner-Test-Only-42\nOwner-Test-Only-42\n')
 cli('create-barista.php',f'testowner\nOwner-Test-Only-42\npolicycli\nPolicy CLI\n{value}\n{value}\n')
 expect(login('policycli',value),200)
 assert snapshot()==domain
 passed('password policy: CLI recovery/support reset/staff creation accept 12 emoji and authenticate; sales/inventory/VAT records unchanged')
password_policy_checks()
