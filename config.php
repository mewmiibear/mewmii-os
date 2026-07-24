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
        'password' => 'Tassama27!',
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
    | Security Hardening Phase 4C: read from the environment first (via .env - see
    | includes/env_loader.php - or a real server env var, either works), falling back to the
    | values below only if the environment doesn't provide them. This is a deliberate
    | transition step, not the end state - these fallback values are the SAME live credentials
    | that were already hardcoded here before this change, kept only so nothing breaks before
    | .env is actually deployed on the server. Once .env is confirmed working in production,
    | these fallback strings should be deleted in a follow-up commit and the keys rotated -
    | see .env.example for the expected variable names. Empty string is still the
    | "not configured" state, checked by wc_client_is_configured().
    */

    'woocommerce' => [
        'url' => getenv('WC_URL') ?: 'https://mewmiibear.com',
        'consumer_key' => getenv('WC_CONSUMER_KEY') ?: 'ck_424dc98501df5ccad492f7ad3b332b1e551c3f00',
        'consumer_secret' => getenv('WC_CONSUMER_SECRET') ?: 'cs_acd5163f0111ea2cca74992cf17801b6724d94ee',
        'webhook_secret' => getenv('WC_WEBHOOK_SECRET') ?: '3d41e506bf4484296e2773955bb3832f896a1b011be38b3d7448c67e4efa7ffd',
    ],

];
