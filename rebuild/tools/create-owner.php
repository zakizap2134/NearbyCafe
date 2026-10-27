<?php
declare(strict_types=1);
require_once __DIR__.'/cli.php';require_once __DIR__.'/../app/owner_setup.php';require_runtime();$db=connection();schema_ready($db);
echo "Initial owner setup. Password entry is visible; use a private host console.\n";
$name=username(prompt('Owner username'));$display=trim(prompt('Display name'));
if($display===''||strlen($display)>100)problem(422,'VALIDATION','Display name must contain 1–100 bytes.');
$password=prompt('Password (Use at least 12 characters.)');$confirm=prompt('Confirm password');
if(!hash_equals($password,$confirm))problem(422,'VALIDATION','Passwords do not match.');
$hash=new_password($password, $confirm);unset($password,$confirm);
$db->beginTransaction();
try {
    lock_state($db);
    if(!owner_setup_needed($db))problem(409,'OWNER_EXISTS','Initial owner setup is disabled because accounts already exist.');
    query($db,"INSERT INTO users(username,display_name,password_hash,role,created_at,updated_at) VALUES(?,?,?,'OWNER',NOW(),NOW())",[$name,$display,$hash]);
    audit($db,(int)$db->lastInsertId(),'OWNER_PROVISIONED',bin2hex(random_bytes(8)));$db->commit();
    echo "Owner created. Run php tools/preflight.php, then start the app and sign in.\n";
} catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
