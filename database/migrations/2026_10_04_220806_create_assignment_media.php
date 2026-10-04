<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference images and videos an instructor attaches to an assignment brief.
     *
     * Files live on the private disk under generated names and are only ever served through an
     * authorized route, exactly like study materials and lecture recordings. `alt_text` is not
     * optional for an image: a reference picture a learner cannot perceive is a missing part of
     * the brief, not a decoration.
     */
    public function up(): void
    {
        Schema::create('assignment_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('alt_text', 500)->nullable();
            $table->unsignedInteger('position')->default(1);
            $table->foreignId('uploader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['assignment_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_media');
    }
};
