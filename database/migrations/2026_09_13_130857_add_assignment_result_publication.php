<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->json('published_result')->nullable();
            $table->unsignedInteger('published_grade_version')->nullable();
            $table->timestamp('result_published_at')->nullable();
        });
        Schema::create('result_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('grade_version');
            $table->json('result');
            $table->string('reason', 1000);
            $table->timestamp('created_at');
            $table->unique(['submission_id', 'grade_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_publications');
        Schema::table('submissions', fn (Blueprint $table) => $table->dropColumn(['published_result', 'published_grade_version', 'result_published_at']));
    }
};
