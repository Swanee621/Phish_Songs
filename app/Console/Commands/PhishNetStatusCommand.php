<?php

namespace App\Console\Commands;

use App\Models\SetlistEntry;
use App\Models\Show;
use App\Services\PhishNet\PhishNetRepository;
use App\Services\PhishNet\PhishNetSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Prints everything needed to tell whether the sync loop is alive and, when it
 * is not, which link in the chain is broken — without touching the phish.net
 * API.
 *
 * The loop is quiet by design most of the day, so "no recent import" alone
 * proves nothing. What distinguishes a healthy idle loop from a dead one is the
 * time since the last *pass*: a running loop restamps that clock every tick
 * interval whether or not anything changed, so a gap far larger than the
 * applicable interval means the scheduler is not firing this command's sibling.
 */
class PhishNetStatusCommand extends Command
{
    protected $signature = 'phish:status';

    protected $description = 'Report sync loop health: last pass, pacing, and whether the scheduler appears to be running';

    public function handle(PhishNetRepository $repository, PhishNetSynchronizer $synchronizer): int
    {
        $live = $repository->liveState();

        $inShowWindow = $live['inShowWindow'];
        $interval = $inShowWindow
            ? (int) config('phishnet.sync.active_interval')
            : (int) config('phishnet.sync.interval');

        $lastRunAt = $live['updatedAt'] !== null ? Carbon::parse($live['updatedAt']) : null;
        $secondsSince = $lastRunAt?->diffInSeconds(now(), absolute: true);

        $this->line('');
        $this->line('<options=bold>Sync loop</>');

        $this->line(sprintf(
            '  Last pass:        %s',
            $lastRunAt === null
                ? '<fg=red>never (the loop has not run at all)</>'
                : sprintf('%s (%ds ago)', $lastRunAt->toDateTimeString(), $secondsSince),
        ));

        $this->line(sprintf('  Pacing now:       every %ds (%s)', $interval, $inShowWindow ? 'show underway' : 'idle'));

        /*
         * Two full intervals plus a minute of slack: one interval is the normal
         * quiet gap between passes, so only a gap well past that indicates the
         * every-minute tick is not firing at all.
         */
        $healthy = $lastRunAt !== null && $secondsSince <= ($interval * 2) + 60;

        $this->line(sprintf(
            '  Scheduler:        %s',
            $healthy
                ? '<fg=green>appears to be running</>'
                : '<fg=red>NOT running (or phish:tick is failing) — see below</>',
        ));

        $this->line('');
        $this->line('<options=bold>Live state</>');
        $this->line(sprintf('  Show underway:    %s', $inShowWindow ? '<fg=green>yes</>' : 'no'));
        $this->line(sprintf('  Showdate:         %s', $live['showdate'] ?? '—'));
        $this->line(sprintf('  On stage:         %s', $live['currentSongs'] ?? '—'));

        $this->line('');
        $this->line('<options=bold>Intervals in effect</>');
        $this->line(sprintf('  Server sync, show night:  %ds  <fg=gray>(PHISHNET_SYNC_ACTIVE_INTERVAL)</>', (int) config('phishnet.sync.active_interval')));
        $this->line(sprintf('  Server sync, idle:        %ds  <fg=gray>(PHISHNET_SYNC_INTERVAL)</>', (int) config('phishnet.sync.interval')));
        $this->line(sprintf('  Browser poll, show night: %ds  <fg=gray>(CLIENT_SYNC_ACTIVE_INTERVAL)</>', (int) config('phishnet.client.active_interval')));
        $this->line(sprintf('  Browser poll, idle:       %ds  <fg=gray>(CLIENT_SYNC_INTERVAL)</>', (int) config('phishnet.client.interval')));

        $latestShowdate = (string) (Show::query()->where('artistid', 1)->max('showdate') ?? '');

        if ($latestShowdate !== '') {
            $entries = SetlistEntry::query()
                ->whereIn('showid', Show::query()->where('showdate', $latestShowdate)->pluck('showid'))
                ->where('artistid', 1)
                ->count();

            $this->line('');
            $this->line('<options=bold>Most recent show stored</>');
            $this->line(sprintf('  %s — %d songs', $latestShowdate, $entries));
        }

        if (! $healthy) {
            $this->line('');
            $this->line('<fg=yellow>The loop is not being driven. Check, in order:</>');
            $this->line('  1. Is the scheduler enabled for this environment?');
            $this->line('     It must run `php artisan schedule:run` every minute.');
            $this->line('  2. Does `php artisan schedule:list` show phish:tick?');
            $this->line('  3. Run `php artisan phish:tick -v` by hand — if that syncs,');
            $this->line('     the command is fine and only the scheduler is missing.');
        }

        $this->line('');

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
