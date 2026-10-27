<?php
// Apache piped logger. Never persist raw errors, request paths, headers or credentials.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');ini_set('log_errors','0');
$file=__DIR__.'/../storage/launcher/apache-status.log';
while(($line=fgets(STDIN,8192))!==false){
 $level=preg_match('/\[(emerg|alert|crit|error|warn|notice|info|debug)\]/',$line,$m)?$m[1]:'error';
 $code=preg_match('/\bAH[0-9]{5}\b/',$line,$m)?$m[0]:'APACHE_EVENT';
 clearstatcache(true,$file);if(is_file($file)&&filesize($file)>262144)@rename($file,$file.'.old');
 @file_put_contents($file,gmdate('c').' '.$level.' '.$code.PHP_EOL,FILE_APPEND|LOCK_EX);
}
