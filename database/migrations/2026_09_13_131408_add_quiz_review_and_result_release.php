<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->decimal('score', 8, 2)->nullable()->change();
            $table->json('manual_scores')->nullable();
            $table->text('review_feedback')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->json('published_result')->nullable();
            $table->unsignedInteger('published_version')->nullable();
            $table->timestamp('result_published_at')->nullable();
        });
        Schema::create('quiz_assessment_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('event');
            $table->unsignedInteger('version');
            $table->json('result');
            $table->string('reason', 1000);
            $table->timestamp('created_at');
            $table->unique(['quiz_attempt_id', 'event', 'version']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore a matching backup to retain reviewed quiz results.');
    }
};
