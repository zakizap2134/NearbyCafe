<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/bootstrap.php';
set_exception_handler(function(Throwable $e): void {
    $ref=bin2hex(random_bytes(8));diagnostic($e,$ref);
    fwrite(STDERR,'FAIL '.($e instanceof AppError?$e->errorCode.': '.$e->getMessage():'Unexpected setup failure. Check storage/app.log.').' [reference '.$ref.']'.PHP_EOL);
    exit(1);
});
function prompt(string $label): string {fwrite(STDOUT,$label.': ');$value=fgets(STDIN);if($value===false)problem(422,'INPUT_REQUIRED','Input ended before setup was complete.');return rtrim($value,"\r\n");}
function require_runtime(): void {
    if(PHP_VERSION_ID<80100)problem(503,'PHP_VERSION','PHP 8.1 or newer is required.');
    foreach(['pdo_mysql','openssl','json'] as $ext)if(!extension_loaded($ext))problem(503,'PHP_EXTENSION','Enable the PHP extension: '.$ext);
    if(preg_match_all('/./us', "é😀")!==2)problem(503,'PHP_UNICODE','PHP must support Unicode regular expressions.');
    if(!is_writable(__DIR__.'/../storage'))problem(503,'LOG_DIRECTORY','The storage directory must be writable by PHP.');
}
