<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Settlement Provider
    |--------------------------------------------------------------------------
    |
    | Supported: "fake", "arc"
    |
    */
    'provider' => env('SETTLEMENT_PROVIDER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Default Invoicing Gateway
    |--------------------------------------------------------------------------
    |
    | Supported: "fake", "solidinvoice"
    |
    */
    'invoice_gateway' => env('INVOICE_GATEWAY', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Circle / Arc Testnet Settings
    |--------------------------------------------------------------------------
    */
    'arc' => [
        'circle_api_key' => env('CIRCLE_API_KEY', ''),
        'circle_entity_secret' => env('CIRCLE_ENTITY_SECRET', ''),
        'blockchain' => env('CIRCLE_BLOCKCHAIN', 'ARC-TESTNET'),
        'wallet_id' => env('ARC_WALLET_ID', ''),
        'wallet_address' => env('ARC_WALLET_ADDRESS', ''),
        'usdc_contract_address' => env('ARC_USDC_CONTRACT_ADDRESS', ''),
        'webhook_secret' => env('ARC_WEBHOOK_SECRET', ''),
        'allow_unsigned_webhooks' => env('SETTLEMENT_ALLOW_UNSIGNED_WEBHOOKS', false),
        'base_url' => env('CIRCLE_API_BASE_URL', 'https://api.circle.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SolidInvoice API Settings
    |--------------------------------------------------------------------------
    */
    'solidinvoice' => [
        'base_url' => env('SOLIDINVOICE_URL', 'http://localhost:8080'),
        'api_token' => env('SOLIDINVOICE_TOKEN', ''),
    ],
];
