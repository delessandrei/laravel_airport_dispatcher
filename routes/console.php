<?php

/**
 *
 * @author        Delescu Andrei Vlad <andrei.delescu@gmail.com>
 * @copyright     Copyright(c) 2026 Andrei-Vlad Delescu. All rights reserved.
 * @link          https://www.deless.ro/
 */

use Illuminate\Console\Scheduling\Event;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$every = function (Event $event, string $frequency): Event {
    return str_starts_with($frequency, 'every') && method_exists($event, $frequency)
        ? $event->{$frequency}()
        : $event->cron($frequency);
};

// Collection. Costs OpenSky credits, so it runs rarely and never on top of itself.
$every(Schedule::command('flights:fetch'), (string) config('dispatch.cron'))
    ->withoutOverlapping()
    ->runInBackground();


$every(Schedule::command('gates:allocate'), (string) config('dispatch.allocate_cron'))
    ->withoutOverlapping();

$every(Schedule::command('gates:validate'), (string) config('dispatch.validate_cron'))
    ->withoutOverlapping();

$every(Schedule::command('gates:report'), (string) config('dispatch.report_cron'))
    ->withoutOverlapping()
    ->runInBackground();
