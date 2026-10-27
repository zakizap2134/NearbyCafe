import {chromium,expect} from '@playwright/test';
import assert from 'node:assert/strict';
const base=process.argv[2];const browser=await chromium.launch({headless:true,executablePath:process.env.VV_CHROMIUM_PATH,args:['--no-sandbox']});
try{
 const c=await browser.newContext(),p=await c.newPage();let password='Owner-Test-Only-42';
 async function api(route,body,token){const r=await c.request.fetch(base+'/api/index.php?route='+route,{method:body?'POST':'GET',data:body,headers:{Origin:base,...(token?{Authorization:'Bearer '+token}:{})}});assert.equal(r.status(),200);return(await r.json()).data;}
 async function issue(){const {token}=await api('login',{username:'testowner',password});const s=await api('management/recovery-code/status',null,token);return(await api('management/recovery-code/generate',{owner_password:password,expected_version:s.version,confirm_replace:true},token)).recovery_code;}
 let code=await issue();await p.goto(base+'/#/forgot-password');
 const submit=p.getByRole('button',{name:'Reset Owner password',exact:true});
 async function fill(value,next=password){await expect(submit).toBeVisible();await p.getByLabel('Username',{exact:true}).fill('testowner');await p.getByLabel('Current offline recovery code',{exact:true}).fill(value);await p.getByLabel('New password',{exact:true}).fill(next);await p.getByLabel('Confirm new password',{exact:true}).fill(next);}
 await fill(code,'short');await submit.click();await expect(p.getByRole('alert')).toContainText('Use at least 12 characters.');
 for (const value of ['é'.repeat(11),'😀'.repeat(11)]) { await fill(code,value);await submit.click();await expect(p.getByRole('alert')).toContainText('Use at least 12 characters.'); }
 await fill(code,'😀'.repeat(19));await submit.click();await expect(p.getByRole('alert')).toContainText('This password is too long. Use fewer characters; accented letters and emoji may take more space.');
 await fill('A'.repeat(64));await submit.click();await expect(p.getByRole('alert')).toContainText('incorrect');
 password='😀'.repeat(12);await fill(code,password);await p.locator('.owner-recovery-card form').evaluate(f=>{f.requestSubmit();f.requestSubmit();});const dialog=p.getByRole('dialog');await expect(dialog).toBeVisible();await expect(dialog.getByRole('button',{name:'Done — hide code'})).toBeDisabled();await p.keyboard.press('Escape');await expect(dialog).toBeVisible();code=await dialog.getByLabel('Owner recovery code',{exact:true}).inputValue();await dialog.getByRole('checkbox').check();await dialog.getByRole('button',{name:'Done — hide code'}).click();await expect(p.getByRole('heading',{name:'Sign in to your workspace'})).toBeVisible();assert.equal(await p.evaluate(()=>sessionStorage.getItem('venuevue.rebuild.session')),null);
 await p.getByRole('link',{name:'Forgot password?',exact:true}).click();await fill(code,'Lost-Reply-Password-42');await p.route('**/api/index.php?route=recovery%2Fowner',async r=>{await r.fetch();await r.abort();},{times:1});await submit.click();await expect(p.getByRole('alert')).toContainText('could not be confirmed');await expect(submit).toHaveCount(0);password='Lost-Reply-Password-42';code=await issue();assert.ok(code);
 console.log('PASS live browser recovery: validation, credential errors, successful reset, duplicate-submit guard, saved-code acknowledgement, no auto-login, committed lost reply and reauthenticated replacement');await c.close();
}finally{await browser.close();}
