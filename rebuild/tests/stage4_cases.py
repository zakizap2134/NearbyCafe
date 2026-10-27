"""Stage 4 gates, after prior browser tests; uses the same disposable database only."""
import csv,io,copy
ot=expect(login(),200)['data']['token'];st=expect(login('testbarista','Staff-Test-Only-42'),200)['data']['token']
epoch=expect(request('health'),200)['data']['epoch']
prior=snapshot();dbq('UPDATE app_state SET schema_version=3');cli('migrate-stage4.php');cli('migrate-stage4.php');cli('preflight.php');assert snapshot()==prior
assert expect(request('health'),200)['data']['epoch']==epoch
passed('Stage 3 → 4 migration and rerun preserve records and restore epoch')
for route in ['management/reports','management/csv','management/audit','management/movements']:
 expect(request(route,token=st),403)
for route in ['management/inventory','management/staff-save','management/delete','management/backup','management/restore-preview','management/restore']:
 expect(request(route,{},st),403)

i=ing('Stage4 milk');v=variant('Stage4 drink','25.00');save('recipe',variant_id=v,rows=[dict(ingredient_id=i,qty='100')]);bid,receive_body=receive(i,'1000')
cid=int(dbq(f'SELECT id FROM stock_containers WHERE batch_id={bid}'))
def operation(kind='waste',quantity='100',expected='1000',container=cid,**rest):
 return dict(operation_key=key(),expected_epoch=epoch,kind=kind,id=container,quantity=quantity,expected_remaining=expected,reason='Verified physical count',**rest)
waste=operation();expect(request('management/inventory',waste,ot),200);prior=snapshot()
assert expect(request('management/inventory',waste,ot),200)['data']['duplicate'];assert snapshot()==prior
expect(request('management/inventory',{**waste,'quantity':'101'},ot),409)
expect(request('management/inventory',operation(expected='1000'),ot),409)
expect(request('management/inventory',operation(quantity='901',expected='900'),ot),422)
expect(request('management/inventory',operation('adjust','950','900'),ot),200)
expect(request('management/inventory',operation('adjust','1001','950'),ot),422)
assert dbq(f'SELECT current_stock FROM ingredients WHERE id={i}')=='950.000'
correction=dict(operation_key=key(),expected_epoch=epoch,kind='batch',id=bid,reason='Hold for inspection',expiration_date='2099-12-31',supplier='New supplier',lot_reference='FIX-1',quarantined=1)
expect(request('management/inventory',correction,ot),200)
expect(sale([(v,1,'25.00')],token=ot)[0],409)
expect(request('management/inventory',operation('adjust','960','950'),ot),409)
expect(request('management/inventory',{**correction,'operation_key':key(),'quarantined':0},ot),200)
expect(sale([(v,1,'25.00')],token=ot)[0],200)
opened=dbq(f'SELECT CONCAT(opened_at,":",use_by) FROM stock_containers WHERE id={cid}')
expect(request('management/inventory',operation('adjust','900','850'),ot),200)
assert dbq(f'SELECT CONCAT(opened_at,":",use_by) FROM stock_containers WHERE id={cid}')==opened
prior=snapshot();dbq("CREATE TRIGGER stage4_fault BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Stage4 late failure'")
expect(request('management/inventory',operation(expected='900'),ot),500);assert snapshot()==prior;dbq('DROP TRIGGER stage4_fault')
expired_batch,_=receive(i,'200',expiry='2000-01-01');expired_c=int(dbq(f'SELECT id FROM stock_containers WHERE batch_id={expired_batch}'))
expect(request('management/inventory',operation(quantity='200',expected='200',container=expired_c),ot),200)
expect(request('management/inventory',operation('adjust','50','0',container=expired_c),ot),409)
invariants();passed('waste, ±count corrections, batch quarantine, expired waste, opening-clock preservation, idempotency and late-write rollback')

unused=save('product',name='Unused stage4',category='Test')
expect(request('management/delete',dict(kind='product',id=unused),ot),200)
expect(request('management/delete',dict(kind='variant',id=v),ot),409)
staff_body=dict(username='stage4staff',display_name='Stage 4 Staff',password='Stage4-Staff-Only-42',confirm_password='Stage4-Staff-Only-42',is_active=1,owner_password='Owner-Test-Only-42')
expect(request('management/staff-save',{**staff_body,'owner_password':'wrong'},ot),403)
sid=expect(request('management/staff-save',staff_body,ot),200)['data']['id']
staff_session=expect(login('stage4staff','Stage4-Staff-Only-42'),200)['data']['token']
expect(request('management/staff-save',{**staff_body,'id':sid,'is_active':0},ot),200)
expect(request('session',token=staff_session),401);expect(login('stage4staff','Stage4-Staff-Only-42'),401)
expect(request('management/staff-save',{**staff_body,'id':1},ot),422)
expect(request('management/staff-save',{**staff_body,'id':sid,'is_active':1},ot),200)
passed('unused-record deletion, historical record protection, owner-only staff maintenance and deactivation revocation')

day=dbq('SELECT CURDATE()');period_query='&from='+day+'&to='+day
report=expect(request('management/reports'+period_query,token=ot),200)['data']
gross,net,vat=dbq('SELECT SUM(total_gross),SUM(total_net),SUM(total_vat) FROM sales WHERE DATE(sale_at)=CURDATE()').split('\t')
assert (report['totals']['gross'],report['totals']['net'],report['totals']['vat'],report['vat_remittance'])==(gross,net,vat,vat)
expect(request('management/reports&from=2026-02-30',token=ot),422)
expect(request('management/reports&from=2000-01-01&to=2099-01-01',token=ot),422)
assert expect(request('management/reports&from=1990-01-01&to=1990-01-01',token=ot),200)['data']['totals']['count']==0
# Formula-bearing user text must not execute when opened in spreadsheet software.
dbq("UPDATE users SET display_name='=1+1' WHERE id="+str(owner_id))
export=expect(request('management/csv'+period_query,token=ot),200)['data']
rows=list(csv.DictReader(io.StringIO(export['content'].lstrip('\ufeff'))));assert rows[-1]['sale_id']=='TOTAL'
assert (rows[-1]['gross'],rows[-1]['net'],rows[-1]['vat'])==(gross,net,vat)
assert all(Decimal(r['gross'])==Decimal(r['net'])+Decimal(r['vat']) for r in rows)
assert any(r['cashier']=="'=1+1" for r in rows)
dbq("UPDATE users SET display_name='Test Owner' WHERE id="+str(owner_id))
assert expect(request('management/audit'+period_query+'&action=STOCK_ADJUSTED',token=ot),200)['data']['total']>=2
assert expect(request('management/movements'+period_query+'&type=WASTE',token=ot),200)['data']['total']>=2
passed('stored gross/net/VAT reports, daily summaries, date validation, real audit/movement history and formula-safe CSV totals')

# Earlier expiration boundary fixture deliberately moved only use_by backward; make its opening date coherent for restore validation.
dbq("UPDATE stock_containers SET opened_at='1999-12-30 00:00:00' WHERE use_by<opened_at")
password='Backup-Test-Passphrase-42'
creds=dict(owner_password='Owner-Test-Only-42',backup_password=password)
file=expect(request('management/backup',creds,ot),200)['data']['file']
serialized=json.dumps(file);assert 'Test Owner' not in serialized and 'password_hash' not in serialized and 'Stage4 milk' not in serialized
def full_snapshot():
 tables=['users','sessions','ingredients','products','product_variants','recipes','stock_batches','stock_containers','sales','sale_items','stock_movements','audit_events','audit_details','checkout_rejections','owner_recovery','stock_operations','runtime_meta']
 return '\n'.join(dbq('SELECT * FROM '+t+' ORDER BY 1'+(',2' if t=='recipes' else '')) for t in tables)
request_body={**creds,'file':file};prior=full_snapshot()
expect(request('management/restore-preview',{**request_body,'backup_password':'Wrong-Backup-Passphrase'},ot),422);assert full_snapshot()==prior
damaged=copy.deepcopy(file);damaged['data']=('A' if damaged['data'][0]!='A' else 'B')+damaged['data'][1:]
expect(request('management/restore-preview',{**request_body,'file':damaged},ot),422);assert full_snapshot()==prior
for mode in ['stock','foreign','owner','columns','vat','date']:
 p=subprocess.run([PHP,'tests/backup-fixture.php'],input=json.dumps(dict(file=file,password=password,mode=mode)),text=True,capture_output=True,env=ENV);assert p.returncode==0,p.stderr
 expect(request('management/restore-preview',{**request_body,'file':json.loads(p.stdout)},ot),422);assert full_snapshot()==prior
preview=expect(request('management/restore-preview',request_body,ot),200)['data'];assert preview['backup_counts']['sales']>0;assert full_snapshot()==prior
passed('encrypted backup, wrong-passphrase/tamper rejection, preview rejects invalid stock/VAT/FKs/owner/columns/dates without writes')

restore_body={**request_body,**preview,'confirmation':'RESTORE'}
save('product',name='After backup',category='Test')
expect(request('management/restore',restore_body,ot),409)
preview=expect(request('management/restore-preview',request_body,ot),200)['data'];restore_body={**request_body,**preview,'confirmation':'RESTORE'}
prior=full_snapshot();dbq("\nDELIMITER //\nCREATE TRIGGER stage4_restore_fault BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='BACKUP_RESTORED' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Late restore failure'; END IF; END//\nDELIMITER ;\n")
expect(request('management/restore',restore_body,ot),422);assert full_snapshot()==prior
expect(request('session',token=ot),200);dbq('DROP TRIGGER stage4_restore_fault')
passed('stale-preview guard and failure at final restore audit roll back records, sessions and restore epoch together')

# Change owner credentials after export; restore must preserve current owner password and code, not the old backup hashes.
live_code=code_from(cli('recovery-code.php','testowner\nOwner-Test-Only-42\n'))
cli('recover-owner.php',f'testowner\n{live_code}\nOwner-Restore-Test-42\nOwner-Restore-Test-42\n')
ot=expect(login('testowner','Owner-Restore-Test-42'),200)['data']['token'];current_code=dbq('SELECT code_hash FROM owner_recovery WHERE user_id=1')
request_body['owner_password']='Owner-Restore-Test-42'
preview=expect(request('management/restore-preview',request_body,ot),200)['data']
expect(request('management/restore',{**request_body,**preview,'confirmation':'RESTORE'},ot),200)
expect(request('session',token=ot),401);assert dbq('SELECT COUNT(*) FROM sessions')=='0'
assert dbq("SELECT COUNT(*) FROM products WHERE name='After backup'")=='0'
assert dbq('SELECT code_hash FROM owner_recovery WHERE user_id=1')==current_code
assert dbq("SELECT COUNT(*) FROM audit_events WHERE action='BACKUP_RESTORED'")=='1'
expect(login(),401);ot=expect(login('testowner','Owner-Restore-Test-42'),200)['data']['token']
new_epoch=expect(request('health'),200)['data']['epoch'];assert new_epoch!=epoch
old_order=dict(transaction_key=key(),cash_received='50.00',items=[dict(variant_id=v,qty=1,expected_price='25.00')],expected_epoch=epoch)
prior=snapshot()
for stale in [old_order,{k:val for k,val in old_order.items() if k!='expected_epoch'}]:
 assert expect(request('checkout',stale,ot),409)['error']['code']=='RESTORE_REVIEW'
assert expect(request('management/save',{**receive_body,'delivery_key':key(),'expected_epoch':epoch},ot),409)['error']['code']=='RESTORE_REVIEW'
assert expect(request('management/inventory',waste,ot),409)['error']['code']=='RESTORE_REVIEW'
assert snapshot()==prior
expect(request('checkout',{**old_order,'expected_epoch':new_epoch},ot),200)
invariants()
# Return owner credentials to the browser fixture password through the real recovery path.
code=code_from(cli('recovery-code.php','testowner\nOwner-Restore-Test-42\n'))
cli('recover-owner.php',f'testowner\n{code}\nOwner-Test-Only-42\nOwner-Test-Only-42\n')
passed('successful atomic restore retains current owner recovery, restores history, revokes sessions and blocks pre-restore checkout/stock requests; new-epoch checkout succeeds')
ot=expect(login(),200)['data']['token'];prior_epoch=expect(request('health'),200)['data']['epoch']
assert 'CREDENTIAL_INVALID' in cli('finalize-host-restore.php','testowner\nwrong\n',ok=False)
assert expect(request('health'),200)['data']['epoch']==prior_epoch
cli('finalize-host-restore.php','testowner\nOwner-Test-Only-42\n')
expect(request('session',token=ot),401);assert expect(request('health'),200)['data']['epoch']!=prior_epoch
passed('server-only raw-restore finalization requires owner credentials, validates totals, rotates epoch and revokes sessions')
