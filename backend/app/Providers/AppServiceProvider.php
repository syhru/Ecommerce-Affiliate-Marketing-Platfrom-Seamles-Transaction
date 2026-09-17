<?php

namespace App\Providers;

use App\Models\Order;
use App\Observers\OrderObserver;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function ($event) {
            if (request()->hasSession()) {
                request()->session()->put('credential_epoch', $event->user->credentialEpoch());
                request()->session()->put('credential_user_id', $event->user->id);
            }
        });
        Order::observe(OrderObserver::class);
        Paginator::useBootstrapFive();
    }
}
