<?php

namespace App\Console\Commands;

use App\Actions\ContentLifecycle;
use Illuminate\Console\Command;

/**
 * Empties Trash of archived content that nothing refers to.
 *
 * Deliberately narrow. It never relaxes the deletion rules: anything carrying learner history is
 * retained however long it has sat there, and the retention window only decides when an already
 * safe-to-delete record stops being kept for convenience.
 */
class PurgeArchivedContent extends Command
{
    protected $signature = 'lms:purge-trash
        {--days= : Days an item must have been archived before it is eligible}
        {--dry-run : Report what would be removed without removing anything}';

    protected $description = 'Permanently remove long-archived content that nothing depends on';

    public function handle(ContentLifecycle $lifecycle): int
    {
        $days = (int) ($this->option('days') ?: config('lms.trash_retention_days'));
        if ($days < 1) {
            $this->components->error('The retention window must be at least one day.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $lifecycle->purgeArchived($days, null, $dryRun);

        $this->components->twoColumnDetail('Retention window', $days.' days');
        $this->components->twoColumnDetail($dryRun ? 'Would delete' : 'Deleted', (string) $result['deleted']);
        $this->components->twoColumnDetail('Retained (records depend on them)', (string) $result['retained']);

        if ($dryRun) {
            $this->components->warn('Nothing was changed. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
