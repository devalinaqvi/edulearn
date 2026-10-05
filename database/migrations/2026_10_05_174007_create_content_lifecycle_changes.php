<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who archived, restored or destroyed course content, when, and why.
     *
     * Institutions are expected to be able to answer that question long after the fact, for grade
     * appeals and accreditation evidence, so the record has to outlive its subject.
     *
     * That shapes the schema. `subject_id` is deliberately NOT a foreign key: the row it names
     * may have been permanently deleted, which is precisely the event worth recording.
     * `subject_title` is copied for the same reason, so the entry stays legible once the original
     * is gone. `course_id` nulls rather than restricts on delete, because a restricting reference
     * here would make a course undeletable the moment it was first archived -- the audit trail
     * would block the very action it exists to describe.
     */
    public function up(): void
    {
        Schema::create('content_lifecycle_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_title');
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('course_title')->nullable();
            $table->string('action', 32);
            $table->text('reason')->nullable();
            $table->json('preserved')->nullable();
            $table->timestamp('created_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_lifecycle_changes');
    }
};
