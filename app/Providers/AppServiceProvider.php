<?php

namespace App\Providers;

use App\Models\Course;
use App\Policies\CoursePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Paginator::defaultView('pagination.default');
        Gate::policy(Course::class, CoursePolicy::class);
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('notes', fn (Request $r) => Limit::perMinute(5)->by($r->user()->id));
    }
}
