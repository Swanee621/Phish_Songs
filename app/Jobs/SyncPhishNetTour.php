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
 * the check repeats every `phishnet.sync.interval` seconds.
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
        $year = $synchronizer->currentShowYear();

        /*
         * A changed year means new plays, and possibly songs whose catalog
         * counts moved, so the catalog is only re-checked when that happens.
         */
        if ($synchronizer->syncYear($year)) {
            $synchronizer->syncSongs();
        }

        /*
         * Only the successful path schedules the next run. A throwing run is
         * retried by the queue, and its final failure re-arms the loop from
         * failed(), so the loop can never fork into two chains.
         */
        $this->scheduleNextRun();
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('phish.net tour sync failed.', [
            'error' => $exception?->getMessage(),
        ]);

        /*
         * Keep the loop alive across a failed run, otherwise a single upstream
         * outage silently stops all future syncing.
         */
        $this->scheduleNextRun();
    }

    protected function scheduleNextRun(): void
    {
        if (! $this->continuous) {
            return;
        }

        self::dispatch()->delay(now()->addSeconds(
            (int) config('phishnet.sync.interval'),
        ));
    }
}
