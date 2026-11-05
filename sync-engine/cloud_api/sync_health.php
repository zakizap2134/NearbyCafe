<?php
/**
 * VenueVue Cloud Health & Ping Endpoint
 * ----------------------------------------------------------------------------
 * Responds to heartbeat pings from physical shop hosts.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config/sync_config.php';

// Check Bearer Token
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches) || !hash_equals($config['sync_secret_token'], trim($matches[1]))) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized: Invalid sync bearer token']);
    exit;
}

echo json_encode([
    'ok' => true,
    'server' => 'VenueVue Cloud Mirror',
    'status' => 'HEALTHY',
    'shop_id' => $config['shop_id'],
    'timestamp' => date('c'),
    'config_version' => 1,
], JSON_PRETTY_PRINT);
