<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('class_sessions')) {
            Schema::create('class_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('section_id')->constrained()->restrictOnDelete();
                $table->foreignId('instructor_id')->constrained('users')->restrictOnDelete();
                $table->string('title');
                $table->string('kind');
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->timestamps();
                $table->index(['section_id', 'starts_at']);
                $table->index(['instructor_id', 'starts_at']);
            });
        }
        if (! Schema::hasTable('attendance_records')) {
            Schema::create('attendance_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('class_session_id')->constrained()->restrictOnDelete();
                $table->foreignId('registration_id')->constrained()->restrictOnDelete();
                $table->string('status');
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();
                $table->unique(['class_session_id', 'registration_id']);
            });
        }
        if (! Schema::hasTable('attendance_changes')) {
            Schema::create('attendance_changes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('attendance_record_id')->constrained()->restrictOnDelete();
                $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $table->string('from_status')->nullable();
                $table->string('to_status');
                $table->text('reason');
                $table->timestamp('created_at');
            });
        }
        if (! Schema::hasTable('attendance_corrections')) {
            Schema::create('attendance_corrections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('attendance_record_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('record_version');
                $table->string('requested_status');
                $table->text('reason');
                $table->string('status')->default('pending');
                $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->text('decision_reason')->nullable();
                $table->timestamps();

            });
        }
        if (! Schema::hasIndex('attendance_corrections', 'attendance_correction_version_unique')) {
            Schema::table('attendance_corrections', function (Blueprint $table) {
                $table->unique(['attendance_record_id', 'record_version'], 'attendance_correction_version_unique');
            });
        }
    }

    public function down(): void
    {
        foreach (['attendance_corrections', 'attendance_changes', 'attendance_records', 'class_sessions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
