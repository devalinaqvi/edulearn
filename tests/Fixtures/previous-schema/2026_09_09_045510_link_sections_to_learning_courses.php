<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('learning_course_id')->nullable()->unique()->constrained('courses')->restrictOnDelete();
        });
        Schema::create('section_instructors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['section_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_instructors');
        Schema::table('sections', function (Blueprint $table) {
            $table->dropForeign(['learning_course_id']);
            $table->dropUnique(['learning_course_id']);
            $table->dropColumn('learning_course_id');
        });
    }
};
