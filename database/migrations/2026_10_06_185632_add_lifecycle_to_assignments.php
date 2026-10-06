<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gives assignments the draft/published/archived lifecycle lessons, materials, lectures and
     * quizzes already have, and the rich-text marker lesson bodies already carry.
     *
     * `status` defaults to 'published' rather than 'draft' on purpose. Every existing assignment
     * is already visible to learners and already being worked on; defaulting to draft would
     * withdraw live coursework from under them the moment this migration ran. New assignments
     * start as drafts, which is decided in the authoring path, not here.
     *
     * `instructions_format` defaults to 'text' for the same reason it does on lessons: existing
     * instructions were written as plain text, and reinterpreting them as markup could change
     * what a learner was told.
     */
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->string('status', 16)->default('published')->after('max_marks')->index();
            $table->string('instructions_format', 8)->default('text')->after('instructions');
            $table->timestamp('published_at')->nullable()->after('status');
            $table->foreignId('published_by')->nullable()->after('published_at')->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable()->after('published_by');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
        });

        // Existing rows are live coursework: record that they are published without inventing a
        // publication moment, since nobody performed one.
        Schema::table('assignments', fn (Blueprint $table) => $table->index(['course_id', 'status']));
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropIndex(['course_id', 'status']);
            $table->dropConstrainedForeignId('published_by');
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['status', 'instructions_format', 'published_at', 'archived_at']);
        });
    }
};
