<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_write_locks', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
        });
        DB::table('academic_write_locks')->insert(['id' => 1]);
        foreach (['academic_terms', 'catalog_courses'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('university_id')->nullable()->constrained()->restrictOnDelete();
            });
            if (DB::table('universities')->count() === 1) {
                DB::table($name)->update(['university_id' => DB::table('universities')->value('id')]);
            }
        }
        Schema::table('curricula', function (Blueprint $table): void {
            $table->string('approval_status', 20)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
        });
        DB::table('curricula')->update(['approval_status' => 'approved']);
        Schema::create('academic_setup_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('entity');
            $table->unsignedBigInteger('record_id');
            $table->string('operation', 30);
            $table->json('before')->nullable();
            $table->json('after');
            $table->text('reason');
            $table->timestamp('created_at');
            $table->index(['university_id', 'id']);
        });
        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->timestamp('published_at')->nullable();
            $table->json('pending_change')->nullable();
        });
        DB::table('class_sessions')->update(['published_at' => DB::raw('created_at')]);
        Schema::create('timetable_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_term_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('sessions');
            $table->text('reason');
            $table->timestamp('created_at');
            $table->unique(['program_id', 'academic_term_id', 'version'], 'timetable_release_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_releases');
        Schema::table('class_sessions', fn (Blueprint $table) => $table->dropColumn(['published_at', 'pending_change']));
        Schema::dropIfExists('academic_setup_changes');
        Schema::table('curricula', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approval_status', 'approved_at']);
        });
        foreach (['academic_terms', 'catalog_courses'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('university_id'));
        }
        Schema::dropIfExists('academic_write_locks');
    }
};
