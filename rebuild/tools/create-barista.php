<?php
declare(strict_types=1);
require_once __DIR__.'/cli.php';require_runtime();$db=connection();schema_ready($db);
echo "Stage 1 staff provisioning. Owner credentials required. Password entry is visible; use a private console.\n";
$ownerName=username(prompt('Owner username'));$ownerPassword=prompt('Owner password');
$name=username(prompt('Barista username'));$display=trim(prompt('Barista display name'));
if($display===''||strlen($display)>100)problem(422,'VALIDATION','Display name must contain 1–100 bytes.');
$password=prompt('Barista password (Use at least 12 characters.)');$confirm=prompt('Confirm barista password');if(!hash_equals($password,$confirm))problem(422,'VALIDATION','Passwords do not match.');
$hash=new_password($password, $confirm);unset($password);
$db->beginTransaction();
try {
    lock_state($db);$owner=query($db,"SELECT * FROM users WHERE username=? AND role='OWNER' AND is_active=1",[$ownerName])->fetch();
    if(!$owner||!password_verify($ownerPassword,$owner['password_hash']))problem(403,'OWNER_AUTH','Owner credentials are incorrect.');
    if(query($db,'SELECT id FROM users WHERE username=?',[$name])->fetch())problem(409,'USERNAME_EXISTS','Username is already in use.');
    query($db,"INSERT INTO users(username,display_name,password_hash,role,created_at,updated_at) VALUES(?,?,?,'BARISTA',NOW(),NOW())",[$name,$display,$hash]);
    audit($db,(int)$owner['id'],'BARISTA_PROVISIONED',bin2hex(random_bytes(8)));$db->commit();echo "Barista created.\n";
} catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
