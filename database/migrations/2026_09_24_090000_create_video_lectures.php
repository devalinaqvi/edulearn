<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_lectures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(1);
            $table->string('status', 16)->default('draft');
            $table->string('processing_status', 16)->default('pending');
            $table->string('processing_error', 500)->nullable();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->string('container', 16);
            $table->string('video_codec', 16)->nullable();
            $table->string('audio_codec', 16)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('poster_path')->nullable();
            $table->foreignId('uploader_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['course_id', 'status', 'position']);
        });

        Schema::create('video_lecture_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_lecture_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->foreignId('uploader_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignId('replaced_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('replaced_at');
            $table->string('reason', 1000)->nullable();
            $table->unique(['video_lecture_id', 'version']);
        });

        /** Captions are stored as validated WebVTT; the transcript is the plain-text, versioned AI note source. */
        Schema::create('video_lecture_tracks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_lecture_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('language', 16)->default('en');
            $table->string('label', 100);
            $table->string('path')->nullable();
            $table->longText('transcript_text')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('uploader_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['video_lecture_id', 'kind', 'language']);
        });

        Schema::create('video_lecture_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_lecture_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position_seconds')->default(0);
            $table->unsignedInteger('watched_seconds')->default(0);
            $table->unsignedInteger('furthest_seconds')->default(0);
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_reported_at')->nullable();
            $table->timestamps();
            $table->unique(['video_lecture_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_lecture_progress');
        Schema::dropIfExists('video_lecture_tracks');
        Schema::dropIfExists('video_lecture_revisions');
        Schema::dropIfExists('video_lectures');
    }
};
