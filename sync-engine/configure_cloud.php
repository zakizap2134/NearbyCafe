<?php
/**
 * Interactive / Scriptable Cloud Account Switcher for Nearby Cafe Live Sync
 */

$configFile = dirname(__DIR__) . '/rebuild/config/cloud.local.json';

echo "====================================================\n";
echo "NEARBY CAFE · CLOUD ACCOUNT CONFIGURATION\n";
echo "====================================================\n";

$currentUrl = 'https://nearbycafe.infinityfreeapp.com';
$currentUser = 'zakizap';
$currentPass = 'callmepapaton2x';
$currentShopId = 'nearbycafe_intramuros_01';

if (file_exists($configFile)) {
    $existing = json_decode(file_get_contents($configFile), true);
    if (!empty($existing['cloud_url'])) $currentUrl = $existing['cloud_url'];
    if (!empty($existing['cloud_user'])) $currentUser = $existing['cloud_user'];
    if (!empty($existing['cloud_pass'])) $currentPass = $existing['cloud_pass'];
    if (!empty($existing['shop_id'])) $currentShopId = $existing['shop_id'];
}

echo "Current Target: {$currentUrl}\n";
echo "Current User:   {$currentUser}\n\n";

// Check if running from arguments (non-interactive)
$args = getopt('', ['url:', 'user:', 'pass:', 'shop:']);

if (!empty($args['url'])) {
    $newUrl = trim($args['url']);
    $newUser = !empty($args['user']) ? trim($args['user']) : $currentUser;
    $newPass = !empty($args['pass']) ? trim($args['pass']) : $currentPass;
    $newShopId = !empty($args['shop']) ? trim($args['shop']) : $currentShopId;
} else {
    // Interactive prompt
    echo "Enter new InfinityFree Cloud URL [Press ENTER to keep '{$currentUrl}']:\n> ";
    $line = trim(fgets(STDIN));
    $newUrl = $line !== '' ? $line : $currentUrl;

    echo "Enter Owner/Admin Username [Press ENTER to keep '{$currentUser}']:\n> ";
    $line = trim(fgets(STDIN));
    $newUser = $line !== '' ? $line : $currentUser;

    echo "Enter Owner/Admin Password [Press ENTER to keep current password]:\n> ";
    $line = trim(fgets(STDIN));
    $newPass = $line !== '' ? $line : $currentPass;

    $newShopId = $currentShopId;
}

$newUrl = rtrim($newUrl, '/');
if (!preg_match('#^https?://#i', $newUrl)) {
    $newUrl = 'https://' . $newUrl;
}

$payload = [
    'cloud_url' => $newUrl,
    'cloud_user' => $newUser,
    'cloud_pass' => $newPass,
    'shop_id' => $newShopId,
    'updated_at' => date('c'),
];

if (!is_dir(dirname($configFile))) {
    @mkdir(dirname($configFile), 0777, true);
}

file_put_contents($configFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\n[✓] Cloud configuration successfully saved to:\n    {$configFile}\n";
echo "Target Cloud: {$newUrl}\n";
echo "Account User: {$newUser}\n";
echo "Live Sync will automatically push transactions to this account upon next start.\n";
echo "====================================================\n";
