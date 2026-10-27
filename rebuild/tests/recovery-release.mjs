// Real API/browser Task 5 checks; disposable data only, no secret screenshots.
import {chromium, expect} from '@playwright/test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const base=process.argv[2], secrets=[], urls=[], messages=[];
const browser=await chromium.launch({headless:true,executablePath:process.env.VV_CHROMIUM_PATH,args:['--no-sandbox']});
let password='Lost-Reply-Password-42';secrets.push(password);
try {
 const context=await browser.newContext(), page=await context.newPage();
 page.on('request',r=>urls.push(r.url()));page.on('console',m=>messages.push(m.text()));
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 async function api(route,body,token,status=200){const r=await context.request.fetch(base+'/api/index.php?route='+encodeURIComponent(route),{method:body?'POST':'GET',data:body,headers:{Origin:base,...(token?{Authorization:'Bearer '+token}:{})}});assert.equal(r.status(),status,route+' HTTP status');return(await r.json()).data;}
 const owner=await api('login',{username:'testowner',password});
 const staff=await api('login',{username:'testbarista',password:'Staff-Test-Only-42'});
 const save=async(action,values)=>(await api('management/save',{action,...values},owner.token)).id;
 const ingredient=await save('ingredient',{name:'Recovery release milk',unit:'mL',minimum_stock:'100',opened_shelf_life_hours:48});
 const product=await save('product',{name:'Recovery release Latte',category:'Coffee'});
 const variant=await save('variant',{product_id:product,size:'M',temperature:'HOT',sku:'RECOVERY-RELEASE-M',price:'112.00'});
 await save('recipe',{variant_id:variant,rows:[{ingredient_id:ingredient,qty:'100'}]});
 await save('receive',{expected_epoch:(await api('health')).epoch,ingredient_id:ingredient,delivery_key:crypto.randomUUID(),container_count:1,quantity_per_container:'1000',expiration_date:'2099-12-31',reference:'Disposable recovery test'});
 async function login(){await page.getByLabel('Username',{exact:true}).fill('testowner');await page.getByLabel('Password',{exact:true}).fill(password);await page.getByRole('button',{name:'Sign in',exact:true}).click();await expect(page.getByRole('heading',{name:'Management',exact:true})).toBeVisible();}
 const panel=page.locator('.owner-recovery-code'),dialog=page.getByRole('dialog');
 async function acknowledge(){await expect(dialog.getByRole('button',{name:'Done — hide code'})).toBeDisabled();await page.keyboard.press('Escape');await expect(dialog).toBeVisible();const code=await dialog.getByLabel('Owner recovery code',{exact:true}).inputValue();secrets.push(code,code.replaceAll('-',''));await dialog.getByRole('checkbox').check();await dialog.getByRole('button',{name:'Done — hide code'}).click();return code;}
 async function prepare(){await panel.getByLabel('Current Owner password for recovery code',{exact:true}).fill(password);await panel.getByRole('checkbox').check();}
 await page.goto(base);await login();await page.getByRole('button',{name:'Account security',exact:true}).click();await expect(panel).toContainText('A recovery code is prepared');
 let lostCode, requests=0;
 await page.route('**/api/index.php?route=management%2Frecovery-code%2Fgenerate',async route=>{requests++;const response=await route.fetch();assert.equal(response.status(),200);lostCode=(await response.json()).data.recovery_code;secrets.push(lostCode,lostCode.replaceAll('-',''));await route.abort();},{times:1});
 await prepare();await panel.getByRole('button',{name:'Replace recovery code',exact:true}).click();await expect(panel.getByRole('alert')).toContainText('code may have changed');await expect(dialog).toHaveCount(0);assert.equal(requests,1);
 await panel.getByRole('button',{name:'Check code status',exact:true}).click();await expect(panel.getByRole('checkbox')).toBeVisible();
 let rotations=0;page.on('request',r=>{if(r.url().includes('route=management%2Frecovery-code%2Fgenerate'))rotations++;});
 await prepare();await panel.locator('form').evaluate(f=>{f.requestSubmit();f.requestSubmit();});await expect(dialog).toBeVisible();assert.equal(rotations,1);const code=await acknowledge();
 await api('recovery/owner',{username:'testowner',recovery_code:lostCode,new_password:password,confirm_password:password},null,403);
 console.log('PASS Task 5 browser: committed lost replacement shows uncertainty; reauthentication replaces code; duplicate clicks send one request; superseded code rejected');
 let online=false;await context.route('**/api/index.php?route=checkout',r=>online?r.continue():r.abort());
 await page.getByRole('link',{name:'Register',exact:true}).click();await page.getByRole('button',{name:'Add Recovery release Latte M HOT',exact:true}).click();await page.getByLabel('Cash received',{exact:true}).fill('200');await expect(page.getByText('Draft saved on this device.',{exact:true})).toBeVisible();await page.getByRole('button',{name:'Charge',exact:true}).click();await page.getByRole('button',{name:'Confirm sale',exact:true}).click();await expect(page.getByRole('heading',{name:'Pending orders (1)',exact:true})).toBeVisible();
 async function stored(){return page.evaluate(async()=>{const result={local:{...localStorage},session:{...sessionStorage},databases:{}};for(const info of await indexedDB.databases()){const db=await new Promise((resolve,reject)=>{const r=indexedDB.open(info.name);r.onsuccess=()=>resolve(r.result);r.onerror=()=>reject(r.error);});const stores={};for(const name of db.objectStoreNames){stores[name]=await new Promise((resolve,reject)=>{const r=db.transaction(name).objectStore(name).getAll();r.onsuccess=()=>resolve(r.result);r.onerror=()=>reject(r.error);});}db.close();result.databases[info.name]=stores;}return result;});}
 const queue=async()=>(await stored()).databases['venuevue-rebuild-checkout'].queue;
 const before=await queue();assert.equal(before.length,1);assert.equal(before[0].state,'submitted');const key=before[0].key;
 await page.getByRole('button',{name:'Sign out',exact:true}).click();await page.getByRole('link',{name:'Forgot password?',exact:true}).click();await expect(page.getByRole('button',{name:'Reset Owner password',exact:true})).toBeVisible();password='Recovery-Release-Final-42';secrets.push(password);
 await page.getByLabel('Username',{exact:true}).fill('testowner');await page.getByLabel('Current offline recovery code',{exact:true}).fill(code);await page.getByLabel('New password',{exact:true}).fill(password);await page.getByLabel('Confirm new password',{exact:true}).fill(password);await page.getByRole('button',{name:'Reset Owner password',exact:true}).click();await expect(dialog).toBeVisible();
 let storageText=JSON.stringify(await stored());for(const secret of secrets)assert.ok(!storageText.includes(secret),'Secret persisted in browser storage');await acknowledge();await expect(page.getByRole('heading',{name:'Sign in to your workspace'})).toBeVisible();
 await api('session',null,owner.token,401);await api('session',null,staff.token);
 await page.reload();await login();await page.getByRole('link',{name:'Register',exact:true}).click();await expect(page.getByRole('heading',{name:'Pending orders (1)',exact:true})).toBeVisible();assert.equal((await queue())[0].key,key);
 online=true;await page.evaluate(()=>window.dispatchEvent(new Event('online')));await expect.poll(async()=>(await queue())[0]?.state,{timeout:15000}).toBe('confirmed');assert.equal((await queue())[0].key,key);assert.equal((await queue()).length,1);
 const current=await api('login',{username:'testowner',password});const management=await api('management',null,current.token);assert.equal(management.ingredients.find(i=>Number(i.id)===ingredient).current_stock,'900.000');
 await page.reload();await expect(page.getByRole('button',{name:/View receipt #/})).toHaveCount(1);
 storageText=JSON.stringify(await stored());const logs=fs.readFileSync('storage/app.log','utf8')+(process.env.VV_TEST_HTTP_LOG?fs.readFileSync(process.env.VV_TEST_HTTP_LOG,'utf8'):'');
 for(const secret of secrets){assert.ok(!storageText.includes(secret),'Secret in storage');assert.ok(!urls.join('\n').includes(secret),'Secret in URL');assert.ok(!messages.join('\n').includes(secret),'Secret in console');assert.ok(!logs.includes(secret),'Secret in server log');}
 await page.getByRole('button',{name:'Sign out',exact:true}).click();await page.getByRole('link',{name:'Forgot password?',exact:true}).click();await expect(page.getByLabel('Current offline recovery code',{exact:true})).toHaveValue('');await expect(page.getByLabel('New password',{exact:true})).toHaveValue('');assert.deepEqual(errors,[]);
 console.log('PASS Task 5 browser: Owner pending order survives reset/reload/same-account sign-in; original key reconciles once with 100 mL deduction; Owner session revoked and staff session preserved; no recovery secrets in storage/URLs/console/server logs; leaving recovery clears inputs');
 await context.close();
}finally{await browser.close();}
