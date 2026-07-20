<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sync
    |--------------------------------------------------------------------------
    |
    | Show and song data is mirrored into the local database and served from
    | there, so the API is only contacted by the background sync loop. These
    | options control how often that loop checks the tour in progress.
    |
    */

    'sync' => [

        /*
         * Seconds between checks of the currently running tour. Each check is a
         * single API request; the database and cache are only written when the
         * returned payload differs from the last one imported.
         */
        'interval' => (int) env('PHISHNET_SYNC_INTERVAL', 300),

        /*
         * The earliest show year to pull down during `phish:backfill`. Phish's
         * first show was in 1983.
         */
        'first_year' => (int) env('PHISHNET_FIRST_YEAR', 1983),

        /*
         * Seconds to pause between year requests while backfilling, to stay
         * friendly to the upstream API during the one-time historical import.
         */
        'backfill_delay' => (int) env('PHISHNET_BACKFILL_DELAY', 1),

    ],

];
