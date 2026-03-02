<?php

use App\Jobs\AutoCompleteOrders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('orders:advance-status')->everyMinute();

Schedule::job(new AutoCompleteOrders)->hourly();

Schedule::command('model:prune')->daily();
