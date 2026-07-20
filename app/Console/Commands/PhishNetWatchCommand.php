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
        $idle = (int) config('phishnet.sync.interval');
        $active = (int) config('phishnet.sync.active_interval');

        SyncPhishNetTour::dispatch();

        $this->info("Tour sync loop started; checking every {$active}s during a show, {$idle}s otherwise.");
        $this->line('A queue worker must be running for the loop to advance: php artisan queue:work');

        return self::SUCCESS;
    }
}
