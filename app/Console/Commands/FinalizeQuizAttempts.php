<?php

namespace App\Console\Commands;

use App\Actions\QuizWorkflow;
use Illuminate\Console\Command;

class FinalizeQuizAttempts extends Command
{
    protected $signature = 'quizzes:finalize';

    protected $description = 'Score saved answers for up to 500 expired quiz attempts';

    public function handle(QuizWorkflow $workflow): int
    {
        $this->info($workflow->finalizeExpired().' expired attempts finalized.');

        return self::SUCCESS;
    }
}
