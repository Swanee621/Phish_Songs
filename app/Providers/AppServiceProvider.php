<?php

namespace App\Providers;

use App\Services\PhishNet\PhishNetClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\Records\CacheEvent;
use Laravel\Nightwatch\Records\Query;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PhishNetClient::class, fn () => new PhishNetClient(
            config('services.phishnet.key'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        if (config('nightwatch.enabled')) {
            Nightwatch::rejectQueries(function (Query $query) {
                return str_contains($query->sql, 'pulse_') || str_contains($query->sql, 'telescope_');
            });
            Nightwatch::rejectCacheEvents(function (CacheEvent $event) {
                return str_contains($event->key, 'pulse') || str_contains($event->key, 'telescope');
            });
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
