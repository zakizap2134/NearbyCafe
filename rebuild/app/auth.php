<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
function login(PDO $db,array $body,string $requestId): array {
    $name=username($body['username']??null);$password=$body['password']??null;
    if (!is_string($password) || strlen($password)>72 || str_contains($password,"\0")) problem(422,'VALIDATION','Enter a valid password.');
    // Account and remote-IP buckets separately discourage distributed guessing and username spraying.
    $buckets=[hash('sha256','user:'.$name),hash('sha256','ip:'.($_SERVER['REMOTE_ADDR']??'local'))];
    $db->beginTransaction();
    try {
        lock_state($db);
        query($db,'DELETE FROM login_limits WHERE window_started<DATE_SUB(NOW(),INTERVAL 15 MINUTE)');
        foreach($buckets as $index=>$bucket) {
            query($db,'INSERT IGNORE INTO login_limits(bucket,failures,window_started) VALUES(?,0,NOW())',[$bucket]);
            $failures=query($db,'SELECT failures FROM login_limits WHERE bucket=?',[$bucket])->fetchColumn();
            if ((int)$failures>=($index===0?5:30)) problem(429,'LOGIN_LIMIT','Too many sign-in attempts. Wait 15 minutes before trying again.');
        }
        $user=query($db,'SELECT * FROM users WHERE username=?',[$name])->fetch();
        $hash=$user['password_hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $valid=password_verify($password,$hash);
        if (!$user || !$valid || !(int)$user['is_active']) {
            foreach($buckets as $bucket) query($db,'UPDATE login_limits SET failures=failures+1 WHERE bucket=?',[$bucket]);
            audit($db,null,'LOGIN_FAILED',$requestId);
            $db->commit();problem(401,'INVALID_CREDENTIALS','Username or password is incorrect.');
        }
        if (password_needs_rehash($user['password_hash'],PASSWORD_BCRYPT)) query($db,'UPDATE users SET password_hash=?,updated_at=NOW() WHERE id=?',[password_hash($password,PASSWORD_BCRYPT),$user['id']]);
        query($db,'DELETE FROM login_limits WHERE bucket=?',[$buckets[0]]);
        query($db,'DELETE FROM sessions WHERE expires_at<=NOW()');
        $token=bin2hex(random_bytes(32));$expires=date('Y-m-d H:i:s',time()+settings()['session_hours']*3600);
        query($db,'INSERT INTO sessions(token_hash,user_id,created_at,expires_at) VALUES(?,?,NOW(),?)',[hash('sha256',$token),$user['id'],$expires]);
        audit($db,(int)$user['id'],'LOGIN_OK',$requestId);$db->commit();
        return ['user'=>public_user($user),'token'=>$token,'expires_at'=>$expires];
    } catch(Throwable $e) {if($db->inTransaction())$db->rollBack();throw $e;}
}
