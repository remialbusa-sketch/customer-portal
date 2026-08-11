<?php

namespace App\Providers;

use App\Events\TicketCreated;
use App\Listeners\SendTspAlertForNewTicket;
use App\Services\MondayClient;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Single MondayClient instance per request, built from config.
        $this->app->singleton(MondayClient::class, fn () => MondayClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Explicitly register the TSP alert listener. Laravel 11 would
        // auto-discover this from the typed `handle(TicketCreated $event)`
        // signature in App\Listeners, but spelling it out here makes
        // the wiring obvious to anyone reading the provider.
        Event::listen(TicketCreated::class, SendTspAlertForNewTicket::class);
    }
}
