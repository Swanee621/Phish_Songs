<?php

namespace App\Console\Commands;

use App\Services\PhishNet\PhishNetRepository;
use App\Services\PhishNet\PhishNetSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled heartbeat that keeps the tour sync running.
 *
 * Runs every minute from the scheduler and performs a sync pass inline
 * whenever the configured interval since the last one has elapsed — the fast
 * `active_interval` while a show is underway, the slow idle `interval`
 * otherwise.
 *
 * The pass runs in this process rather than being queued, so the scheduler is
 * the loop's only dependency: no queue worker has to be alive for data to keep
 * flowing. Combined with the every-minute cadence, that makes the loop
 * self-healing — a failed, killed, or missed run is simply picked up by a
 * later tick, and a fresh deploy seeds itself the first time this fires, so
 * the loop can never be permanently stopped while the scheduler is up.
 */
class PhishNetTickCommand extends Command
{
    protected $signature = 'phish:tick';

    protected $description = 'Run a tour sync pass when the configured interval has elapsed (scheduled every minute)';

    public function handle(PhishNetRepository $repository, PhishNetSynchronizer $synchronizer): int
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

        try {
            $synchronizer->syncPass();
        } catch (Throwable $exception) {
            Log::error('phish.net sync pass failed.', [
                'error' => $exception->getMessage(),
            ]);

            /**
             * Keep the loop's clock moving across a failed pass. Restamping the
             * last known state touches only the database and cache, never the
             * API that just refused us, and is what lets the next attempt wait
             * out the normal interval instead of hammering a failing upstream
             * every minute. Trusting a possibly stale window flag is the
             * documented bias: a false "live" costs one extra poll, a false
             * "idle" a frozen live page.
             */
            try {
                $synchronizer->publishLiveState($live['showdate'], $live['inShowWindow']);
            } catch (Throwable) {
                // Even the restamp failing must not take the tick down; the
                // next one simply tries again.
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
