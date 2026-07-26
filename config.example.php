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
        // Public base URL of THIS Mewmii OS install (no trailing slash), e.g.
        // "https://admin.mewmiibear.com" - falls back to the current request's own host
        // if left blank, which only works for web-triggered syncs, never a future
        // CLI/cron one - set this explicitly.
        'url' => getenv('APP_URL') ?: '',
        // Public base URL specifically for the /uploads folder (product images etc), e.g.
        // "https://mewmiibear.com" - ONLY needed if this differs from 'url' above. On a
        // subdomain deployment (admin app on admin.mewmiibear.com, but /uploads is only
        // publicly reachable at the root domain, or vice versa) these two are NOT the
        // same host, and using 'url' for both is exactly what caused WooCommerce image
        // sync to 404 - see includes/bootstrap.php's app_uploads_base_url(). Leave blank
        // to fall back to 'url' when the admin app and its uploads really are on the same
        // public host.
        'uploads_url' => getenv('APP_UPLOADS_URL') ?: '',
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
        // Phase 6E (WooCommerce webhook integration) - the secret configured on the
        // WooCommerce side (Settings > Advanced > Webhooks) for signing INBOUND deliveries
        // to modules/webhooks/woocommerce.php. Deliberately a separate key from
        // webhook_secret above, which is the OUTBOUND token this app sends to a custom
        // WordPress endpoint (see includes/wc_receipt_verification.php) - a different
        // direction, different trust boundary, so a compromise of one never compromises
        // the other.
        'webhook_receive_secret' => getenv('WC_WEBHOOK_RECEIVE_SECRET') ?: '',
    ],

];
