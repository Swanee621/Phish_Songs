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

        /**
         * Seconds between checks of the currently running tour while no show is
         * underway. Each check is a single API request; the database and cache
         * are only written when the returned payload differs from the last one
         * imported.
         */
        'interval' => (int) env('PHISHNET_SYNC_INTERVAL', 3600),

        /**
         * Seconds between checks while a show is underway, when setlists are
         * being entered upstream and the payload actually moves.
         *
         * phish.net caches responses for a few minutes and asks that clients
         * poll no faster than every ~5 minutes, so this must stay above 300.
         */
        'active_interval' => (int) env('PHISHNET_SYNC_ACTIVE_INTERVAL', 360),

        /**
         * The earliest show year to pull down during `phish:backfill`. Phish's
         * first show was in 1983.
         */
        'first_year' => (int) env('PHISHNET_FIRST_YEAR', 1983),

        /**
         * Seconds to pause between year requests while backfilling, to stay
         * friendly to the upstream API during the one-time historical import.
         */
        'backfill_delay' => (int) env('PHISHNET_BACKFILL_DELAY', 1),

    ],

    /*
    |--------------------------------------------------------------------------
    | Show Window
    |--------------------------------------------------------------------------
    |
    | The API exposes no "show in progress" flag and no end-of-show marker, so a
    | live show is inferred from a scheduled date plus the wall clock.
    |
    | Detection runs in two stages. An outer gate in Eastern time decides when
    | it is even worth asking the API — nothing can be underway before 6pm
    | Eastern, and a west coast show is over by 4am Eastern — which keeps the
    | schedule lookup off the wire for most of the day. Inside that gate the
    | show's own venue timezone is resolved from its state, and the window is
    | evaluated in local time.
    |
    */

    'show_window' => [

        /**
         * Timezone the outer gate is evaluated in.
         */
        'gate_timezone' => env('PHISHNET_SHOW_GATE_TIMEZONE', 'America/New_York'),

        /**
         * Hours between which the schedule is worth checking, in gate time.
         * `gate_end_hour` is the morning after, so it is always past midnight.
         */
        'gate_start_hour' => (int) env('PHISHNET_SHOW_GATE_START_HOUR', 18),
        'gate_end_hour' => (int) env('PHISHNET_SHOW_GATE_END_HOUR', 4),

        /**
         * The window around a show, in the venue's own local time. Opening an
         * hour before a typical 8pm downbeat covers early starts, and 01:00
         * covers a long second set plus encore.
         */
        'start_hour' => (int) env('PHISHNET_SHOW_START_HOUR', 19),
        'end_hour' => (int) env('PHISHNET_SHOW_END_HOUR', 1),

    ],

];
