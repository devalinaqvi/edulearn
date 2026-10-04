<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a quiz exist without an availability window.
     *
     * "No deadline" is represented by the absence of a time rather than by a sentinel date far in
     * the future: a fabricated date would be indistinguishable from a real one, and would start
     * behaving like a deadline the moment it arrived.
     *
     * A null opens_at means the quiz is available as soon as it is published; a null closes_at
     * means it never closes. Each attempt still carries its own deadline_at, so an individual
     * sitting remains time-limited by the attempt duration.
     */
    public function up(): void
    {
        Schema::table('quizzes', function (Blueprint $table) {
            $table->dateTime('opens_at')->nullable()->change();
            $table->dateTime('closes_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Quizzes created without a window have no date to restore; restore a matching backup instead.');
    }
};
