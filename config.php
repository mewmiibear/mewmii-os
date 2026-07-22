<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */

    'db' => [
        'host' => '127.0.0.1',
        'database' => 'u924285025_mewmii_os',
        'username' => 'u924285025_mewmii_admin',
        'password' => 'MewmiiTassama27!',
        'charset' => 'utf8mb4',
    ],

    /*
    |--------------------------------------------------------------------------
    | Application
    |--------------------------------------------------------------------------
    */

    'app' => [
        'name' => 'Mewmii OS',
        'environment' => 'production',
        'debug' => false,
        'timezone' => 'Asia/Kuala_Lumpur',
    ],

    /*
    |--------------------------------------------------------------------------
    | WooCommerce
    |--------------------------------------------------------------------------
    |
    | Credentials are never hardcoded here - they are read from environment
    | variables set on the server (same pattern as APP_ADMIN_EMAIL /
    | APP_ADMIN_PASSWORD in install.php). Empty string is the "not configured"
    | state, checked by wc_client_is_configured().
    */

    'woocommerce' => [
        'url' => getenv('WC_URL') ?: '',
        'consumer_key' => getenv('WC_CONSUMER_KEY') ?: '',
        'consumer_secret' => getenv('WC_CONSUMER_SECRET') ?: '',
        'webhook_secret' => getenv('WC_WEBHOOK_SECRET') ?: '',
    ],

];
