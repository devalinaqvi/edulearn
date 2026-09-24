<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 16)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['course_id', 'status']);
        });
        DB::table('materials')->update(['version' => 1, 'status' => 'active']);

        Schema::create('material_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->string('path');
            $table->string('original_name');
            $table->string('format', 16);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->foreignId('uploader_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignId('replaced_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('replaced_at');
            $table->string('reason', 1000)->nullable();
            $table->unique(['material_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_revisions');
        Schema::table('materials', function (Blueprint $table) {
            $table->dropIndex(['course_id', 'status']);
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['version', 'status', 'archived_at']);
        });
    }
};
