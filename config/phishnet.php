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
         * Seconds between idle checks, while no show is underway. Each check
         * is a few small requests: today's setlist feed, the most recent
         * show's feed through the day after it was played, and the song
         * catalog. The database and cache are only written when a returned
         * payload differs from the last one imported.
         */
        'interval' => (int) env('PHISHNET_SYNC_INTERVAL', 3600),

        /**
         * Seconds between refreshes of the current year's full setlist feed
         * while idle — the heavyweight pull that catches corrections to shows
         * older than the day-after window the hourly checks cover.
         */
        'catalog_interval' => (int) env('PHISHNET_SYNC_CATALOG_INTERVAL', 86400),

        /**
         * Seconds between checks while a show is underway, when the loop
         * switches to polling only tonight's setlist feed (which carries the
         * show notes with it) plus the day's schedule.
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
    | Client Polling
    |--------------------------------------------------------------------------
    |
    | The browser polls a lightweight endpoint for a version hash that changes
    | whenever new setlist data lands, so an open page can refresh itself while
    | a show is being played. It mirrors the server's own pacing: poll quickly
    | during a show window, and back off to a slow heartbeat otherwise. The
    | server decides which interval applies and hands it back in the response,
    | so the client never has to reason about the show window itself.
    |
    */

    'client' => [

        /**
         * Seconds the browser waits between live-status polls while no show is
         * underway — a slow heartbeat that mainly exists to notice when a show
         * window opens.
         */
        'interval' => (int) env('CLIENT_SYNC_INTERVAL', 3600),

        /**
         * Seconds between live-status polls while a show is underway, when the
         * version hash is actually moving.
         */
        'active_interval' => (int) env('CLIENT_SYNC_ACTIVE_INTERVAL', 60),

    ],

    /*
    |--------------------------------------------------------------------------
    | Header Live Song
    |--------------------------------------------------------------------------
    |
    | How the "on stage right now" run is shown in the app header when it is too
    | long to fit the space beside the live dot.
    |
    */

    'header' => [

        /**
         * 'scroll' animates a long run back and forth; 'wrap' lets it flow onto
         * up to `live_song_max_lines` lines and clips anything past that.
         */
        'live_song_display' => env('HEADER_LIVE_SONG_DISPLAY', 'scroll'),

        /**
         * The most lines a wrapped run may take before it is clipped. Ignored
         * while `live_song_display` is 'scroll'.
         */
        'live_song_max_lines' => (int) env('HEADER_LIVE_SONG_MAX_LINES', 2),

    ],

    /*
    |--------------------------------------------------------------------------
    | Show Window
    |--------------------------------------------------------------------------
    |
    | The API exposes no "show in progress" flag and no end-of-show marker, so a
    | live show is inferred from a scheduled date plus the wall clock.
    |
    | An outer gate in Eastern time decides when it is even worth asking the
    | API — nothing can be underway before 6pm Eastern, and a west coast show is
    | over by 4am Eastern — which keeps the schedule lookup off the wire for
    | most of the day. Inside that gate, a show on the schedule for the date is
    | enough to put the loop on show-night pacing until its closing song lands.
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
         * The hour on the day *after* a show, in the venue's local time, when
         * the browser stops treating that show as the current one.
         *
         * This is a display concern rather than a sync one: the loop still
         * winds down as soon as the closing song lands, but a page opened the
         * next morning keeps the show's songs highlighted and keeps the ones it
         * debuted on the not-played list until this hour passes.
         */
        'highlight_end_hour' => (int) env('PHISHNET_SHOW_HIGHLIGHT_END_HOUR', 14),

    ],

];
