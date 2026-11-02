<?php
// Copy to local.php. This is the sole runtime configuration file; never commit it.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'venuevue_rebuild',
        'user' => 'root',
        'password' => '',
    ],
    'session_hours' => 8,
    // Optional SMTP configuration for online password reset emails (falls back to PHP mail() if omitted)
    'smtp' => [
        'enabled' => false,
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'username' => '',
        'password' => '',
        'from_email' => 'noreply@nearbycafe.local',
        'from_name' => 'Nearby Café POS',
        'encryption' => 'tls', // 'tls', 'ssl', or ''
    ],
];
