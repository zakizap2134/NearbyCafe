<?php
declare(strict_types=1);
ini_set('display_errors','0'); ini_set('log_errors','0');
header('Content-Type: application/json'); header('Cache-Control: no-store');
if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){http_response_code(404);echo '{"ok":false}';exit;}
try {
 require_once __DIR__.'/../app/bootstrap.php';
 $config=json_decode((string)@file_get_contents(__DIR__.'/../config/launcher.local.json'),true);
 if(!is_array($config)||!preg_match('/^[a-f0-9]{32}$/D',$config['InstallationId']??''))throw new RuntimeException();
 $db=connection();schema_ready($db);$meta=runtime_meta($db);
 echo json_encode(['ok'=>true,'installation'=>$config['InstallationId'],'epoch'=>$meta['epoch']],JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(503);echo '{"ok":false}';}
