<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('student')->index();
        });
        Schema::create('courses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
            $t->string('title');
            $t->text('description');
            $t->string('status')->default('draft')->index();
            $t->timestamps();
        });
        Schema::create('lessons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->restrictOnDelete();
            $t->string('title');
            $t->text('body');
            $t->unsignedInteger('position');
            $t->timestamps();
            $t->index(['course_id', 'position']);
        });
        Schema::create('materials', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->restrictOnDelete();
            $t->foreignId('lesson_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('title');
            $t->string('path');
            $t->string('original_name');
            $t->string('format');
            $t->timestamps();
        });
        Schema::create('enrollments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->unique(['course_id', 'user_id']);
        });
        Schema::create('assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->restrictOnDelete();
            $t->string('title');
            $t->text('instructions');
            $t->dateTime('due_at');
            $t->unsignedInteger('max_marks');
            $t->timestamps();
        });
        Schema::create('submissions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('assignment_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->text('body')->nullable();
            $t->string('path')->nullable();
            $t->string('original_name')->nullable();
            $t->dateTime('submitted_at');
            $t->boolean('is_late')->default(false);
            $t->string('status')->default('submitted');
            $t->decimal('grade', 8, 2)->nullable();
            $t->text('feedback')->nullable();
            $t->foreignId('graded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('graded_at')->nullable();
            $t->timestamps();
            $t->unique(['assignment_id', 'user_id']);
        });
        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('course_id')->constrained()->restrictOnDelete();
            $t->string('title');
            $t->text('body');
            $t->timestamps();
        });
        Schema::create('lesson_completions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('lesson_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->unique(['lesson_id', 'user_id']);
        });
        Schema::create('study_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('course_id')->constrained()->restrictOnDelete();
            $t->foreignId('lesson_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('material_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('source_title');
            $t->string('title');
            $t->string('request_key');
            $t->string('status')->default('pending');
            $t->string('provider');
            $t->text('content')->nullable();
            $t->string('error')->nullable();
            $t->dateTime('generated_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'request_key']);
            $t->index(['user_id', 'created_at']);
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->text('value');
        });
        DB::table('settings')->insert([['key' => 'site_name', 'value' => 'Acumen LMS'], ['key' => 'registration_open', 'value' => '1']]);
    }

    public function down(): void
    {
        foreach (['settings', 'study_notes', 'lesson_completions', 'announcements', 'submissions', 'assignments', 'enrollments', 'materials', 'lessons', 'courses'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('role'));
    }
};
