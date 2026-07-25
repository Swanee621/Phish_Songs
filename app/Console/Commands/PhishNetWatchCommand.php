<?php

namespace App\Console\Commands;

use App\Jobs\SyncPhishNetTour;
use Illuminate\Console\Command;

class PhishNetWatchCommand extends Command
{
    protected $signature = 'phish:watch';

    protected $description = 'Dispatch an immediate one-off tour sync (the scheduler runs the recurring loop)';

    public function handle(): int
    {
        SyncPhishNetTour::dispatch(continuous: false);

        $this->info('Tour sync dispatched.');
        $this->line('The recurring loop is driven by the scheduler (phish:tick, every minute), so it re-arms itself; this just triggers a sync right now.');
        $this->line('A queue worker must be running to process it: php artisan queue:work');

        return self::SUCCESS;
    }
}
