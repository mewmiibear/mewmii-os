<?php

/**
 * Template for config.php (Security Hardening Phase 4D). config.php itself is gitignored -
 * copy this file to config.php on a fresh install/clone. Every real value comes from the
 * environment (.env - see includes/env_loader.php and .env.example) - this file contains no
 * secrets and never needs to.
 */

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
