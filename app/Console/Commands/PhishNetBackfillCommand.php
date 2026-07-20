<?php

namespace App\Console\Commands;

use App\Services\PhishNet\PhishNetSynchronizer;
use Illuminate\Console\Command;

class PhishNetBackfillCommand extends Command
{
    protected $signature = 'phish:backfill
                            {--from= : First show year to import (defaults to phishnet.sync.first_year)}
                            {--to= : Last show year to import (defaults to the current year)}';

    protected $description = 'Import the full phish.net show, setlist, song and venue history into the local database';

    public function handle(PhishNetSynchronizer $synchronizer): int
    {
        $from = (int) ($this->option('from') ?: config('phishnet.sync.first_year'));
        $to = (int) ($this->option('to') ?: now()->year);

        if ($from > $to) {
            $this->error("Invalid range: {$from} is after {$to}.");

            return self::FAILURE;
        }

        $this->info('Importing catalogs...');
        $synchronizer->syncVenues();
        $synchronizer->syncSongs();

        $delay = (int) config('phishnet.sync.backfill_delay');
        $changed = 0;

        $this->withProgressBar(range($from, $to), function (int $year) use ($synchronizer, $delay, &$changed) {
            if ($synchronizer->syncYear($year)) {
                $changed++;
            }

            if ($delay > 0) {
                sleep($delay);
            }
        });

        $this->newLine(2);
        $this->info("Backfill complete. {$changed} of ".($to - $from + 1).' years imported or updated.');

        return self::SUCCESS;
    }
}
