<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_notes', function (Blueprint $table) {
            $table->foreignId('video_lecture_id')->nullable()->after('material_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('study_notes', fn (Blueprint $table) => $table->dropConstrainedForeignId('video_lecture_id'));
    }
};
