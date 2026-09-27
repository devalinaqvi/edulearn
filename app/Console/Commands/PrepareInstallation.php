<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PrepareInstallation extends Command
{
    protected $signature = 'lms:prepare';

    protected $description = 'Check MySQL configuration and generate an application key only for a new installation';

    public function handle(): int
    {
        if (app()->configurationIsCached()) {
            $this->error('Clear cached configuration with php artisan config:clear before preparing installation.');

            return self::FAILURE;
        }
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->error('EduLearn requires MySQL. Configure DB_CONNECTION=mysql and your database credentials in .env.');

            return self::FAILURE;
        }
        if (filled(config('app.key'))) {
            $this->info('Existing APP_KEY preserved.');

            return self::SUCCESS;
        }
        if (Schema::hasTable('users') && DB::table('users')->exists()) {
            $this->error('This database already contains users. Restore its original APP_KEY; do not generate a replacement during setup.');

            return self::FAILURE;
        }

        return $this->call('key:generate', ['--no-interaction' => true]);
    }
}
