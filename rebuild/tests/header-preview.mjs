import {chromium,expect} from '@playwright/test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const base=process.argv[2],out=process.env.HEADER_PREVIEW_DIR;fs.mkdirSync(out,{recursive:true});
const browser=await chromium.launch({headless:true,executablePath:process.env.VV_CHROMIUM_PATH,args:['--no-sandbox']});
try {
 for(const role of ['OWNER','BARISTA']) {
  const c=await browser.newContext(),p=await c.newPage(),errors=[];p.on('pageerror',e=>errors.push(e.message));
  await p.route('**/api/index.php?*',async r=>{const route=new URL(r.request().url()).searchParams.get('route');let data={};
   if(route==='health')data={epoch:'synthetic-header'};
   if(route==='setup/status')data={needed:false};
   if(route==='login'||route==='session')data={token:'synthetic-session',user:{id:role==='OWNER'?1:2,username:'demo.user',display_name:role==='OWNER'?'Demo Owner':'Demo Barista',role}};
   if(route==='management')data={ingredients:[],products:[],variants:[],recipes:[],batches:[],containers:[],movements:[],sales:{date:'Preview',totals:{count:0,gross:0,net:0,vat:0},recent:[]}};
   if(route==='catalogue')data={variants:[]};
   if(route==='management/recovery-code/status')data={prepared:true,version:'a'.repeat(64)};
   await r.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data})});
  });
  await p.goto(base);await p.getByLabel('Username',{exact:true}).fill('demo.user');await p.getByLabel('Password',{exact:true}).fill('Synthetic-Only-42');await p.getByRole('button',{name:'Sign in',exact:true}).click();
  const header=p.locator('.workspace-header'),logo=header.locator('.header-logo');await expect(header).toBeVisible();await expect(header.getByRole('link',{name:'Management',exact:true})).toHaveCount(role==='OWNER'?1:0);
  for(const width of [1440,1024,768,390,320]) {
   await p.setViewportSize({width,height:1000});await expect.poll(()=>header.locator('img').evaluate(e=>e.complete&&e.naturalWidth===2000)).toBe(true);
   assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Page overflow');
   const h=await header.boundingBox(),b=await logo.boundingBox();assert.equal(b.x,0);
   if(width>900)assert.ok(Math.abs(b.width/width-.125)<.02);else assert.ok(b.width>=128);
   assert.equal(await header.evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(255, 249, 240)');
   assert.equal(await logo.evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(254, 34, 0)');
   assert.equal(await logo.locator('img').evaluate(e=>getComputedStyle(e).objectFit),'contain');
   for(const el of await header.locator('a,button').all()){const r=await el.boundingBox();assert.ok(r.height>=44&&r.width>=44);}
   if(role==='OWNER')await header.screenshot({path:`${out}/header-${width}.png`});
  }
  await p.setViewportSize({width:1440,height:1000});await header.getByRole('link',{name:'Register',exact:true}).click();await expect(p).toHaveURL(/#\/register$/);await expect(p.getByRole('heading',{name:'Current order'})).toBeVisible();
  if(role==='OWNER'){await header.getByRole('link',{name:'Management',exact:true}).click();await expect(p).toHaveURL(/#\/management$/);await p.screenshot({path:out+'/workspace-desktop.png',fullPage:true});}
  await header.getByRole('button',{name:'Sign out',exact:true}).click();await expect(p.getByRole('heading',{name:'Sign in to your workspace'})).toBeVisible();
  await p.getByRole('link',{name:'Forgot password?',exact:true}).click();await expect(p.locator('.recovery-brand-bar .header-logo')).toBeVisible();
  await p.setViewportSize({width:390,height:1000});assert.ok(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  assert.deepEqual(errors,[]);await c.close();
 }
 console.log('PASS header: 1440/1024/768/390/320px, desktop 1/8 proportion, cream background, original red, full contained logo, no overflow, 44px controls, Owner/Barista navigation, sign-out and recovery header; synthetic data only');
}finally{await browser.close();}
