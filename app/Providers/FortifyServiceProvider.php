<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Fortify;

/**
 * Fortify stays installed but dormant: the site has no accounts, so none of its
 * routes are registered and none of its views exist.
 *
 * Re-enabling login means dropping the {@see Fortify::ignoreRoutes()} call and
 * pointing Fortify's view callbacks at new Inertia pages; the actions, the User
 * model and the auth tables are all still here.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }
}
