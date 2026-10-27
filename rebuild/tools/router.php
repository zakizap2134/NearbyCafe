<?php
// Development server: route only the API and built public assets, never project files.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if ($path==='/api/index.php') {require __DIR__.'/../public/api/index.php';return true;}
if ($path==='/launcher-health.php') {require __DIR__.'/../public/launcher-health.php';return true;}
if (preg_match('~^/(favicon\.ico|favicon-(16|32|48|192)\.png|apple-touch-icon\.png)$~D',$path) && is_file(__DIR__.'/../public'.$path)) return false;
if ($path==='/' || $path==='/index.html') {
    $file=__DIR__.'/../public/index.html';
    if (is_file($file)) {header('Content-Type: text/html; charset=utf-8');readfile($file);return true;}
}
$file=realpath(__DIR__.'/../public'.$path);$assets=realpath(__DIR__.'/../public/assets');
if ($assets && $file && str_starts_with($file,$assets.DIRECTORY_SEPARATOR) && is_file($file) && in_array(pathinfo($file,PATHINFO_EXTENSION),['js','css','svg','png','woff2'],true)) return false;
http_response_code(404);header('Content-Type: application/json');echo '{"ok":false,"error":{"code":"NOT_FOUND","message":"Not found"}}';return true;
