<?php
declare(strict_types=1);
require __DIR__.'/../app/stock.php';
$base=['id'=>1,'batch_id'=>1,'ingredient_id'=>1,'quarantined'=>0,'remaining_qty'=>'100.000','expiration_date'=>'2026-11-20','opened_at'=>null,'use_by'=>null];
function check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException($message);}
check(stock_eligible($base,'2026-11-20 23:59:59'),'Expiry-today must remain usable before midnight');
check(!stock_eligible($base,'2026-11-21 00:00:00'),'Expired batch must be excluded at next midnight');
$opened=array_replace($base,['opened_at'=>'2026-11-18 12:00:00','use_by'=>'2026-11-20 12:00:00']);
check(stock_eligible($opened,'2026-11-20 11:59:59'),'Opened stock usable one second before use-by');
check(!stock_eligible($opened,'2026-11-20 12:00:00'),'Use-by equality must be excluded');
// An allocation uses the caller's frozen sale timestamp, not wall-clock time.
$allocation=stock_plan([$base],1000,'2026-11-20 23:59:59','Milk');
check(count($allocation)===1&&$allocation[0]['take']===1000,'Allocation must respect frozen sale time');
echo "PASS frozen sale time, midnight expiry and exact use-by boundary\n";
