<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'nightwatch' => [
        /*
         * Comma separated client IPs whose requests are dropped before
         * Nightwatch ships them, so browsing the site yourself does not fill
         * the dashboard. Kept in the environment rather than here because they
         * are personal addresses and this repository is public.
         */
        'ignored_ips' => env('NIGHTWATCH_IGNORED_IPS', ''),
    ],

    'maxmind' => [
        /*
         * Free GeoLite2 account credentials. `geoip:update` uses them to fetch
         * the city database that visitor addresses are resolved against
         * locally; nothing about a visitor is ever sent to MaxMind.
         */
        'account_id' => env('MAXMIND_ACCOUNT_ID'),
        'license_key' => env('MAXMIND_LICENSE_KEY'),
        'edition' => env('MAXMIND_EDITION', 'GeoLite2-City'),
        'database_path' => env('MAXMIND_DATABASE_PATH', storage_path('app/geoip/GeoLite2-City.mmdb')),
    ],

    'phishnet' => [
        'key' => env('PHISHNET_API_KEY'),
        'salt' => env('PHISHNET_API_SALT'),

        /*
         * Song slugs to leave out of the Tour Checker's played / not-played
         * counts (e.g. placeholder or non-song setlist entries).
         */
        'excluded_songs' => [
            //
        ],
    ],

];
