<?php
/**
 * VenueVue Store-and-Forward Synchronization Configuration
 * ----------------------------------------------------------------------------
 * Shared configuration settings for Local Daemon and Cloud API.
 */

$cloudApiUrl = 'https://nearbycafe.infinityfreeapp.com/api';
$cloudConfigPath = dirname(__DIR__, 2) . '/rebuild/config/cloud.local.json';
if (file_exists($cloudConfigPath)) {
    $custom = json_decode(file_get_contents($cloudConfigPath), true);
    if (!empty($custom['cloud_url'])) {
        $cloudApiUrl = rtrim($custom['cloud_url'], '/') . '/api';
    }
}

return [
    // Pre-shared security token to authenticate shop pushes to the cloud
    'sync_secret_token' => 'NV_SYNC_SEC_98f4b6e2a1_intramuros_nearbycafe',
    
    // Identification for the physical shop instance
    'shop_id' => 'nearbycafe_intramuros_01',
    'shop_name' => 'Nearby Café (Legaspi St., Intramuros)',

    // Target Cloud API URL (Where the local daemon pushes outbox records)
    'cloud_api_url' => $cloudApiUrl,
    
    // Batch processing limits
    'batch_size' => 50,             // Number of transactions to send in one POST payload
    'connect_timeout_seconds' => 3, // Fast fail timeout so offline detection is instantaneous
    'request_timeout_seconds' => 10,
    'daemon_sleep_seconds' => 15,   // Seconds to wait between sync polling cycles

    // Local MariaDB connection (Local Shop PC)
    'local_db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'root',
        'pass' => '',
        'name' => 'venuevue_rebuild',
    ],

    // Cloud MariaDB connection (When running on the Cloud Host directly)
    'cloud_db' => [
        'host' => 'sql305.infinityfree.com',
        'port' => 3306,
        'user' => 'if0_43331399',
        'pass' => 'PASTE_VPANEL_PASSWORD_HERE',
        'name' => 'if0_43331399_venuevue',
    ],
];
