<?php

namespace App\Console\Commands;

use App\Services\PhishNet\PhishNetSynchronizer;
use Illuminate\Console\Command;

class PhishNetWatchCommand extends Command
{
    protected $signature = 'phish:watch';

    protected $description = 'Run an immediate one-off sync pass (the scheduler runs the recurring loop)';

    public function handle(PhishNetSynchronizer $synchronizer): int
    {
        $synchronizer->syncPass();

        $this->info('Sync pass complete.');
        $this->line('The recurring loop is driven by the scheduler (phish:tick, every minute), so keep `php artisan schedule:work` running; this just synced right now.');

        return self::SUCCESS;
    }
}
