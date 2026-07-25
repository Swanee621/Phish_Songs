<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Heartbeat for the phish.net tour sync. The command itself decides whether the
 * configured interval has elapsed before dispatching any work, so running it
 * every minute is cheap and keeps the loop self-healing: if it ever stops, the
 * next tick starts it again with no manual re-seeding.
 */
Schedule::command('phish:tick')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
