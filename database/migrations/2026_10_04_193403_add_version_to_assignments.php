<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Assignments were the only authored entity without an optimistic-concurrency counter,
     * because they had no edit path at all. Co-instructors can now edit the same assignment,
     * so a stale form must be rejected the way it already is for courses, materials, lectures
     * and quizzes.
     */
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(0)->after('rubric_version');
        });
    }

    public function down(): void
    {
        Schema::table('assignments', fn (Blueprint $table) => $table->dropColumn('version'));
    }
};
