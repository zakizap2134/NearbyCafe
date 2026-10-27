<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0'); ini_set('log_errors','0');
require_once __DIR__.'/../app/owner_setup.php';
try {
    $command=$argv[1]??'';
    if ($command==='config') {
        $d=settings()['db']; $data=['host'=>$d['host'],'port'=>$d['port'],'name'=>$d['name']];
    } elseif ($command==='database') {
        $db=connection(false); $data=$db->query('SELECT @@port AS port, @@datadir AS datadir, VERSION() AS version')->fetch();
    } elseif ($command==='ready') {
        // The Windows adapter runs the full preflight first; this returns only readiness metadata.
        $db=connection(); schema_ready($db); $meta=runtime_meta($db);
        foreach(VV_TABLES as $table) {
            $q=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);
            if($q->fetchColumn()!=='InnoDB') problem(503,'SCHEMA_MISMATCH','Incomplete schema.');
        }
        if(!(bool)$db->query("SELECT 1 FROM users WHERE role='OWNER' AND is_active=1 LIMIT 1")->fetchColumn() && !owner_setup_status($db)['ready']) problem(503,'OWNER_MISSING','Owner setup needed.');
        $data=['epoch'=>$meta['epoch']];
    } else { problem(400,'PROBE_FAILED','Unknown probe.'); }
    echo json_encode(['ok'=>true,'data'=>$data],JSON_THROW_ON_ERROR);
} catch(Throwable $e) {
    while(ob_get_level()) ob_end_clean();
    echo json_encode(['ok'=>false,'code'=>$e instanceof AppError?$e->errorCode:'PROBE_FAILED']); exit(1);
}
