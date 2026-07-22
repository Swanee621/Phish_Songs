<?php

namespace App\Jobs;

use App\Services\PhishNet\PhishNetSynchronizer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks the currently running tour for changes, then re-dispatches itself so
 * the check repeats.
 *
 * The delay before the next run depends on whether a show is underway: setlists
 * only move while Phish is on stage, so the loop polls on
 * `phishnet.sync.active_interval` inside a show window and backs off to
 * `phishnet.sync.interval` the rest of the time.
 *
 * Only the live show year is polled — historical years never change, so they are
 * imported once by `phish:backfill` and then read from the database forever.
 *
 * The uniqueness lock is released once processing starts rather than when the
 * job finishes, because this job re-dispatches itself from inside `handle()`;
 * holding the lock to completion would silently swallow that next run.
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
        /*
         * Read before syncing, so a show that starts mid-run still tightens the
         * interval on this pass rather than the next one. The showdate is the
         * show scheduled for now; the loop stays fast only while that show is
         * still live — once its final song is marked it backs off, even though
         * the showdate lingers so an open page can still catch that last song.
         */
        $showdate = $synchronizer->showdateInWindow();
        $inShowWindow = $showdate !== null && ! $synchronizer->showHasEnded($showdate);

        // $year = $synchronizer->currentShowYear();

        /*
         * A changed year means new plays, and possibly songs whose catalog
         * counts moved, so the catalog is only re-checked when that happens.
         */
        // if ($synchronizer->syncYear($year)) {
        //    $synchronizer->syncSongs();
        // }

        /*
         * Republish the snapshot the browser polls, so an open page picks up
         * both the new version hash and the current window flag on its next
         * poll without ever reaching the API itself.
         */
        $synchronizer->publishLiveState($showdate, $inShowWindow);

        /*
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

        /*
         * Keep the loop alive across a failed run, otherwise a single upstream
         * outage silently stops all future syncing.
         *
         * Re-checking the window costs one request, and falls back to the idle
         * interval when that request fails too — backing off is the right
         * response to an upstream that is already refusing us.
         */
        try {
            $synchronizer = app(PhishNetSynchronizer::class);
            $showdate = $synchronizer->showdateInWindow();
            $inShowWindow = $showdate !== null && ! $synchronizer->showHasEnded($showdate);
            $synchronizer->publishLiveState($showdate, $inShowWindow);
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
