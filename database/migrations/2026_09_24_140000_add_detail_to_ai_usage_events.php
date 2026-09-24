<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_usage_events', function (Blueprint $table) {
            $table->string('detail', 200)->nullable()->after('status');
        });

        Schema::table('ai_configurations', function (Blueprint $table) {
            // Defaults to the stricter existing behaviour: opting out is a deliberate admin act.
            $table->boolean('require_zero_retention')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('ai_usage_events', fn (Blueprint $table) => $table->dropColumn('detail'));
        Schema::table('ai_configurations', fn (Blueprint $table) => $table->dropColumn('require_zero_retention'));
    }
};
