<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records how a lesson body should be interpreted.
     *
     * Existing lessons were authored as plain text and must keep rendering escaped, with their
     * line breaks intact. Converting them to HTML in a migration would risk reinterpreting a
     * character a learner wrote years ago, so they stay 'text' and only content saved through the
     * editor is stored as sanitized 'html'.
     */
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('body_format', 8)->default('text')->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', fn (Blueprint $table) => $table->dropColumn('body_format'));
    }
};
