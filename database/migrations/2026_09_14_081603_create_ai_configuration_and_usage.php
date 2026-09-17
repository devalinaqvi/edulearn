<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_configurations', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('provider')->default('mock');
            $table->string('model')->nullable();
            $table->text('api_key')->nullable();
            $table->unsignedInteger('daily_limit')->default(10);
            $table->unsignedInteger('max_input_chars')->default(12000);
            $table->unsignedInteger('max_output_tokens')->default(1200);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });
        Schema::create('ai_usage_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('model')->nullable();
            $table->string('status');
            $table->unsignedInteger('input_chars')->default(0);
            $table->timestamp('created_at')->index();
        });
        Schema::table('study_notes', function (Blueprint $table) {
            $table->string('model_name')->nullable();
            $table->unsignedInteger('ai_configuration_version')->default(0);
            $table->timestamp('edited_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('study_notes', fn (Blueprint $table) => $table->dropColumn(['model_name', 'ai_configuration_version', 'edited_at']));
        Schema::dropIfExists('ai_usage_events');
        Schema::dropIfExists('ai_configurations');
    }
};
