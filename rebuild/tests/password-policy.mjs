import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { staffPasswordError } from '../src/password-policy.js';
const short='Use at least 12 characters.';
const long='This password is too long. Use fewer characters; accented letters and emoji may take more space.';
const cases=[];
for(const value of ['a'.repeat(11),'é'.repeat(11),'😀'.repeat(11),'é'.repeat(6)])cases.push([value,value,short]);
for(const value of ['a'.repeat(12),'é'.repeat(12),'😀'.repeat(12),'é'.repeat(36),'😀'.repeat(18),'a'.repeat(72),'e\u0301'.repeat(6),'  spaced  12  ','line\nbreak12'])cases.push([value,value,null]);
for(const value of ['a'.repeat(73),'é'.repeat(37),'😀'.repeat(19),'😀'.repeat(18)+'a'])cases.push([value,value,long]);
cases.push(['abc\0defghijkl','abc\0defghijkl','Passwords cannot contain NUL characters.'],['abcdef123456','different','Passwords do not match.'],['abcdef123456',null,'Passwords do not match.']);
const php=spawnSync(process.env.PHP||'php',['-r',`
require 'app/bootstrap.php';
$cases=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$results=[];
foreach($cases as [$password,$confirmation]) {
 try { $hash=new_password($password,$confirmation); if(!password_verify($password,$hash))throw new Exception('Changed password');$results[]=null; }
 catch(AppError $e) { $results[]=$e->getMessage(); }
}
echo json_encode($results,JSON_THROW_ON_ERROR);
`],{input:JSON.stringify(cases),encoding:'utf8'});
assert.equal(php.status,0,php.stderr);
const backend=JSON.parse(php.stdout);
cases.forEach(([value,confirmation,expected],i)=>{
 assert.equal(staffPasswordError(value,confirmation),expected,`Frontend case ${i}`);
 assert.equal(backend[i],expected,`PHP case ${i}`);
});
assert.equal(staffPasswordError('abcdefghijk\ud800','abcdefghijk\ud800'),'Enter a password using valid Unicode characters.');
const malformed=spawnSync(process.env.PHP||'php',['-r',`require 'app/bootstrap.php';try{validate_new_password(str_repeat('a',11).chr(255),str_repeat('a',11).chr(255));exit(1);}catch(AppError $e){echo $e->getMessage();}`],{encoding:'utf8'});
assert.equal(malformed.status,0);assert.equal(malformed.stdout,'Enter a password using valid Unicode characters.');
console.log(`PASS ${cases.length} shared PHP/JavaScript password cases: code points, combining marks, exact 72-byte boundary, confirmation, NUL, whitespace/newline preservation; malformed Unicode rejected`);
