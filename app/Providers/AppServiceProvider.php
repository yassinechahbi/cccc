<?php

namespace App\Providers;

use App\Models\Circuit;
use App\Models\Member;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('admin', fn (User $user) => $user->isAdmin());
        Gate::define('manage-circuits', fn (User $user) => $user->canManageCircuits());
        Gate::define('view-circuit', fn (User $user, Circuit $circuit) => $circuit->isVisibleTo($user));
        Gate::define('edit-circuit', fn (User $user, Circuit $circuit) => $circuit->isEditableBy($user));
        // Own record, or any record for an admin.
        Gate::define('edit-member', fn (User $user, Member $member) => $user->isAdmin() || $user->member_id === $member->id);

        Event::listen(Verified::class, fn (Verified $event) => $event->user->linkMemberByEmail());

        // Codes are typed by hand, so failed attempts are throttled per visitor.
        RateLimiter::for('track', fn (Request $request) => Limit::perMinute(15)->by($request->ip()));
    }
}
