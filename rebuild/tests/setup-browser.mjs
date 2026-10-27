import { chromium, expect } from '@playwright/test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
const base=process.argv[2],out=process.env.VV_RELEASE_PREVIEW_DIR||'test-results/release';fs.mkdirSync(out,{recursive:true});
const browser=await chromium.launch({headless:true,executablePath:process.env.VV_CHROMIUM_PATH,args:['--no-sandbox']});
try{
 const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 const click=n=>page.getByRole('button',{name:n,exact:true}).click(),fill=(n,v)=>page.getByLabel(n,{exact:true}).fill(v);
 await page.goto(base);await click('Set up owner account');assert.equal(await page.getByRole('combobox').count(),0);
 for(const width of [1440,768,390]){await page.setViewportSize({width,height:1000});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:`${out}/owner-setup-${width}.png`,fullPage:true});}
 await fill('Display name','Browser Owner');await fill('Username','BrowserOwner');await fill('Password','Browser-Owner-Only-42');await fill('Confirm password','Mismatch-Only-42');await fill('One-time setup code',process.env.VV_TEST_SETUP_CODE);await click('Create owner account');await expect(page.getByRole('alert')).toContainText('Passwords do not match');
 await fill('Confirm password','Browser-Owner-Only-42');await click('Show password');await expect(page.getByLabel('Password',{exact:true})).toHaveAttribute('type','text');await click('Create owner account');
 await expect(page.getByRole('heading',{name:'Sign in to your workspace',exact:true})).toBeVisible();await expect(page.getByLabel('Username',{exact:true})).toHaveValue('browserowner');await expect(page.getByLabel('Password',{exact:true})).toHaveValue('');await expect(page.getByRole('button',{name:'Set up owner account',exact:true})).toHaveCount(0);
 await fill('Password','Browser-Owner-Only-42');await click('Sign in');await expect(page.locator('.role')).toContainText('Owner');await expect(page).toHaveURL(/#\/management$/);assert.deepEqual(errors,[]);
 console.log('PASS setup browser: confirmation, visibility, no role selector, return to sign-in, Owner landing and desktop/tablet/mobile layout');
}finally{await browser.close();}
