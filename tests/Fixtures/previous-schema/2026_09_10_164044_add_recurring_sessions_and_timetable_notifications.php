<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->uuid('series_key')->nullable()->index();
        });
        Schema::create('timetable_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('timetable_release_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('message');
            $table->timestamp('created_at');
            $table->timestamp('read_at')->nullable();
            $table->unique(['timetable_release_id', 'user_id'], 'timetable_recipient_unique');
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_notifications');
        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->dropIndex(['series_key']);
            $table->dropColumn('series_key');
        });
    }
};
