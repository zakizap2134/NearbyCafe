// Synthetic test host only. Set VV_LAUNCHER_LOGIN=1 for the fixture Owner.
import {chromium,expect} from '@playwright/test';
import assert from 'node:assert/strict';
const base=process.argv[2];
const browser=await chromium.launch({headless:true,executablePath:process.env.VV_CHROMIUM_PATH,args:['--no-sandbox']});
try{
 const page=await browser.newPage();const failures=[];page.on('pageerror',e=>failures.push(e.message));
 await page.goto(base);await expect(page).toHaveTitle('Nearby Café');
 const icons=await page.locator('link[rel="icon"]').evaluateAll(ns=>ns.map(n=>n.href));assert.equal(icons.length,3);
 for(const href of icons){assert.ok(href.startsWith(base+'/'));const r=await page.request.get(href);assert.equal(r.status(),200);assert.ok((await r.body()).length>100);}
 await expect(page.getByRole('heading',{name:'Sign in to your workspace'})).toBeVisible();
 if(process.env.VV_LAUNCHER_LOGIN==='1'){
  await page.getByLabel('Username',{exact:true}).fill('launcherowner');await page.getByLabel('Password',{exact:true}).fill('Launcher-Test-Only-42');await page.getByRole('button',{name:'Sign in',exact:true}).click();
  await expect(page.getByRole('heading',{name:'Management',exact:true})).toBeVisible();
 }
 assert.deepEqual(failures,[]);console.log('PASS Chromium: local favicon ICO/PNG requests, Nearby Café title, rendered UI'+(process.env.VV_LAUNCHER_LOGIN==='1'?', Owner sign-in':'')+', no JavaScript errors');
}finally{await browser.close();}
