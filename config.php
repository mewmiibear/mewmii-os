<?php
return [

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */

    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'database' => getenv('DB_DATABASE') ?: '',
        'username' => getenv('DB_USERNAME') ?: '',
        'password' => getenv('DB_PASSWORD') ?: '',
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
        'url' => 'https://admin.mewmiibear.com',
        'uploads_url' => 'https://mewmiibear.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | WooCommerce
    |--------------------------------------------------------------------------
    */

    'woocommerce' => [
        'url' => getenv('WC_URL') ?: '',
        'consumer_key' => getenv('WC_CONSUMER_KEY') ?: '',
        'consumer_secret' => getenv('WC_CONSUMER_SECRET') ?: '',
        'webhook_secret' => getenv('WC_WEBHOOK_SECRET') ?: '',
    ],

];
