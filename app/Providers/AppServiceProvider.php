<?php

namespace App\Providers;

use App\Models\Course;
use App\Policies\CoursePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Paginator::defaultView('pagination.default');

        // Times are stored in UTC and read in the configured display timezone. These keep the
        // conversion in one place instead of scattering format() calls through the views.
        Blade::directive('showtime', fn ($expression) => "<?php echo e(\App\Services\DisplayTime::format({$expression})); ?>");
        Blade::directive('showdate', fn ($expression) => "<?php echo e(\App\Services\DisplayTime::formatDate({$expression})); ?>");
        Blade::directive('inputtime', fn ($expression) => "<?php echo e(\App\Services\DisplayTime::forInput({$expression})); ?>");
        Blade::directive('tz', fn () => '<?php echo e(\App\Services\DisplayTime::abbreviation()); ?>');
        Gate::policy(Course::class, CoursePolicy::class);
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('notes', fn (Request $r) => Limit::perMinute(5)->by($r->user()->id));
    }
}
