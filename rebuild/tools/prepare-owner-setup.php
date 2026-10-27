<?php
declare(strict_types=1);
require_once __DIR__ . '/cli.php';
require_once __DIR__ . '/../app/owner_setup.php';
require_runtime();
$db = connection();
schema_ready($db);
$db->beginTransaction();
$temporary = null;
try {
    lock_state($db);
    if (!owner_setup_needed($db)) {
        problem(409, 'OWNER_EXISTS', 'Initial setup is closed. Use the existing account or the documented owner recovery process.');
    }
    $code = strtoupper(bin2hex(random_bytes(32)));
    $path = owner_setup_file();
    $temporary = tempnam(dirname($path), '.owner-setup-');
    if ($temporary === false) problem(503, 'SETUP_FILE', 'Cannot prepare the private setup file. Check storage permissions.');
    @chmod($temporary, 0600);
    $json = json_encode([
        'code_hash' => hash('sha256', $code),
        'expires_at' => time() + 86400,
        'epoch' => runtime_meta($db)['epoch'],
    ], JSON_THROW_ON_ERROR);
    if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !rename($temporary, $path)) {
        problem(503, 'SETUP_FILE', 'Cannot save the private setup file. Check storage permissions.');
    }
    $temporary = null;
    $db->commit();
    echo "Owner setup code (valid for 24 hours, displayed only here):\n";
    echo implode('-', str_split($code, 8)) . "\n";
    echo "Give this code privately to the owner. Open the app and choose Set up owner account.\n";
    echo "Preparing another code replaces the previous code. Do not publish or log this output.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
} finally {
    if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
}
