<?php

namespace App\Jobs;

use App\Services\PhishNet\PhishNetRepository;
use App\Services\PhishNet\PhishNetSynchronizer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One pass of the phish.net sync, in one of two modes:
 *
 * - Idle (no show tonight): once per `phishnet.sync.interval` (hourly by
 *   default), check today's per-showdate feed (catches any show landing today),
 *   the most recent show's feed through the day after it was played (catches
 *   late setlist corrections), and the song catalog (play counts and gaps move
 *   with every played show). Only the heavyweight year feed stays on a daily
 *   cadence ({@see PhishNetSynchronizer::yearFeedIsStale}). Historical years
 *   never change, so they are imported once by `phish:backfill` and then read
 *   from the database forever.
 * - Show night: poll only tonight's per-showdate feed, once per
 *   `phishnet.sync.active_interval`. That single payload carries the songs and
 *   the show notes both, and doubles as the end-of-show check, so a live pass
 *   costs two API calls in total (schedule lookup + setlist).
 *
 * The scheduler's `phish:tick` drives the cadence, dispatching this as a
 * one-off whenever the applicable interval has elapsed. The self-re-dispatch
 * path (`$continuous`) remains for a manually started standalone loop.
 *
 * The uniqueness lock is released once processing starts rather than when the
 * job finishes, because a continuous run re-dispatches itself from inside
 * `handle()`; holding the lock to completion would silently swallow that next
 * run.
 */
#[Backoff([30, 60, 120])]
class SyncPhishNetTour implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Must stay below the queue connection's `retry_after` (90s), otherwise the
     * worker would release this job back onto the queue while it is still
     * running and the sync loop would fork into two chains.
     */
    public int $timeout = 60;

    public int $uniqueFor = 600;

    /**
     * @param  bool  $continuous  Whether to re-dispatch after running. A one-off
     *                            sync passes false; the watch loop passes true.
     */
    public function __construct(public bool $continuous = true) {}

    public function uniqueId(): string
    {
        return 'phishnet-sync-tour';
    }

    public function handle(PhishNetSynchronizer $synchronizer): void
    {
        $showdate = $synchronizer->currentLiveShowdate();

        if ($showdate === null) {
            /**
             * Idle: the hourly once-over. Today's feed catches any show
             * landing today — including one outside the modeled evening
             * window — the recent-show feed catches corrections through the
             * day after a show, and the song catalog keeps play counts and
             * gaps current. Only the heavyweight year feed waits for its
             * daily refresh, to catch corrections to older shows.
             */
            $synchronizer->syncToday();
            $synchronizer->syncRecentShow();
            $synchronizer->syncSongs();

            if ($synchronizer->yearFeedIsStale()) {
                $synchronizer->syncYear($synchronizer->currentShowYear());
            }

            $synchronizer->publishLiveState(null, false);
            $this->scheduleNextRun(false);

            return;
        }

        /**
         * Show night: tonight's feed is the only one that moves, so it is the
         * only one fetched — one payload carrying the songs and the show notes,
         * whose final-song marker also tells the loop when to back off. The
         * year and song catalogs catch up on the first idle run the morning
         * after. The showdate itself lingers past the final song so an open
         * page can still catch it; only the pacing drops back to idle.
         */
        $inShowWindow = $synchronizer->syncLiveShow($showdate);

        /**
         * Republish the snapshot the browser polls, so an open page picks up
         * both the new version hash and the current window flag on its next
         * poll without ever reaching the API itself.
         */
        $synchronizer->publishLiveState($showdate, $inShowWindow);

        /**
         * Only the successful path schedules the next run. A throwing run is
         * retried by the queue, and its final failure re-arms the loop from
         * failed(), so the loop can never fork into two chains.
         */
        $this->scheduleNextRun($inShowWindow);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('phish.net tour sync failed.', [
            'error' => $exception?->getMessage(),
        ]);

        /**
         * Keep the loop alive across a failed run, otherwise a single upstream
         * outage silently stops all future syncing.
         *
         * Re-publishing the last known state touches only the database, never
         * the API that just refused us, and restamping its clock is what lets
         * `phish:tick` wait out the normal interval instead of re-dispatching
         * every minute into the outage. Trusting a possibly stale window flag
         * is the documented bias: a false "live" costs one extra poll, a false
         * "idle" a frozen live page.
         */
        try {
            $live = app(PhishNetRepository::class)->liveState();
            $inShowWindow = $live['inShowWindow'];
            app(PhishNetSynchronizer::class)->publishLiveState($live['showdate'], $inShowWindow);
        } catch (Throwable) {
            $inShowWindow = false;
        }

        $this->scheduleNextRun($inShowWindow);
    }

    protected function scheduleNextRun(bool $inShowWindow = false): void
    {
        if (! $this->continuous) {
            return;
        }

        $interval = $inShowWindow
            ? (int) config('phishnet.sync.active_interval')
            : (int) config('phishnet.sync.interval');

        self::dispatch()->delay(now()->addSeconds($interval));
    }
}
