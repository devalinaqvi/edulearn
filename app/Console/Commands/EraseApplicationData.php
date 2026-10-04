<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Erases application DATA while leaving the schema and migration history intact.
 *
 * This is deliberately not `migrate:fresh`. Dropping and recreating tables loses the migration
 * record's meaning on an installation that has already been upgraded, and the project's history
 * records one occasion where a misdirected refresh destroyed the application database.
 *
 * Deletion order is derived from the live foreign-key graph rather than hard-coded, so it stays
 * correct as migrations are added. Constraints stay enabled throughout: if the order were wrong
 * the delete would fail loudly rather than leaving orphans behind.
 */
class EraseApplicationData extends Command
{
    use ConfirmableTrait;

    protected $signature = 'lms:erase {--force : Skip confirmation, required in production}
        {--files : Also delete private uploads (materials, video lectures, posters, captions)}';

    protected $description = 'Delete all application data from the configured database, keeping the schema';

    /** Schema state, never data: erasing this would strand the installation mid-upgrade. */
    private const NEVER_ERASE = ['migrations'];

    public function handle(): int
    {
        if (app()->configurationIsCached()) {
            $this->components->error('Cached configuration is in effect, so the target database shown below may not be the one that is written to. Run `php artisan optimize:clear` first.');

            return self::FAILURE;
        }

        $connection = DB::connection();
        $database = $connection->getDatabaseName();

        $this->components->warn('This permanently deletes ALL application data.');
        $this->components->twoColumnDetail('Environment', app()->environment());
        $this->components->twoColumnDetail('Connection', $connection->getName());
        $this->components->twoColumnDetail('Host', (string) config('database.connections.'.$connection->getName().'.host'));
        $this->components->twoColumnDetail('<fg=red;options=bold>Database</>', '<fg=red;options=bold>'.$database.'</>');

        // ConfirmableTrait blocks production unless --force is given, and prompts otherwise.
        if (! $this->confirmToProceed('Application data in ['.$database.'] will be deleted')) {
            return self::FAILURE;
        }

        $order = $this->deletionOrder($database);
        $erased = [];

        DB::transaction(function () use ($order, &$erased) {
            foreach ($order as $table) {
                $deleted = DB::table($table)->delete();
                if ($deleted > 0) {
                    $erased[$table] = $deleted;
                }
            }
            $this->restoreSystemRecords();
        });

        foreach ($erased as $table => $count) {
            $this->components->twoColumnDetail($table, number_format($count).' rows');
        }
        $this->components->info(count($erased) === 0 ? 'Database was already empty.' : 'Erased '.number_format(array_sum($erased)).' rows from '.count($erased).' tables.');

        if ($this->option('files')) {
            $this->erasePrivateUploads();
        } else {
            $this->components->warn('Private uploads were kept. Re-run with --files to delete them too.');
        }

        $this->components->info('Schema and migration history are unchanged. Seed login accounts with: php artisan db:seed --class=MinimalLoginSeeder');

        return self::SUCCESS;
    }

    /**
     * Order tables so that every table is deleted before the tables it references.
     *
     * @return list<string>
     */
    private function deletionOrder(string $database): array
    {
        $tables = array_values(array_diff(
            array_map(fn ($row) => array_values((array) $row)[0], DB::select('SHOW TABLES')),
            self::NEVER_ERASE,
        ));

        $references = array_fill_keys($tables, []);
        $dependents = array_fill_keys($tables, 0);

        foreach ($this->foreignKeys($database) as $key) {
            // A self-referencing column orders nothing: one DELETE clears the whole table.
            if ($key->child === $key->parent || ! isset($references[$key->child], $references[$key->parent])) {
                continue;
            }
            if (! in_array($key->parent, $references[$key->child], true)) {
                $references[$key->child][] = $key->parent;
                $dependents[$key->parent]++;
            }
        }

        // Kahn's algorithm: a table is ready once nothing that references it is still queued.
        $ready = array_values(array_filter($tables, fn ($table) => $dependents[$table] === 0));
        $order = [];
        while ($ready) {
            $table = array_shift($ready);
            $order[] = $table;
            foreach ($references[$table] as $parent) {
                if (--$dependents[$parent] === 0) {
                    $ready[] = $parent;
                }
            }
        }

        if (count($order) !== count($tables)) {
            // A cycle would mean no safe order exists; say so rather than disabling constraints.
            $remaining = implode(', ', array_diff($tables, $order));
            throw new \RuntimeException('Circular foreign keys prevent a safe deletion order. Resolve them for: '.$remaining);
        }

        return $order;
    }

    /** @return list<object{child: string, parent: string}> */
    private function foreignKeys(string $database): array
    {
        return DB::select(
            'SELECT TABLE_NAME AS child, REFERENCED_TABLE_NAME AS parent
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$database],
        );
    }

    /**
     * Rows the application needs in order to run at all, recreated by their own migrations.
     *
     * lms_write_locks row 1 is the coordination row every serialized mutation takes. Without it
     * App\Actions\WriteLock refuses to proceed, so an erased installation would reject every
     * write until it was restored.
     */
    private function restoreSystemRecords(): void
    {
        DB::table('lms_write_locks')->updateOrInsert(['id' => 1]);
        DB::table('settings')->updateOrInsert(['key' => 'site_name'], ['value' => 'EduLearn']);
    }

    private function erasePrivateUploads(): void
    {
        $disk = Storage::disk('local');
        $removed = 0;
        foreach (['materials', 'submissions', 'video-lectures'] as $directory) {
            if ($disk->exists($directory)) {
                $removed += count($disk->allFiles($directory));
                $disk->deleteDirectory($directory);
            }
        }
        $this->components->twoColumnDetail('private uploads', number_format($removed).' files deleted');
    }
}
