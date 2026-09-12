<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campus_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->unsignedSmallInteger('capacity');
            $table->text('accessibility_notes');
            $table->text('equipment');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['campus_id', 'code'], 'room_campus_code_unique');
        });
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['room_id', 'starts_at'], 'session_room_start_index');
        });
    }

    public function down(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->dropForeign(['room_id']);
            $table->dropIndex('session_room_start_index');
            $table->dropColumn('room_id');
        });
        Schema::dropIfExists('rooms');
    }
};
