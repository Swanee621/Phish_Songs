<?php

namespace App\Console\Commands;

use App\Services\PhishNet\PhishNetSynchronizer;
use Illuminate\Console\Command;

class PhishNetSyncCommand extends Command
{
    protected $signature = 'phish:sync
                            {--year= : Show year to sync (defaults to the tour currently in progress)}
                            {--catalogs : Also sync the song and venue catalogs}';

    protected $description = 'Run a single phish.net sync for one show year, importing only if the upstream data changed';

    public function handle(PhishNetSynchronizer $synchronizer): int
    {
        $year = (int) ($this->option('year') ?: $synchronizer->currentShowYear());

        $changed = $synchronizer->syncYear($year);

        $this->line($changed
            ? "<info>{$year}: changes imported.</info>"
            : "<comment>{$year}: no changes upstream.</comment>");

        if ($this->option('catalogs')) {
            $this->line($synchronizer->syncVenues() ? '<info>Venues updated.</info>' : '<comment>Venues unchanged.</comment>');
            $this->line($synchronizer->syncSongs() ? '<info>Songs updated.</info>' : '<comment>Songs unchanged.</comment>');
        }

        if ($tour = $synchronizer->currentTour()) {
            $this->line("Current tour: {$tour['tourname']} ({$tour['year']})");
        }

        return self::SUCCESS;
    }
}
