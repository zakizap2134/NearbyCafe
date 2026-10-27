"""Executed inside integration.py's disposable database harness."""
import uuid
from decimal import Decimal

def key(): return str(uuid.uuid4())
def dbq(q): return sql('USE '+DB+';'+q)
owner=expect(login(),200)['data'];staff=expect(login('testbarista','Staff-Test-Only-42'),200)['data']
ot=owner['token'];st=staff['token']
def save(action,**values): return expect(request('management/save',dict(action=action,**values),ot),200)['data']['id']
def ing(name,unit='mL',hours='48'):
 return save('ingredient',name=name,unit=unit,minimum_stock='10',opened_shelf_life_hours=hours,is_active=1)
def variant(name,price='150.00',size='M',temperature='HOT'):
 p=save('product',name=name,category='Test coffee',is_active=1)
 return save('variant',product_id=p,size=size,temperature=temperature,sku=name.replace(' ','-'),price=price,is_active=1)
def receive(i,qty='1000',count=1,expiry='2099-12-31'):
 body=dict(action='receive',delivery_key=key(),ingredient_id=i,container_count=count,quantity_per_container=qty,expiration_date=expiry,reference='Test delivery',supplier='Test supplier',lot_reference='LOT-1')
 result=expect(request('management/save',body,ot),200)['data'];return result['id'],body
def sale(items,token=st,cash='500.00',sale_key=None):
 body={'transaction_key':sale_key or key(),'cash_received':cash,'items':[dict(variant_id=v,qty=q,expected_price=p) for v,q,p in items]}
 return request('checkout',body,token),body
def snapshot():
 return '\n'.join(dbq('SELECT * FROM '+t+' ORDER BY id') for t in ['ingredients','stock_batches','stock_containers','sales','sale_items','stock_movements'])
def invariants():
 assert dbq('SELECT COUNT(*) FROM ingredients i WHERE i.current_stock<>(SELECT COALESCE(SUM(remaining_qty),0) FROM stock_batches b WHERE b.ingredient_id=i.id)')=='0'
 assert dbq('SELECT COUNT(*) FROM stock_batches b WHERE remaining_qty<>qty_opened+qty_sealed OR remaining_qty<>(SELECT COALESCE(SUM(remaining_qty),0) FROM stock_containers c WHERE c.batch_id=b.id)')=='0'
 assert dbq('SELECT COUNT(*) FROM ingredients i WHERE current_stock<>(SELECT COALESCE(SUM(quantity_delta),0) FROM stock_movements m WHERE m.ingredient_id=i.id)')=='0'
 assert dbq('SELECT COUNT(*) FROM sales s WHERE total_gross<>total_net+total_vat OR total_gross<>(SELECT SUM(line_gross) FROM sale_items l WHERE l.sale_id=s.id) OR total_net<>(SELECT SUM(line_net) FROM sale_items l WHERE l.sale_id=s.id)')=='0'

# Upgrade an actual foundation-only database while keeping existing accounts.
for t in ['stock_movements','sale_items','sales','stock_containers','stock_batches','recipes','ingredients','product_variants','products']: dbq('DROP TABLE '+t)
dbq('UPDATE app_state SET schema_version=1')
expect(request('health'),503)
cli('migrate-stage2.php');cli('migrate-stage2.php');cli('preflight.php')
assert dbq('SELECT COUNT(*) FROM users')=='2'
expect(request('session',token=ot),200)
passed('Stage 1 → current schema migration keeps users/sessions and is safe to rerun')

expect(request('management',token=st),403)
expect(request('management/save',dict(action='product',name='Forbidden',category='Coffee'),st),403)
milk=ing('Test fresh milk');beans=ing('Test beans','g','168');cups=ing('Test cups','units','')
latte=variant('Test Latte');americano=variant('Test Americano','120.00','S');empty=variant('No recipe')
save('recipe',variant_id=latte,rows=[dict(ingredient_id=milk,qty='220'),dict(ingredient_id=beans,qty='18'),dict(ingredient_id=cups,qty='1')])
save('recipe',variant_id=americano,rows=[dict(ingredient_id=beans,qty='18'),dict(ingredient_id=cups,qty='1')])
batch,delivery=receive(milk,count=2);receive(beans);receive(cups,'10',expiry='')
before=snapshot();dup=expect(request('management/save',delivery,ot),200)['data'];assert dup['duplicate'] and snapshot()==before
expect(request('management/save',{**delivery,'quantity_per_container':'999'},ot),409)
expect(request('management/save',dict(action='recipe',variant_id=latte,rows=[dict(ingredient_id=cups,qty='0.5')]),ot),422)
expect(request('management/save',dict(action='ingredient',id=cups,name='Test cups',unit='g'),ot),409)
passed('owner catalogue/recipes/stock receipt, units validation, role denial and idempotent deliveries')

response,body=sale([(latte,2,'150.00'),(americano,1,'120.00')]);receipt=expect(response,200)['data']
assert (receipt['total_gross'],receipt['total_net'],receipt['total_vat'],receipt['change_due'])==('420.00','375.00','45.00','80.00')
assert [(l['line_net'],l['line_vat']) for l in receipt['items']]==[('267.86','32.14'),('107.14','12.86')]
assert dbq(f'SELECT current_stock FROM ingredients WHERE id={milk}')=='1560.000'
assert dbq(f'SELECT CONCAT(qty_sealed,":",qty_opened) FROM stock_batches WHERE id={batch}')=='1000.000:560.000'
assert dbq(f'SELECT COUNT(*) FROM stock_containers WHERE batch_id={batch} AND opened_at IS NULL')=='1'
assert dbq(f'SELECT TIMESTAMPDIFF(HOUR,opened_at,use_by) FROM stock_containers WHERE batch_id={batch} AND opened_at IS NOT NULL')=='48'
before=snapshot();duplicate=expect(request('checkout',body,st),200)['data'];assert duplicate['id']==receipt['id'] and duplicate['duplicate'] and snapshot()==before
expect(request('checkout',{**body,'cash_received':'600.00'},st),409)
expect(request('checkout',body,ot),409)
expect(request('sales/receipt&id='+str(receipt['id']),token=st),200)
expect(request('sales/receipt&id='+str(receipt['id']),token=ot),200)
today=expect(request('management',token=ot),200)['data']['sales']['totals'];assert today==dict(count=1,gross='420.00',net='375.00',vat='45.00')
passed('cash milestone: ₱420 gross / ₱375 net / ₱45 VAT / ₱80 change; exact stock, opened container, receipt and real daily totals')

for items,cash,status in [([(latte,1,'149.00')],'500',409), ([(latte,1,'150.00')],'149',422), ([(empty,1,'150.00')],'500',409), ([(latte,5,'150.00'),(americano,3,'120.00')],'2000',409)]:
 before=snapshot();expect(sale(items,cash=cash)[0],status);assert snapshot()==before
# Fail after all inventory writes, proving full rollback including opening metadata.
before=snapshot()
dbq("CREATE TRIGGER stage2_audit_fault BEFORE INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected late sale failure'")
failed,retry_body=sale([(latte,1,'150.00')]);expect(failed,500);assert snapshot()==before
dbq('DROP TRIGGER stage2_audit_fault')
expect(request('checkout',retry_body,st),200);invariants()
passed('changed prices, underpayment, missing recipes and aggregated shared stock reject unchanged; late fault rolls back every sale/stock write and retries safely')

# Dedicated perishable variant tests FEFO, opened priority, expired batches and opened use-by.
perishable=ing('Expiry test milk');drink=variant('Expiry test drink','10.00');save('recipe',variant_id=drink,rows=[dict(ingredient_id=perishable,qty='100')])
old,_=receive(perishable,'500',expiry='2000-01-01')
before=snapshot();expect(sale([(drink,1,'10.00')])[0],409);assert snapshot()==before
later,_=receive(perishable,'500',expiry='2099-12-31');earlier,_=receive(perishable,'500',expiry='2099-01-01')
expect(sale([(drink,1,'10.00')])[0],200)
assert dbq(f'SELECT remaining_qty FROM stock_batches WHERE id={earlier}')=='400.000'
earliest,_=receive(perishable,'500',expiry='2098-01-01')
expect(sale([(drink,1,'10.00')])[0],200)
assert dbq(f'SELECT remaining_qty FROM stock_batches WHERE id={earlier}')=='300.000' # opened beats sealed
dbq(f"UPDATE stock_containers SET use_by='2000-01-01' WHERE batch_id={earlier}")
expect(sale([(drink,1,'10.00')])[0],200)
assert dbq(f'SELECT remaining_qty FROM stock_batches WHERE id={earliest}')=='400.000'
assert dbq(f'SELECT remaining_qty FROM stock_batches WHERE id={old}')=='500.000'
expire_today=ing('Expires today');v=variant('Today drink','10.00');save('recipe',variant_id=v,rows=[dict(ingredient_id=expire_today,qty='100')])
today=dbq("SET time_zone='+08:00';SELECT CURDATE()")
receive(expire_today,'100',expiry=today);expect(sale([(v,1,'10.00')])[0],200)
invariants();passed('FEFO, opened-before-sealed, expired/use-by exclusion, expiry-today eligibility and all stock/VAT invariants')

# Invoice rounding residual and immutable historical prices.
a=variant('Rounding one','0.05');b=variant('Rounding two','0.05')
for v in [a,b]:save('recipe',variant_id=v,rows=[dict(ingredient_id=beans,qty='0.001')])
r=expect(sale([(a,1,'0.05'),(b,1,'0.05')],token=ot)[0],200)['data']
assert (r['total_net'],r['total_vat'])==('0.09','0.01') and [x['line_net'] for x in r['items']]==['0.04','0.05']
expect(request('sales/receipt&id='+str(r['id']),token=st),404)
dbq(f'UPDATE product_variants SET price=160 WHERE id={latte}')
assert expect(request('checkout',body,st),200)['data']['total_gross']=='420.00'
assert expect(request('sales/receipt&id='+str(receipt['id']),token=ot),200)['data']['items'][0]['unit_price']=='150.00'
invariants();passed('half-up VAT with last-line residual, staff receipt isolation and historical sale snapshots after price change')
