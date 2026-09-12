<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->string('status', 20)->default('scheduled');
            $table->unsignedInteger('version')->default(1);
        });
        Schema::create('class_session_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 20);
            $table->text('reason');
            $table->json('before');
            $table->json('after');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_session_changes');
        Schema::table('class_sessions', function (Blueprint $table): void {
            $table->dropColumn(['status', 'version']);
        });
    }
};
