<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('tickets:archive-inactive')->everyMinute()->withoutOverlapping();

Schedule::command('helpdesk:automate')->everyMinute()->withoutOverlapping();

Schedule::command('mailboxes:sync')->everyMinute()->withoutOverlapping();

Schedule::command('tickets:prune-trash')->hourly()->withoutOverlapping();

Schedule::command('tickets:prune-spam')->hourly()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
