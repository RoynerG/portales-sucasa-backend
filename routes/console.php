<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ciencuadras:verify-pending --limit=25')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('ciencuadras:auto-sync')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('ciencuadras:reconcile-active')
    ->everyTenMinutes()
    ->withoutOverlapping();
