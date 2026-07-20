<?php

namespace App\Console\Commands;

use App\Jobs\SyncPhishNetTour;
use Illuminate\Console\Command;

class PhishNetWatchCommand extends Command
{
    protected $signature = 'phish:watch';

    protected $description = 'Start the background loop that re-checks the current tour on the configured interval';

    public function handle(): int
    {
        $interval = (int) config('phishnet.sync.interval');

        SyncPhishNetTour::dispatch();

        $this->info("Tour sync loop started; checking every {$interval}s.");
        $this->line('A queue worker must be running for the loop to advance: php artisan queue:work');

        return self::SUCCESS;
    }
}
