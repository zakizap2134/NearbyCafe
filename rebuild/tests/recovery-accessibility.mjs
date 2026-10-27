import {chromium,expect} from '@playwright/test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const base=process.argv[2]||'http://127.0.0.1:18082',out=process.env.RECOVERY_PREVIEW_DIR||'/tmp/recovery-previews';fs.mkdirSync(out,{recursive:true});
const fake=Array(8).fill('A1B2C3D4').join('-');let kind='success',prepared=false;
const browser=await chromium.launch({headless:true,executablePath:process.env.VV_CHROMIUM_PATH,args:['--no-sandbox']});
try{
const c=await browser.newContext({viewport:{width:1440,height:1100}}),p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
await p.route('**/api/index.php?*',async r=>{const route=new URL(r.request().url()).searchParams.get('route');let data={},error,status=200;
 if(route==='health')data={epoch:'synthetic-only'};
 if(route==='setup/status')data={needed:false};
 if(route==='login'||route==='session')data={token:'synthetic-session',user:{id:1,username:'demo.owner',display_name:'Demo Owner',role:'OWNER'}};
 if(route==='management')data={ingredients:[],products:[],variants:[],recipes:[],batches:[],containers:[],movements:[],sales:{date:'Synthetic preview',totals:{count:0,gross:0,net:0,vat:0},recent:[]}};
 if(route==='management/staff')data={staff:[]};
 if(route==='management/recovery-code/status')data={prepared,version:'a'.repeat(64)};
 if(route==='management/recovery-code/generate'){prepared=true;data={prepared:true,version:'b'.repeat(64),recovery_code:fake};}
 if(route==='recovery/owner'){
  if(kind==='invalid'){status=403;error={code:'RECOVERY_INVALID',message:'The username or recovery code is incorrect.'};}
  else if(kind==='limit'){status=429;error={code:'RECOVERY_LIMIT',message:'Too many recovery attempts. Wait up to 15 minutes before trying again.'};}
  else if(kind==='lost'){await r.abort();return;}
  else data={reset:true,sessions_revoked:true,recovery_code:fake};
 }
 await r.fulfill({status,contentType:'application/json',body:JSON.stringify(error?{ok:false,error}:{ok:true,data})});
});
function lum(hex){const a=hex.match(/[a-f\d]{2}/gi).map(x=>parseInt(x,16)/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4);return a[0]*.2126+a[1]*.7152+a[2]*.0722;}
function ratio(a,b){let x=lum(a),y=lum(b);return(Math.max(x,y)+.05)/(Math.min(x,y)+.05);}
const pairs=[['Body','#392b25','#fffdf8',4.5],['Hint','#65564e','#fff9f0',4.5],['Action','#ffffff','#963e35',4.5],['Error','#742f28','#fff0eb',4.5],['Success','#285344','#edf5ee',4.5],['Input boundary','#8b786c','#fffdf8',3],['Focus','#28534e','#fff9f0',3]];
for(const [name,a,b,target] of pairs)assert.ok(ratio(a,b)>=target,name+' contrast');fs.writeFileSync(out+'/contrast.txt',pairs.map(([n,a,b,t])=>`${n}: ${ratio(a,b).toFixed(2)}:1 (target ${t}:1); ${a} on ${b}`).join('\n'));
async function geometry(){assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Horizontal overflow');}
async function mark(){await p.evaluate(()=>{if(document.getElementById('synthetic-preview'))return;const x=document.createElement('div');x.id='synthetic-preview';x.textContent='DESIGN PREVIEW · SYNTHETIC DATA ONLY · NOT A REAL ACCOUNT OR CODE';x.style.cssText='padding:10px;background:#28534e;color:#fff;text-align:center;font:700 12px/1.5 sans-serif';document.body.prepend(x);});}
async function shot(name){await mark();await p.screenshot({path:out+'/'+name+'.png',fullPage:true});}
const submit=p.getByRole('button',{name:'Reset Owner password',exact:true}),dialog=p.getByRole('dialog');
async function open(){await p.goto(base+'/#/forgot-password');await p.reload();await expect(submit).toBeVisible();}
async function fill(){await p.getByLabel('Username',{exact:true}).fill('demo.owner');await p.getByLabel('Current offline recovery code',{exact:true}).fill(fake);await p.getByLabel('New password',{exact:true}).fill('Synthetic-Only-42');await p.getByLabel('Confirm new password',{exact:true}).fill('Synthetic-Only-42');}
await open();assert.ok(await p.locator('.owner-recovery-card input').evaluateAll(a=>a.every(i=>i.labels.length&&(!i.getAttribute('aria-describedby')||i.getAttribute('aria-describedby').split(' ').every(id=>document.getElementById(id))))));
assert.equal(await submit.evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(150, 62, 53)');
for(const width of [1440,768,390,320]){await p.setViewportSize({width,height:1100});await geometry();for(const el of await p.locator('.owner-recovery-card button, .owner-recovery-card input, .recovery-back').all()){const b=await el.boundingBox();assert.ok(b.height>=47.9&&b.width>=47.9);}await p.locator('.recovery-brand-bar').click();await shot('recovery-'+width);}
await p.setViewportSize({width:1440,height:1100});await p.locator('#owner-recovery-title').focus();await p.keyboard.press('Tab');await expect(p.getByLabel('Username',{exact:true})).toBeFocused();assert.equal(await p.getByLabel('Username',{exact:true}).evaluate(e=>getComputedStyle(e).outlineWidth),'3px');
await p.keyboard.press('Tab');await p.keyboard.press('Tab');await expect(p.getByRole('button',{name:'Show current offline recovery code',exact:true})).toBeFocused();await p.keyboard.press('Enter');await expect(p.getByLabel('Current offline recovery code',{exact:true})).toHaveAttribute('type','text');
await submit.click();await expect(p.getByRole('alert')).toBeFocused();await expect(p.getByRole('alert')).toContainText('username');
for(const k of ['invalid','limit','lost']){await fill();kind=k;await submit.click();await expect(p.getByRole('alert')).toBeFocused();await shot('feedback-'+k);}
await open();kind='success';await fill();await submit.click();await expect(dialog).toBeVisible();await expect(dialog.getByRole('heading',{name:'Save your recovery code'})).toBeFocused();
for(let i=0;i<12;i++){await p.keyboard.press('Tab');assert.ok(await dialog.evaluate(d=>d.contains(document.activeElement)));}
await p.keyboard.press('Escape');await expect(dialog).toBeVisible();await expect(dialog.getByRole('button',{name:'Done — hide code'})).toBeDisabled();
await p.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async value=>{window.syntheticCopied=value;}}}));await dialog.getByRole('button',{name:'Copy code',exact:true}).click();await expect(dialog.getByRole('status')).toContainText('Code copied');assert.equal(await p.evaluate(()=>window.syntheticCopied),fake);await p.evaluate(()=>delete window.syntheticCopied);
await p.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async()=>{throw new Error('Synthetic failure');}}}));await dialog.getByRole('button',{name:'Copy code',exact:true}).click();await expect(dialog.getByRole('status')).toContainText('copy it manually');
const code=dialog.getByLabel('Owner recovery code',{exact:true});await code.focus();assert.ok(await code.evaluate(e=>e.selectionStart===0&&e.selectionEnd===e.value.length));
await p.evaluate(()=>{window.savedURL=URL.createObjectURL;URL.createObjectURL=()=>{throw new Error('Synthetic failure');};});await dialog.getByRole('button',{name:'Download recovery sheet'}).click();await expect(dialog.getByRole('status')).toContainText('write all eight groups on paper');await p.evaluate(()=>{URL.createObjectURL=window.savedURL;delete window.savedURL;});
const waiting=p.waitForEvent('download');await dialog.getByRole('button',{name:'Download recovery sheet'}).click();const dl=await waiting,stream=await dl.createReadStream(),chunks=[];for await(const chunk of stream)chunks.push(chunk);const sheet=Buffer.concat(chunks).toString();for(const text of ['demo.owner',fake,'Forgot password?','initial setup code','Management > Account security','password may already have changed'])assert.ok(sheet.includes(text));fs.writeFileSync(out+'/synthetic-recovery-sheet.txt','SYNTHETIC EXAMPLE — NOT A REAL CODE\n\n'+sheet);await dl.delete();
for(const width of [1440,768,390,320]){await p.setViewportSize({width,height:1100});await geometry();assert.ok(await code.evaluate(e=>e.scrollWidth<=e.clientWidth&&e.scrollHeight<=e.clientHeight),'Code clipping');const b=await dialog.boundingBox();assert.ok(b.x>=0&&b.x+b.width<=width);assert.equal(await dialog.locator('.recovery-check').evaluate(e=>getComputedStyle(e).flexDirection),'row');for(const el of await dialog.getByRole('button').all()){const b=await el.boundingBox();assert.ok(b.height>=48&&b.width>=48);}await dialog.evaluate(d=>{if(!d.querySelector('.synthetic-only')){const x=document.createElement('p');x.className='synthetic-only';x.textContent='SYNTHETIC PREVIEW — NOT A REAL CODE';x.style.cssText='color:#285344;background:#edf5ee;padding:8px;font-weight:700';d.prepend(x);}d.scrollTop=0;});await shot('save-code-'+width);}
await dialog.getByRole('checkbox').check();await dialog.getByRole('button',{name:'Done — hide code'}).click();await expect(p.getByRole('heading',{name:'Sign in to your workspace'})).toBeVisible();
await p.getByLabel('Username',{exact:true}).fill('demo.owner');await p.getByLabel('Password',{exact:true}).fill('Synthetic-Only-42');await p.getByRole('button',{name:'Sign in',exact:true}).click();await p.getByRole('button',{name:'Account security',exact:true}).click();const panel=p.locator('.owner-recovery-code');await expect(panel).toContainText('No recovery code is prepared');
for(const width of [1440,768,390]){await p.setViewportSize({width,height:1100});await geometry();await panel.evaluate(e=>{if(e.querySelector('.synthetic-only'))return;const x=document.createElement('p');x.className='synthetic-only';x.textContent='SYNTHETIC PREVIEW — DEMO ACCOUNT';e.prepend(x);});await panel.screenshot({path:out+'/preparation-'+width+'.png'});}
await panel.getByLabel('Current Owner password for recovery code',{exact:true}).fill('Synthetic-Only-42');await panel.getByRole('button',{name:'Generate recovery code',exact:true}).click();await expect(dialog).toBeVisible();await dialog.getByRole('checkbox').check();await dialog.getByRole('button',{name:'Done — hide code'}).click();await expect(panel).toContainText('Recovery code saved');await panel.getByLabel('Current Owner password for recovery code',{exact:true}).fill('Synthetic-Only-42');await panel.getByRole('button',{name:'Replace recovery code',exact:true}).click();await expect(panel.getByRole('alert')).toBeFocused();
await p.getByRole('button',{name:'Sign out',exact:true}).click();await expect(p.getByRole('heading',{name:'Sign in to your workspace'})).toBeVisible();await open();await p.setViewportSize({width:390,height:1100});await p.addStyleTag({content:'html{font-size:200%!important}'});await geometry();await expect(submit).toBeVisible();
assert.deepEqual(errors,[]);console.log('PASS Task 4: desktop/tablet/mobile/320px and 200% text reflow; labels/help references; 48px targets; computed muted theme; contrast; keyboard Show/Hide; focused alerts; modal Tab containment/Escape/acknowledgement; code wrapping; clipboard/download fallbacks; sheet wording; Owner preparation UI. Previews use mocked synthetic data only.');await c.close();
}finally{await browser.close();}
