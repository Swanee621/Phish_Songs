<?php

namespace App\Console\Commands;

use App\Jobs\SyncPhishNetTour;
use App\Services\PhishNet\PhishNetRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Scheduled heartbeat that keeps the tour sync running.
 *
 * Runs every minute from the scheduler and dispatches a one-off
 * {@see SyncPhishNetTour} whenever the configured interval since the last sync
 * has elapsed — the fast `active_interval` while a show is underway, the slow
 * idle `interval` otherwise.
 *
 * Driving the cadence from the scheduler rather than a job that re-dispatches
 * itself is what makes the loop self-healing: a stalled, failed, or lost run is
 * simply picked back up on the next tick, and a fresh deploy seeds itself the
 * first time this runs, so the loop can never be permanently stopped.
 *
 * The dispatched job is one-off ({@see SyncPhishNetTour::$continuous} false): it
 * does the sync and stops, leaving the next run entirely to this command. Its
 * uniqueness guard keeps a slow queue from piling several copies up at once.
 */
class PhishNetTickCommand extends Command
{
    protected $signature = 'phish:tick';

    protected $description = 'Dispatch a tour sync when the configured interval has elapsed (scheduled every minute)';

    public function handle(PhishNetRepository $repository): int
    {
        $live = $repository->liveState();

        $interval = $live['inShowWindow']
            ? (int) config('phishnet.sync.active_interval')
            : (int) config('phishnet.sync.interval');

        $lastRunAt = $live['updatedAt'] !== null
            ? Carbon::parse($live['updatedAt'])
            : null;

        // Nothing to do yet when the last sync is still inside the interval;
        // a null last-run means the loop has never run, so fall through and seed
        // it. Comparing against a past instant keeps the check sign-safe.
        if ($lastRunAt !== null && $lastRunAt->greaterThan(now()->subSeconds($interval))) {
            return self::SUCCESS;
        }

        SyncPhishNetTour::dispatch(continuous: false);

        return self::SUCCESS;
    }
}
