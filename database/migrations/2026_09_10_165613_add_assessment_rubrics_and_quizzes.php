<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->json('rubric')->nullable();
            $table->unsignedInteger('rubric_version')->default(0);
        });
        Schema::table('submissions', function (Blueprint $table) {
            $table->json('rubric_scores')->nullable();
            $table->unsignedInteger('grade_version')->default(0);
        });
        Schema::create('assessment_grade_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained();
            $table->foreignId('actor_id')->constrained('users');
            $table->json('before');
            $table->json('after');
            $table->text('reason');
            $table->timestamp('created_at');
        });
        Schema::create('assignment_extensions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('actor_id')->constrained('users');
            $table->dateTime('due_at');
            $table->text('reason');
            $table->timestamp('created_at');
            $table->index(['assignment_id', 'user_id']);
        });
        Schema::create('quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained();
            $table->string('title', 160);
            $table->text('instructions');
            $table->dateTime('opens_at');
            $table->dateTime('closes_at');
            $table->unsignedInteger('duration_minutes');
            $table->json('questions');
            $table->string('status')->default('draft');
            $table->unsignedInteger('version')->default(0);
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quiz_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->dateTime('started_at');
            $table->dateTime('deadline_at');
            $table->json('answers');
            $table->unsignedInteger('version')->default(0);
            $table->unsignedInteger('score')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->unique(['quiz_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('quizzes');
        Schema::dropIfExists('assignment_extensions');
        Schema::dropIfExists('assessment_grade_changes');
        Schema::table('submissions', fn (Blueprint $table) => $table->dropColumn(['rubric_scores', 'grade_version']));
        Schema::table('assignments', fn (Blueprint $table) => $table->dropColumn(['rubric', 'rubric_version']));
    }
};
