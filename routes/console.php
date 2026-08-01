<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Heartbeat for the phish.net tour sync. The command itself decides whether the
 * configured interval has elapsed before doing any work, so running it every
 * minute is cheap and keeps the loop self-healing: if it ever stops, the next
 * tick starts it again with no manual re-seeding. The sync runs inline in the
 * command — no queue worker involved — so the scheduler is the loop's only
 * dependency.
 *
 * The 10-minute overlap-lock expiry matters: the default is a day, so a run
 * killed without releasing its lock would otherwise silence the loop for 24
 * hours instead of ten minutes.
 */
Schedule::command('phish:tick')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();
