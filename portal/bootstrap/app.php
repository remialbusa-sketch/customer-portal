<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Offline TSR drainer (SyncPendingTsrReports). This is the
        // server-side safety net for the browser queue: it catches
        // rows that reached the DB but never reached Monday because
        // no browser was open to fire the `online` drain. Runs every
        // 5 minutes. Requires a cPanel cron entry running
        // `php artisan schedule:run` every minute — see DEPLOY.md.
        $schedule->call(fn () => app(\App\Actions\SyncPendingTsrReports::class)->execute())
            ->everyFiveMinutes()
            ->name('tsr-drainer')
            ->withoutOverlapping();
    })
    ->withBroadcasting(__DIR__.'/../routes/channels.php')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);

        // ETag/304 short-circuit for revisits. Skipped on
        // POST + the explicit deltas listed in SetResponseETag
        // (chat poll, TSR status, etc.).
        $middleware->append(\App\Http\Middleware\SetResponseETag::class);

        // Trust the ngrok reverse proxy (or any other reverse proxy in
        // dev) so that $request->isSecure(), $request->getHost() and
        // the signed-URL machinery pick up the public scheme/host
        // forwarded by ngrok in the X-Forwarded-* headers.
        $middleware->trustProxies(
            at: '*',
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT
                | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
