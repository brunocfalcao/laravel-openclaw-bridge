<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenClaw Gateway
    |--------------------------------------------------------------------------
    |
    | Connection settings for the OpenClaw AI gateway. Communicates via
    | WebSocket on the local loopback interface.
    |
    */

    'gateway' => [
        'url' => env('OC_GATEWAY_URL', 'ws://127.0.0.1:18789'),
        'token' => env('OC_GATEWAY_TOKEN', ''),
        'timeout' => (int) env('OC_GATEWAY_TIMEOUT', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Prefix
    |--------------------------------------------------------------------------
    |
    | Used to namespace OpenClaw sessions. Prevents cross-talk between
    | different applications sharing the same gateway.
    |
    */

    'session_prefix' => env('OC_SESSION_PREFIX', 'market-studies'),

    /*
    |--------------------------------------------------------------------------
    | Browser (CDP Screenshots)
    |--------------------------------------------------------------------------
    |
    | Chrome DevTools Protocol endpoint for headless Chrome screenshots.
    | Chrome must be running with --remote-debugging-port=9222 --headless.
    |
    */

    'browser' => [
        'url' => env('OC_BROWSER_URL', 'http://127.0.0.1:9222'),
    ],

];
